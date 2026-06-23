<?php
/**
 * WP REST API endpoints for the WPPilot chat interface — WP 7.0 Abilities edition.
 *
 * POST /wp-json/wppilot/v1/chat
 * POST /wp-json/wppilot/v1/session/clear
 * POST /wp-json/wppilot/v1/pending/clear
 *
 * @package WPPilot
 */

defined( 'ABSPATH' ) || exit;

class WPPilot_API {

	/** Actions that require user confirmation before execution. */
	private const CONFIRM_ACTIONS = [
		'create_product',
		'create_variable_product',
		'update_price',
		'edit_product',
		'update_stock',
		'change_status',
		'bulk_update_price',
		'update_variation_price',
		'update_variation_stock',
	];

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		register_rest_route( 'wppilot/v1', '/chat', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_chat' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'message'    => [ 'required' => true,  'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field' ],
				'session_id' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			],
		] );

		register_rest_route( 'wppilot/v1', '/session/clear', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_clear_session' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'session_id' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			],
		] );

		register_rest_route( 'wppilot/v1', '/pending/clear', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_clear_pending' ],
			'permission_callback' => [ $this, 'check_permission' ],
			'args'                => [
				'session_id' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			],
		] );
	}

	public function check_permission(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	public function handle_chat( WP_REST_Request $request ): WP_REST_Response {
		try {
			return $this->process_chat( $request );
		} catch ( Throwable $e ) {
			return new WP_REST_Response( [
				'type'       => 'error',
				'error_code' => 'unexpected_error',
				'message'    => 'Something went wrong. Please try again.',
			], 500 );
		}
	}

	private function process_chat( WP_REST_Request $request ): WP_REST_Response {
		$message    = $request->get_param( 'message' );
		$session_id = $request->get_param( 'session_id' ) ?: wp_generate_uuid4();
		$session    = new WPPilot_Session();
		$agent      = new WPPilot_Agent();

		// ── Rate limiting ─────────────────────────────────────────────────────────
		if ( ! $this->check_rate_limit( get_current_user_id() ) ) {
			$this->security_log( 'rate_limit_exceeded', get_current_user_id() );
			return new WP_REST_Response( [
				'type'       => 'error',
				'error_code' => 'rate_limited',
				'message'    => 'Too many requests — please wait a moment and try again.',
				'session_id' => $session_id,
			], 429 );
		}

		// ── AI availability check ─────────────────────────────────────────────────
		// Run before every chat turn (including confirmations) so errors are
		// always user-friendly rather than PHP fatals or silent failures.
		$support = $agent->check_ai_support();
		if ( is_wp_error( $support ) ) {
			return new WP_REST_Response( [
				'type'       => 'error',
				'error_code' => $support->get_error_code(),
				'message'    => $this->support_error_message( $support ),
				'session_id' => $session_id,
			], 503 );
		}

		// ── Confirmation path — execute a stored pending action ──────────────────
		$pending = $session->get_pending( $session_id );

		if ( null !== $pending ) {
			$session->clear_pending( $session_id );

			$action     = $pending['action'];
			$parameters = $pending['parameters'];
			$history    = $pending['history'];

			$result = $agent->execute_action( $action, $parameters );
			return $this->build_action_response( $action, $result, $history, $agent, $session, $session_id );
		}

		// ── Normal path — new user message ────────────────────────────────────────
		$history = $session->get( $session_id ) ?: [];

		$agent_result = $agent->process_message( $message, $history );

		// Error from agent.
		if ( 'error' === $agent_result['type'] ) {
			$code = $agent_result['error_code'] ?? 'ai_error';
			$msg  = $this->enrich_error_message( $code, $agent_result['message'] );

			return new WP_REST_Response( [
				'type'       => 'error',
				'error_code' => $code,
				'message'    => $msg,
				'session_id' => $session_id,
			], 502 );
		}

		// Record the user message now (after the AI call so we don't persist on error).
		$history[] = [ 'role' => 'user', 'content' => $message ];

		// ── Read action — execute immediately and re-run with result ─────────────
		if ( 'read_action' === $agent_result['type'] ) {
			$read_action_name = $agent_result['action'];
			$read_result      = $agent->execute_action( $read_action_name, $agent_result['parameters'] );

			$history[] = [
				'role'    => 'assistant',
				'content' => sprintf( '[%s result: %s]', $read_action_name, wp_json_encode( $read_result ) ),
			];

			// Tell the AI explicitly: data is in hand, either propose a write action
			// or answer the question — don't fetch data again.
			$enriched = $message
				. "\n\nData retrieved:\n" . wp_json_encode( $read_result )
				. "\n\nIf the user wants to change something, propose the appropriate write action."
				. ' If the user asked a question, use action "clarify" and put the answer in confirmation_message.';

			$agent_result = $agent->process_message( $enriched, $history );

			// Allow one follow-on read action (e.g. search_products → get_product).
			// Execute it and make one more AI call with the full data so it can answer.
			if ( 'read_action' === ( $agent_result['type'] ?? '' ) ) {
				$follow_action = $agent_result['action'];
				$follow_result = $agent->execute_action( $follow_action, $agent_result['parameters'] );

				$history[] = [
					'role'    => 'assistant',
					'content' => sprintf( '[%s result: %s]', $follow_action, wp_json_encode( $follow_result ) ),
				];

				$enriched2 = $message
					. "\n\nFull product data:\n" . wp_json_encode( $follow_result )
					. "\n\nUsing this data: if the user wants to change something, propose the appropriate write action with a confirmation_message. If the user asked a question, use action \"clarify\" and put the answer in confirmation_message. Do not fetch more data.";

				$agent_result = $agent->process_message( $enriched2, $history );

				// Final fallback: if STILL a read action, generate plain text from the data.
				if ( 'read_action' === ( $agent_result['type'] ?? '' ) ) {
					$text = $agent->generate_followup( $follow_action, $follow_result, $history );
					if ( empty( $text ) ) {
						unset( $follow_result['success'], $follow_result['edit_url'] );
						$text = wp_json_encode( $follow_result );
					}
					$history[] = [ 'role' => 'assistant', 'content' => $text ];
					$session->save( $session_id, $history );
					return new WP_REST_Response( [
						'type'       => 'text',
						'message'    => $text,
						'session_id' => $session_id,
					] );
				}
			}

			if ( 'error' === $agent_result['type'] ) {
				return new WP_REST_Response( [
					'type'       => 'error',
					'error_code' => $agent_result['error_code'] ?? 'ai_error',
					'message'    => $agent_result['message'],
					'session_id' => $session_id,
				], 502 );
			}
		}

		// ── Write action — store for confirmation ─────────────────────────────────
		if ( 'action' === $agent_result['type'] && in_array( $agent_result['action'], self::CONFIRM_ACTIONS, true ) ) {
			$session->save( $session_id, $history );
			$session->save_pending( $session_id, [
				'action'     => $agent_result['action'],
				'parameters' => $agent_result['parameters'],
				'history'    => $history,
			] );

			return new WP_REST_Response( [
				'type'        => 'confirmation_required',
				'message'     => $agent_result['message'] ?: $this->build_confirmation_message( $agent_result['action'], $agent_result['parameters'] ),
				'action_name' => $this->build_action_label( $agent_result['action'], $agent_result['parameters'] ),
				'session_id'  => $session_id,
			] );
		}

		// ── Text / clarification response ─────────────────────────────────────────
		$text = $agent_result['message'] ?? '';
		$history[] = [ 'role' => 'assistant', 'content' => $text ];
		$session->save( $session_id, $history );

		return new WP_REST_Response( [
			'type'       => 'text',
			'message'    => $text,
			'session_id' => $session_id,
		] );
	}

	// ── Post-execution response builder ──────────────────────────────────────────

	private function build_action_response(
		string $action,
		array $result,
		array $history,
		WPPilot_Agent $agent,
		WPPilot_Session $session,
		string $session_id
	): WP_REST_Response {
		// Fixed template for successful variable product creation.
		if ( 'create_variable_product' === $action && ! empty( $result['success'] ) ) {
			$product_name    = sanitize_text_field( $result['product_name'] ?? 'Product' );
			$variation_count = (int) ( $result['variation_count'] ?? 0 );
			$edit_url        = esc_url_raw( $result['edit_url'] ?? '' );
			$link            = $edit_url ? "\n\n[View {$product_name} in WooCommerce →]({$edit_url})" : '';
			$message         = "✓ **{$product_name}** created as draft variable product with {$variation_count} variation(s)\n\n"
				. "Review in WooCommerce before publishing:\n"
				. "- Check prices and stock for each variation\n"
				. "- Add product images\n"
				. "- Set categories and tags\n\n"
				. "When ready, publish it to make it live on your store.{$link}";

			$history[] = [ 'role' => 'assistant', 'content' => $message ];
			$session->save( $session_id, $history );

			return new WP_REST_Response( [
				'type'          => 'text',
				'message'       => $message,
				'tool_executed' => $action,
				'tool_result'   => $result,
				'session_id'    => $session_id,
			] );
		}

		// Fixed template for successful simple product creation — avoids an extra AI call.
		if ( 'create_product' === $action && ! empty( $result['success'] ) ) {
			$product_name = sanitize_text_field( $result['product_name'] ?? 'Product' );
			$edit_url     = esc_url_raw( $result['edit_url'] ?? '' );
			$link         = $edit_url ? "\n\n[View {$product_name} in WooCommerce →]({$edit_url})" : '';
			$message      = "✓ **{$product_name}** created as draft\n\n"
				. "The product has been created but is not yet published. Review it in WooCommerce to check:\n"
				. "- Price and stock levels\n"
				. "- Description and images\n"
				. "- Categories and tags\n\n"
				. "When everything looks good, publish it to make it live on your store.{$link}";

			$history[] = [ 'role' => 'assistant', 'content' => $message ];
			$session->save( $session_id, $history );

			return new WP_REST_Response( [
				'type'          => 'text',
				'message'       => $message,
				'tool_executed' => $action,
				'tool_result'   => $result,
				'session_id'    => $session_id,
			] );
		}

		// For all other actions, generate a conversational follow-up.
		$message = $agent->generate_followup( $action, $result, $history );

		// Fall back to the executor's own message if the AI call fails.
		if ( empty( $message ) ) {
			if ( empty( $result['success'] ) ) {
				$message = $result['error'] ?? __( 'The action could not be completed. Please try again.', 'storehand-ai-product-manager-for-woocommerce' );
			} else {
				$message = $result['message'] ?? __( 'Done! The action was completed successfully.', 'storehand-ai-product-manager-for-woocommerce' );
				$name    = sanitize_text_field( $result['product_name'] ?? '' );
				$url     = esc_url_raw( $result['edit_url'] ?? '' );
				if ( $url && $name ) {
					$message .= "\n\n[Open {$name} in WooCommerce →]({$url})";
				}
			}
		}

		$history[] = [ 'role' => 'assistant', 'content' => $message ];
		$session->save( $session_id, $history );

		return new WP_REST_Response( [
			'type'          => 'text',
			'message'       => $message,
			'tool_executed' => $action,
			'tool_result'   => $result,
			'session_id'    => $session_id,
		] );
	}

	// ── Confirmation message builders ─────────────────────────────────────────────

	private function build_confirmation_message( string $action, array $args ): string {
		return match ( $action ) {
			'create_product'          => $this->build_create_confirmation( $args ),
			'create_variable_product' => $this->build_variable_product_confirmation( $args ),
			'update_price',
			'edit_product'            => $this->build_edit_confirmation( $args ),
			'update_stock'           => $this->build_stock_confirmation( $args ),
			'change_status'          => $this->build_status_confirmation( $args ),
			'bulk_update_price'      => $this->build_bulk_price_confirmation( $args ),
			'update_variation_price' => $this->build_variation_price_confirmation( $args ),
			'update_variation_stock' => $this->build_variation_stock_confirmation( $args ),
			default                  => __( 'Proceed with this action?', 'storehand-ai-product-manager-for-woocommerce' ),
		};
	}

	private function build_variable_product_confirmation( array $args ): string {
		$name       = sanitize_text_field( $args['name'] ?? 'this product' );
		$attr_name  = strtolower( sanitize_text_field( $args['attribute_name'] ?? 'variation' ) );
		$variations = is_array( $args['variations'] ?? null ) ? $args['variations'] : [];
		$count      = count( $variations );
		$symbol     = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );

		/* translators: 1: product name, 2: variation count, 3: attribute name (e.g. size) */
		$message = sprintf(
			__( "I'm about to create \"%1\$s\" as a draft variable product with %2\$d %3\$s variation(s):", 'storehand-ai-product-manager-for-woocommerce' ),
			$name,
			$count,
			$attr_name
		);

		foreach ( $variations as $var ) {
			$value = sanitize_text_field( $var['value'] ?? '' );
			$price = number_format( (float) ( $var['price'] ?? 0 ), 2 );
			$message .= "\n• {$value} — {$symbol}{$price}";
		}

		return $message;
	}

	private function build_create_confirmation( array $args ): string {
		$name  = sanitize_text_field( $args['name'] ?? 'this product' );
		$price = isset( $args['price'] ) ? ' for ' . $this->format_price( (string) $args['price'] ) : '';
		/* translators: 1: product name, 2: price string (may be empty) */
		return sprintf( __( 'I\'m about to create "%1$s"%2$s as a draft product.', 'storehand-ai-product-manager-for-woocommerce' ), $name, $price );
	}

	private function build_edit_confirmation( array $args ): string {
		$product_id = (int) ( $args['product_id'] ?? 0 );
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		$name       = $product ? $product->get_name() : "ID {$product_id}";

		$editable = [ 'title', 'description', 'short_description', 'sku', 'regular_price', 'stock_quantity', 'stock_status', 'categories', 'tags' ];
		$fields   = array_intersect( array_keys( $args ), $editable );

		if ( count( $fields ) !== 1 || ! $product ) {
			/* translators: 1: number of fields, 2: product name */
			return sprintf( __( 'I\'m about to update %1$d field(s) on "%2$s".', 'storehand-ai-product-manager-for-woocommerce' ), count( $fields ), $name );
		}

		$field     = reset( $fields );
		$new_value = is_array( $args[ $field ] ) ? implode( ', ', $args[ $field ] ) : (string) $args[ $field ];

		switch ( $field ) {
			case 'title':
				/* translators: 1: product name, 2: current title, 3: new title */
				return sprintf( __( 'I\'m about to rename "%1$s" from "%2$s" to "%3$s".', 'storehand-ai-product-manager-for-woocommerce' ), $name, $product->get_name(), $new_value );

			case 'regular_price':
				$from = $this->format_price( $product->get_regular_price() ?: '0' );
				$to   = $this->format_price( $new_value );
				/* translators: 1: product name, 2: current price, 3: new price */
				return sprintf( __( 'I\'m about to update the price for "%1$s" from %2$s to %3$s.', 'storehand-ai-product-manager-for-woocommerce' ), $name, $from, $to );

			case 'sku':
				$current = $product->get_sku() ?: __( '(none)', 'storehand-ai-product-manager-for-woocommerce' );
				/* translators: 1: product name, 2: current SKU, 3: new SKU */
				return sprintf( __( 'I\'m about to change the SKU for "%1$s" from "%2$s" to "%3$s".', 'storehand-ai-product-manager-for-woocommerce' ), $name, $current, $new_value );

			case 'stock_quantity':
				$current = (int) ( $product->get_stock_quantity() ?? 0 );
				/* translators: 1: product name, 2: current stock, 3: new stock */
				return sprintf( __( 'I\'m about to update stock for "%1$s" from %2$d to %3$d.', 'storehand-ai-product-manager-for-woocommerce' ), $name, $current, (int) $new_value );

			case 'stock_status':
				/* translators: 1: product name, 2: current status, 3: new status */
				return sprintf( __( 'I\'m about to change stock status for "%1$s" from "%2$s" to "%3$s".', 'storehand-ai-product-manager-for-woocommerce' ), $name, $product->get_stock_status(), $new_value );

			case 'categories':
			case 'tags':
				$label = 'categories' === $field ? __( 'categories', 'storehand-ai-product-manager-for-woocommerce' ) : __( 'tags', 'storehand-ai-product-manager-for-woocommerce' );
				/* translators: 1: field label, 2: product name, 3: new values */
				return sprintf( __( 'I\'m about to update %1$s for "%2$s" to: %3$s.', 'storehand-ai-product-manager-for-woocommerce' ), $label, $name, $new_value );

			default:
				$label = str_replace( '_', ' ', $field );
				/* translators: 1: field name, 2: product name */
				return sprintf( __( 'I\'m about to update the %1$s for "%2$s".', 'storehand-ai-product-manager-for-woocommerce' ), $label, $name );
		}
	}

	private function build_stock_confirmation( array $args ): string {
		$product_id = (int) ( $args['product_id'] ?? 0 );
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		$name       = $product ? $product->get_name() : "ID {$product_id}";
		$current    = $product ? (int) ( $product->get_stock_quantity() ?? 0 ) : 0;

		if ( isset( $args['set_to'] ) ) {
			$new = (int) $args['set_to'];
			/* translators: 1: product name, 2: current stock, 3: new stock */
			return sprintf( __( 'I\'m about to set stock for "%1$s" from %2$d to %3$d.', 'storehand-ai-product-manager-for-woocommerce' ), $name, $current, $new );
		}
		if ( isset( $args['increase_by'] ) ) {
			$by  = (int) $args['increase_by'];
			$new = $current + $by;
			if ( $by >= 0 ) {
				/* translators: 1: product name, 2: current stock, 3: new stock */
				return sprintf( __( 'I\'m about to increase stock for "%1$s" from %2$d to %3$d.', 'storehand-ai-product-manager-for-woocommerce' ), $name, $current, $new );
			}
			/* translators: 1: product name, 2: current stock, 3: new stock */
			return sprintf( __( 'I\'m about to decrease stock for "%1$s" from %2$d to %3$d.', 'storehand-ai-product-manager-for-woocommerce' ), $name, $current, $new );
		}
		/* translators: %s: product name */
		return sprintf( __( 'I\'m about to update stock for "%s".', 'storehand-ai-product-manager-for-woocommerce' ), $name );
	}

	private function build_status_confirmation( array $args ): string {
		$product_id = (int) ( $args['product_id'] ?? 0 );
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		$name       = $product ? $product->get_name() : "ID {$product_id}";
		$before     = $product ? $product->get_status() : 'unknown';
		$after      = sanitize_key( $args['status'] ?? '' );
		/* translators: 1: product name, 2: current status, 3: new status */
		return sprintf( __( 'I\'m about to change the status of "%1$s" from "%2$s" to "%3$s".', 'storehand-ai-product-manager-for-woocommerce' ), $name, $before, $after );
	}

	private function build_variation_price_confirmation( array $args ): string {
		$variation_id = absint( $args['variation_id'] ?? 0 );
		$variation    = $variation_id ? wc_get_product( $variation_id ) : null;

		if ( ! $variation || $variation->get_type() !== 'variation' ) {
			/* translators: %d: variation ID */
			return sprintf( __( "I'm about to update the price for variation #%d.", 'storehand-ai-product-manager-for-woocommerce' ), $variation_id );
		}

		$parent    = wc_get_product( $variation->get_parent_id() );
		$name      = $parent ? $parent->get_name() : "variation #{$variation_id}";
		$attrs     = $this->format_variation_attrs( $variation );
		$from      = $this->format_price( $variation->get_regular_price() ?: '0' );
		$to        = $this->format_price( (string) ( $args['price'] ?? $args['regular_price'] ?? '0' ) );
		/* translators: 1: product name, 2: variation attributes, 3: current price, 4: new price */
		return sprintf( __( "I'm about to update the price for %1\$s (%2\$s) from %3\$s to %4\$s.", 'storehand-ai-product-manager-for-woocommerce' ), $name, $attrs, $from, $to );
	}

	private function build_variation_stock_confirmation( array $args ): string {
		$variation_id = absint( $args['variation_id'] ?? 0 );
		$variation    = $variation_id ? wc_get_product( $variation_id ) : null;

		if ( ! $variation || $variation->get_type() !== 'variation' ) {
			/* translators: %d: variation ID */
			return sprintf( __( "I'm about to update stock for variation #%d.", 'storehand-ai-product-manager-for-woocommerce' ), $variation_id );
		}

		$parent  = wc_get_product( $variation->get_parent_id() );
		$name    = $parent ? $parent->get_name() : "variation #{$variation_id}";
		$attrs   = $this->format_variation_attrs( $variation );
		$current = (int) ( $variation->get_stock_quantity() ?? 0 );
		$new     = (int) ( $args['quantity'] ?? $args['set_to'] ?? 0 );
		/* translators: 1: product name, 2: variation attributes, 3: current stock, 4: new stock */
		return sprintf( __( "I'm about to set stock for %1\$s (%2\$s) from %3\$d to %4\$d.", 'storehand-ai-product-manager-for-woocommerce' ), $name, $attrs, $current, $new );
	}

	private function format_variation_attrs( WC_Product_Variation $variation ): string {
		$parts = [];
		foreach ( $variation->get_variation_attributes() as $tax_key => $term_slug ) {
			$taxonomy = str_replace( 'attribute_', '', $tax_key );
			if ( taxonomy_exists( $taxonomy ) ) {
				$term  = get_term_by( 'slug', $term_slug, $taxonomy );
				$value = ( $term && ! is_wp_error( $term ) ) ? $term->name : $term_slug;
			} else {
				$value = $term_slug;
			}
			if ( $value ) {
				$parts[] = $value;
			}
		}
		return implode( ', ', $parts ) ?: "variation #{$variation->get_id()}";
	}

	private function build_bulk_price_confirmation( array $args ): string {
		$pct   = (float) ( $args['percentage'] ?? 0 );
		$cat   = sanitize_text_field( $args['category'] ?? '' );
		$dir   = $pct > 0 ? __( 'increase', 'storehand-ai-product-manager-for-woocommerce' ) : __( 'decrease', 'storehand-ai-product-manager-for-woocommerce' );
		$label = abs( $pct ) . '%';
		$scope = $cat
			/* translators: %s: category name */
			? sprintf( __( 'all products in "%s"', 'storehand-ai-product-manager-for-woocommerce' ), $cat )
			: __( 'all products', 'storehand-ai-product-manager-for-woocommerce' );
		/* translators: 1: increase/decrease, 2: product scope, 3: percentage */
		return sprintf( __( "I'm about to %1\$s prices for %2\$s by %3\$s.", 'storehand-ai-product-manager-for-woocommerce' ), $dir, $scope, $label );
	}

	private function format_price( string $price ): string {
		$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		return $symbol . number_format( (float) $price, 2 );
	}

	private function build_action_label( string $action, array $args ): string {
		$name = sanitize_text_field( $args['name'] ?? '' );
		return match ( $action ) {
			'create_product'          => $name ? "Create \"{$name}\"" : 'Create product',
			'create_variable_product' => $name ? "Create \"{$name}\" (variable)" : 'Create variable product',
			'update_price'            => 'Update price',
			'edit_product'           => 'Edit product',
			'update_stock'           => 'Update stock',
			'change_status'          => 'Change status',
			'bulk_update_price'      => 'Bulk update prices',
			'update_variation_price' => 'Update variation price',
			'update_variation_stock' => 'Update variation stock',
			default                  => 'Proceed',
		};
	}

	// ── Error message helpers ─────────────────────────────────────────────────────

	/**
	 * Append a relevant action link to error messages where one is helpful.
	 */
	private function enrich_error_message( string $code, string $message ): string {
		return match ( $code ) {
			'no_wp_ai'        => $message . ' ' . __( 'Please upgrade to WordPress 7.0 or higher.', 'storehand-ai-product-manager-for-woocommerce' ),
			'no_provider',
			'ai_disabled'     => $message . ' ' . __( 'Go to Settings → AI and Settings → Connectors in your WordPress dashboard.', 'storehand-ai-product-manager-for-woocommerce' ),
			'invalid_api_key' => $message . ' ' . __( 'Go to Settings → Connectors to check your API key.', 'storehand-ai-product-manager-for-woocommerce' ),
			default           => $message,
		};
	}

	/**
	 * Build a user-facing message from a WP_Error returned by check_ai_support().
	 */
	private function support_error_message( WP_Error $error ): string {
		return $this->enrich_error_message( $error->get_error_code(), $error->get_error_message() );
	}

	// ── Session endpoints ─────────────────────────────────────────────────────────

	public function handle_clear_session( WP_REST_Request $request ): WP_REST_Response {
		( new WPPilot_Session() )->clear( $request->get_param( 'session_id' ) );
		return new WP_REST_Response( [ 'cleared' => true ] );
	}

	public function handle_clear_pending( WP_REST_Request $request ): WP_REST_Response {
		( new WPPilot_Session() )->clear_pending( $request->get_param( 'session_id' ) );
		return new WP_REST_Response( [ 'cleared' => true ] );
	}

	// ── Rate limiting ─────────────────────────────────────────────────────────────

	private function check_rate_limit( int $user_id ): bool {
		$key   = 'wppilot_rl_' . $user_id;
		$count = (int) get_transient( $key );

		if ( $count >= 60 ) {
			return false;
		}

		// set_transient preserves the remaining TTL on an existing key only when
		// using object-cache drivers that support it; for DB transients, we reset
		// a 60-second window on every increment.  Good enough for abuse prevention.
		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	private function security_log( string $event, int $user_id ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( "[WPPilot Security] {$event} | user:{$user_id}" );
	}
}
