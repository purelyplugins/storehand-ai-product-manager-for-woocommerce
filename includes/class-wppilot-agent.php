<?php
/**
 * AI agent for Pilot — uses the WordPress 7.0 AI Client for intent detection,
 * WPPilot_Tool_Executor for execution.
 *
 * No Abilities API: that layer auto-executes and doesn't support the
 * confirmation-before-change flow Pilot requires.
 *
 * @package WPPilot
 */

defined( 'ABSPATH' ) || exit;

class WPPilot_Agent {

	/** Actions executed immediately without confirmation (read-only). */
	public const READ_ACTIONS = [ 'search_products', 'get_product', 'get_variation' ];

	/** Actions that require user confirmation before executing. */
	public const CONFIRM_ACTIONS = [ 'create_product', 'create_variable_product', 'update_price', 'edit_product', 'update_stock', 'change_status', 'bulk_update_price', 'update_variation_price', 'update_variation_stock' ];

	private function system_instruction(): string {
		$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$code   = get_woocommerce_currency();

		$help_mode = ( '0' !== (string) get_option( 'wppilot_help_mode_enabled', '1' ) );

		$identity = $help_mode
			? 'You are StoreHand AI Product Manager, a WooCommerce assistant. You manage products AND answer general WooCommerce questions.'
			: 'You are StoreHand AI Product Manager, a WooCommerce product manager.';

		$actions = 'Actions:' . "\n"
			. '- search_products          {query}                          — runs immediately, no confirmation' . "\n"
			. '- get_product              {product_id}                     — runs immediately, no confirmation' . "\n"
			. '- get_variation            {variation_id}                   — runs immediately, no confirmation' . "\n"
			. '- create_product           {name, price?, description?, short_description?, sku?, stock_quantity?, categories?, tags?, image_id?}' . "\n"
			. '- create_variable_product  {name, attribute_name, variations: [{value, price, sku?, stock_quantity?}], description?, short_description?, categories?, tags?, image_id?}' . "\n"
			. '- update_price             {product_id, price}' . "\n"
			. '- edit_product             {product_id, name?, description?, short_description?, sku?, stock_status?, image_id?}  — works on simple and variable products; price/stock on variable must use variation actions' . "\n"
			. '- update_stock             {product_id, quantity}' . "\n"
			. '- change_status            {product_id, status: publish|draft|private|pending}' . "\n"
			. '- bulk_update_price        {percentage, category?}  — percentage: 10 for +10%, -5 for -5%; category is optional' . "\n"
			. '- update_variation_price   {variation_id, price}' . "\n"
			. '- update_variation_stock   {variation_id, quantity}' . "\n"
			. '- clarify                  {}  — ask one question in confirmation_message' . "\n\n";

		$rules = 'Rules:' . "\n"
			. '- No emojis anywhere in your responses.' . "\n"
			. '- Products are always created as draft.' . "\n"
			. '- For publish/unpublish use change_status with the parent product_id — this works for both simple and variable products.' . "\n"
			. '- Product referenced by name: use search_products first, then use the returned product_id.' . "\n"
			. '- If search result does not contain the data needed (e.g. description is not in search results), immediately propose get_product with the found product_id — do not ask the user if they want you to.' . "\n"
			. '- When asked to rewrite or update a product description (or any field requiring knowledge of the current value), fetch the current data with get_product first — never ask the user to provide the existing content.' . "\n"
			. '- When a user says "same as [product]", "like [product]", or "copy from [product]", search for that product, get its full details with get_product, then use those details as a template for the new product.' . "\n"
			. '- When copying from a product using "same as", "like", or "copy from", always include image_id in the new product parameters if the source product has one — do not mention the image separately, just include it silently.' . "\n"
			. '- When creating a product (simple or variable) and no image has been provided, mention in confirmation_message that an image can be attached using the image button in the chat before confirming, or added later in WooCommerce.' . "\n"
			. '- When writing a new description, verify it contains all details the user requested before proposing the action. If the user said "mention bamboo", bamboo must appear in parameters.description.' . "\n"
			. '- When proposing an edit_product with a description, include the first 100 characters of the new description in the confirmation_message so the user can verify the content before confirming.' . "\n"
			. '- price is always a string like "28.00". Prices must be positive numbers — if the user gives a negative or zero price, use clarify to explain this.' . "\n"
			. '- categories and tags are arrays of strings.' . "\n"
			. '- categories and tags must already exist in WooCommerce — never create new ones. If the user specifies a category or tag that does not exist, use clarify to tell them it does not exist and ask if they want to use a different one or create it manually in WooCommerce.' . "\n"
			. '- If the user responds with just a product ID or number (e.g. "#1126") after you asked which product to use, treat it as selecting that product and continue with the intended action.' . "\n"
			. '- If the user asks you to DO something that is not one of the listed actions (e.g. create a coupon, set up shipping, configure tax), immediately use action "clarify" to say you cannot do that and explain how to do it in WooCommerce admin. Do not ask for details about tasks you cannot execute.' . "\n"
			. '- If input is ambiguous or contains an invalid value, always use action "clarify" and explain the issue in confirmation_message. Never return non-JSON.' . "\n"
			. '- For products with multiple sizes, colours, or other options, use create_variable_product, not create_product.' . "\n"
			. '- Before proposing create_variable_product, you must have: name, attribute_name (e.g. "Size"), all variation values, and a price for EVERY variation. If anything is missing, use clarify to collect it conversationally.' . "\n"
			. '- Never propose create_variable_product without a price for every variation.' . "\n"
			. '- If the user requests more than 12 variations, use clarify to explain that StoreHand AI Product Manager supports up to 12 variations and they can add more in WooCommerce admin after creation.' . "\n"
			. '- variations is an array of objects: [{"value":"Small","price":"25.00"},{"value":"Medium","price":"27.00"}]' . "\n"
			. '- Variable products: search results include product_type. If product_type is "variable", the product has no single price or stock — these live on its variations.' . "\n"
			. '- For any write on a variable product (price, stock), always call get_product first to list its variations, then identify the correct variation by its attributes.' . "\n"
			. '- A variation_id is different from a parent product_id — never use a parent product_id for update_variation_price or update_variation_stock.' . "\n"
			. '- If only one variation matches the user description, auto-select it. If multiple variations match, use clarify to ask the user which one.' . "\n"
			. '- When updating variation stock, only propose update_variation_stock for the single specific variation the user mentioned — never propose updating multiple variations in one confirmation unless the user explicitly asked to update all of them.' . "\n"
			. '- To set an image on an existing variable product, use edit_product with image_id — this is supported.' . "\n"
			. '- If you use an image_id sourced from another product, mention this in the confirmation_message (e.g. "using the same image as Blue Hoodie") and note the user can attach a different image by uploading one before confirming.' . "\n"
			. '- For bulk_update_price, percentage is a number (not a string): 10 means +10%, -10 means -10%.' . "\n"
			. '- If the user says "increase/decrease all prices by X%" with no category mentioned, immediately propose bulk_update_price with no category parameter — do not ask for clarification about scope.' . "\n";

		$examples = "\n" . 'Examples:' . "\n"
			. '{"action":"create_product","parameters":{"name":"Blue Widget","price":"29.99"},"confirmation_message":"I\'ll create \'Blue Widget\' for ' . $symbol . '29.99 as a draft. Proceed?"}' . "\n"
			. '{"action":"update_stock","parameters":{"product_id":123,"quantity":50},"confirmation_message":"I\'ll set stock for product #123 to 50. Proceed?"}' . "\n"
			. '{"action":"search_products","parameters":{"query":"red hoodie"},"confirmation_message":"Searching for \'red hoodie\'..."}' . "\n"
			. '{"action":"get_product","parameters":{"product_id":123},"confirmation_message":"Fetching full details for product #123..."}' . "\n"
			. '{"action":"clarify","parameters":{},"confirmation_message":"Which product would you like to update?"}' . "\n"
			. '{"action":"create_variable_product","parameters":{"name":"Red Hoodie","attribute_name":"Size","variations":[{"value":"Small","price":"25.00"},{"value":"Medium","price":"27.00"},{"value":"Large","price":"29.00"}]},"confirmation_message":"I\'ll create \'Red Hoodie\' as a draft variable product with 3 size variations: Small ' . $symbol . '25.00, Medium ' . $symbol . '27.00, Large ' . $symbol . '29.00. Proceed?"}' . "\n"
			. '{"action":"update_variation_price","parameters":{"variation_id":456,"price":"45.00"},"confirmation_message":"I\'ll update the price for Red Hoodie (Large) to ' . $symbol . '45.00. Proceed?"}' . "\n"
			. '{"action":"update_variation_stock","parameters":{"variation_id":456,"quantity":10},"confirmation_message":"I\'ll set stock for Red Hoodie (Medium) to 10. Proceed?"}';

		$help_block = $help_mode
			? "\n\n"
			  . 'General WooCommerce help:' . "\n"
			  . '- Product actions always take priority over help answers.' . "\n"
			  . '- When the user asks a how-to or conceptual WooCommerce question (e.g. "how do I set up coupons?", "what is a SKU?", "explain shipping zones"), you MUST answer it — do not say it is outside your scope.' . "\n"
			  . '- For help answers: use action "clarify" and put the full answer in confirmation_message. Keep answers under 200 words. Numbered steps are fine.' . "\n"
			  . '- Phrases like "how do I", "what is", "explain", "how does" signal a help question, not a product action.' . "\n"
			  . '- Do not invent WooCommerce features you are not certain about.' . "\n"
			  . 'Help example: {"action":"clarify","parameters":{},"confirmation_message":"To set up a coupon: go to Marketing > Coupons > Add Coupon. Enter a code, choose a discount type (percentage or fixed), set the amount, and optionally add usage limits or expiry date."}'
			: '';

		return $identity . ' Respond with JSON only — no markdown, no extra text, no emojis.' . "\n\n"
			. 'Format: {"action":"ACTION","parameters":{},"confirmation_message":"text"}' . "\n\n"
			. 'Site currency: ' . $symbol . ' (' . $code . ') — always use this symbol when displaying prices to the user.' . "\n\n"
			. $actions
			. $rules
			. $examples
			. $help_block;
	}

	/**
	 * Check whether the WordPress AI platform is available.
	 *
	 * @return true|WP_Error
	 */
	public function check_ai_support(): true|WP_Error {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new WP_Error( 'no_wp_ai', 'WordPress AI platform not available. Please upgrade to WordPress 7.0 or higher.' );
		}
		if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
			return new WP_Error( 'ai_disabled', 'AI is not available. Check that AI is enabled in Settings → AI and that a provider is connected in Settings → Connectors.' );
		}
		return true;
	}

	/**
	 * Detect intent from a user message.
	 *
	 * @param string $user_message
	 * @param array  $history  [{role, content}]
	 * @return array {type, action?, parameters?, message, error_code?}
	 */
	public function process_message( string $user_message, array $history ): array {
		$support = $this->check_ai_support();
		if ( is_wp_error( $support ) ) {
			return $this->error( $support->get_error_code(), $support->get_error_message() );
		}

		$prompt = $this->build_prompt( $user_message, $history );

		$help_mode  = (bool) get_option( 'wppilot_help_mode_enabled', true );
		$max_tokens = $help_mode ? 800 : 500;

		$raw = wp_ai_client_prompt( $prompt )
			->using_system_instruction( $this->system_instruction() )
			->using_model_preference( 'claude-haiku-4-5-20251001', 'claude-sonnet-4-6' )
			->using_max_tokens( $max_tokens )
			->generate_text();

		if ( is_wp_error( $raw ) ) {
			return $this->map_wp_error( $raw );
		}

		if ( ! is_string( $raw ) ) {
			return $this->error( 'parse_error', 'Unexpected response from AI. Please try again.' );
		}

		$data = $this->parse_json_response( $raw );
		if ( is_wp_error( $data ) ) {
			// AI returned natural language instead of JSON (e.g. explaining why it
			// can't fulfil the request). Surface it rather than showing a generic error.
			$text = sanitize_textarea_field( $raw );
			if ( '' !== $text ) {
				return [ 'type' => 'text', 'message' => $text ];
			}
			return $this->error( 'parse_error', 'Could not understand the AI response. Please try again.' );
		}

		return $this->normalise_result( $data );
	}

	/**
	 * Execute a confirmed action directly via WPPilot_Tool_Executor.
	 *
	 * @param string $action      e.g. 'update_stock'
	 * @param array  $parameters  Parameters from the intent-detection step.
	 * @return array  Result with at least a 'success' key.
	 */
	public function execute_action( string $action, array $parameters ): array {
		$executor = new WPPilot_Tool_Executor();

		// Map simplified AI parameter names to what the executor expects.
		switch ( $action ) {
			case 'create_product':
				if ( isset( $parameters['price'] ) && ! isset( $parameters['regular_price'] ) ) {
					$parameters['regular_price'] = (string) $parameters['price'];
					unset( $parameters['price'] );
				}
				return $executor->execute( 'create_simple_product', $parameters );

			case 'create_variable_product':
				return $executor->execute( 'create_variable_product', $parameters );

			case 'update_price':
				$params = [
					'product_id'    => $parameters['product_id'],
					'regular_price' => (string) ( $parameters['price'] ?? $parameters['regular_price'] ?? '' ),
				];
				return $executor->execute( 'edit_product', $params );

			case 'edit_product':
				if ( isset( $parameters['name'] ) && ! isset( $parameters['title'] ) ) {
					$parameters['title'] = $parameters['name'];
					unset( $parameters['name'] );
				}
				return $executor->execute( 'edit_product', $parameters );

			case 'update_stock':
				// AI returns 'quantity'; executor expects 'set_to'.
				$params = [
					'product_id' => $parameters['product_id'],
					'set_to'     => (int) ( $parameters['quantity'] ?? $parameters['set_to'] ?? 0 ),
				];
				return $executor->execute( 'update_stock_level', $params );

			case 'change_status':
				return $executor->execute( 'change_product_status', $parameters );

			case 'search_products':
				return $executor->execute( 'search_products', $parameters );

			case 'get_product':
				return $executor->execute( 'get_product', $parameters );

			case 'get_variation':
				return $executor->execute( 'get_variation', $parameters );

			case 'bulk_update_price':
				return $executor->execute( 'bulk_update_price', $parameters );

			case 'update_variation_price':
				if ( isset( $parameters['price'] ) && ! isset( $parameters['regular_price'] ) ) {
					$parameters['regular_price'] = (string) $parameters['price'];
					unset( $parameters['price'] );
				}
				return $executor->execute( 'update_variation_price', $parameters );

			case 'update_variation_stock':
				return $executor->execute( 'update_variation_stock', $parameters );

			default:
				return [ 'success' => false, 'error' => "Unknown action: {$action}" ];
		}
	}

	/**
	 * Generate a plain-text follow-up after an action has executed.
	 *
	 * @param string $action
	 * @param array  $result
	 * @param array  $history
	 * @return string  Empty string on failure (caller should use a fallback).
	 */
	public function generate_followup( string $action, array $result, array $history ): string {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return '';
		}

		$result_json = wp_json_encode( $result );
		$context     = '';
		foreach ( array_slice( $history, -6 ) as $turn ) {
			if ( ! is_string( $turn['content'] ?? null ) || '' === $turn['content'] ) {
				continue;
			}
			$role     = 'user' === ( $turn['role'] ?? '' ) ? 'User' : 'Assistant';
			$context .= "{$role}: {$turn['content']}\n";
		}

		$prompt = "A WooCommerce action just completed: {$action}\nResult: {$result_json}\n\n"
			. ( $context ? "Recent conversation:\n{$context}\n" : '' )
			. 'Write a brief, friendly summary of what was done and offer to help with anything else.';

		$symbol   = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		$response = wp_ai_client_prompt( $prompt )
			->using_system_instruction( "You are StoreHand AI Product Manager, a WooCommerce AI assistant. Be concise and friendly. No emojis. Site currency symbol: {$symbol}." )
			->using_model_preference( 'claude-haiku-4-5-20251001', 'claude-sonnet-4-6' )
			->using_max_tokens( 200 )
			->generate_text();

		if ( is_wp_error( $response ) || ! is_string( $response ) ) {
			return '';
		}

		return sanitize_textarea_field( $response );
	}

	// ── Private helpers ───────────────────────────────────────────────────────────

	private function build_prompt( string $user_message, array $history ): string {
		$lines = [];
		foreach ( array_slice( $history, -8 ) as $turn ) {
			if ( ! is_string( $turn['content'] ?? null ) || '' === $turn['content'] ) {
				continue;
			}
			$role    = 'user' === ( $turn['role'] ?? '' ) ? 'User' : 'Assistant';
			$lines[] = "{$role}: {$turn['content']}";
		}
		$lines[] = "User: {$user_message}";
		return implode( "\n", $lines );
	}

	private function normalise_result( array $data ): array {
		$action     = sanitize_key( $data['action'] ?? '' );
		$message    = sanitize_textarea_field( $data['confirmation_message'] ?? '' );
		$parameters = is_array( $data['parameters'] ?? null ) ? $data['parameters'] : [];
		$fallback   = $message ?: "I'm not sure I understood that — could you rephrase?";

		if ( 'clarify' === $action || '' === $action ) {
			return [ 'type' => 'text', 'message' => $fallback ];
		}

		$all_valid = array_merge( self::READ_ACTIONS, self::CONFIRM_ACTIONS );
		if ( ! in_array( $action, $all_valid, true ) ) {
			$this->security_log( 'invalid_action', [ 'action' => $action ] );
			return [ 'type' => 'text', 'message' => $fallback ];
		}

		$validation = $this->validate_action_params( $action, $parameters );
		if ( is_wp_error( $validation ) ) {
			return [ 'type' => 'text', 'message' => $validation->get_error_message() ];
		}

		$is_read = in_array( $action, self::READ_ACTIONS, true );
		return [
			'type'       => $is_read ? 'read_action' : 'action',
			'action'     => $action,
			'parameters' => $parameters,
			'message'    => $message,
		];
	}

	private function validate_action_params( string $action, array $p ): true|WP_Error {
		switch ( $action ) {
			case 'search_products':
				if ( empty( $p['query'] ) ) {
					return new WP_Error( 'missing_query', 'What would you like to search for?' );
				}
				if ( strlen( (string) $p['query'] ) > 200 ) {
					$this->security_log( 'oversized_param', [ 'action' => $action, 'param' => 'query' ] );
					return new WP_Error( 'invalid_query', 'Search query is too long.' );
				}
				break;

			case 'get_product':
				if ( empty( $p['product_id'] ) || ! is_numeric( $p['product_id'] ) || (int) $p['product_id'] < 1 ) {
					return new WP_Error( 'missing_product_id', 'Which product ID would you like to look up?' );
				}
				break;

			case 'create_product':
				if ( empty( $p['name'] ) ) {
					return new WP_Error( 'missing_name', 'What should the product be called?' );
				}
				if ( strlen( (string) $p['name'] ) > 200 ) {
					$this->security_log( 'oversized_param', [ 'action' => $action, 'param' => 'name' ] );
					return new WP_Error( 'invalid_name', 'Product name must be 200 characters or fewer.' );
				}
				$price = $p['price'] ?? $p['regular_price'] ?? null;
				if ( null !== $price ) {
					$price_val = (float) $price;
					if ( $price_val < 0 || $price_val > 999999 ) {
						$this->security_log( 'out_of_range', [ 'action' => $action, 'param' => 'price', 'value' => $price_val ] );
						return new WP_Error( 'invalid_price', 'Price must be between 0 and 999,999.' );
					}
				}
				if ( isset( $p['stock_quantity'] ) && ( (int) $p['stock_quantity'] < 0 || (int) $p['stock_quantity'] > 999999 ) ) {
					return new WP_Error( 'invalid_stock', 'Stock quantity must be between 0 and 999,999.' );
				}
				if ( isset( $p['categories'] ) && ! is_array( $p['categories'] ) ) {
					return new WP_Error( 'invalid_categories', 'Categories must be a list.' );
				}
				if ( isset( $p['tags'] ) && ! is_array( $p['tags'] ) ) {
					return new WP_Error( 'invalid_tags', 'Tags must be a list.' );
				}
				break;

			case 'create_variable_product':
				if ( empty( $p['name'] ) ) {
					return new WP_Error( 'missing_name', 'What should the product be called?' );
				}
				if ( strlen( (string) $p['name'] ) > 200 ) {
					$this->security_log( 'oversized_param', [ 'action' => $action, 'param' => 'name' ] );
					return new WP_Error( 'invalid_name', 'Product name must be 200 characters or fewer.' );
				}
				if ( empty( $p['attribute_name'] ) ) {
					return new WP_Error( 'missing_attribute', 'What attribute do the variations use (e.g. Size, Colour)?' );
				}
				if ( ! isset( $p['variations'] ) || ! is_array( $p['variations'] ) || empty( $p['variations'] ) ) {
					return new WP_Error( 'missing_variations', 'What variations should this product have?' );
				}
				if ( count( $p['variations'] ) > 12 ) {
					$this->security_log( 'out_of_range', [ 'action' => $action, 'param' => 'variations', 'value' => count( $p['variations'] ) ] );
					return new WP_Error( 'too_many_variations', 'Pilot supports up to 12 variations. You can add more in WooCommerce admin after creation.' );
				}
				foreach ( $p['variations'] as $var ) {
					if ( empty( $var['value'] ) ) {
						return new WP_Error( 'missing_variation_value', 'Every variation needs a value (e.g. Small, Red).' );
					}
					$var_price = $var['price'] ?? null;
					if ( null === $var_price || ! is_numeric( $var_price ) || (float) $var_price <= 0 ) {
						$label = sanitize_text_field( $var['value'] );
						return new WP_Error( 'missing_variation_price', "What price should \"{$label}\" be? Every variation needs a price." );
					}
					if ( (float) $var_price > 999999 ) {
						$this->security_log( 'out_of_range', [ 'action' => $action, 'param' => 'variation_price', 'value' => (float) $var_price ] );
						return new WP_Error( 'invalid_price', 'Price must be between 0 and 999,999.' );
					}
				}
				break;

			case 'update_price':
				if ( empty( $p['product_id'] ) || ! is_numeric( $p['product_id'] ) || (int) $p['product_id'] < 1 ) {
					return new WP_Error( 'missing_product_id', 'Which product would you like to update the price for?' );
				}
				$price = $p['price'] ?? $p['regular_price'] ?? null;
				if ( null === $price ) {
					return new WP_Error( 'missing_price', 'What should the new price be?' );
				}
				$price_val = (float) $price;
				if ( $price_val < 0 || $price_val > 999999 ) {
					$this->security_log( 'out_of_range', [ 'action' => $action, 'param' => 'price', 'value' => $price_val ] );
					return new WP_Error( 'invalid_price', 'Price must be between 0 and 999,999.' );
				}
				break;

			case 'edit_product':
				if ( empty( $p['product_id'] ) || ! is_numeric( $p['product_id'] ) || (int) $p['product_id'] < 1 ) {
					return new WP_Error( 'missing_product_id', 'Which product would you like to edit?' );
				}
				if ( isset( $p['name'] ) && strlen( (string) $p['name'] ) > 200 ) {
					$this->security_log( 'oversized_param', [ 'action' => $action, 'param' => 'name' ] );
					return new WP_Error( 'invalid_name', 'Product name must be 200 characters or fewer.' );
				}
				if ( isset( $p['regular_price'] ) ) {
					$price_val = (float) $p['regular_price'];
					if ( $price_val < 0 || $price_val > 999999 ) {
						$this->security_log( 'out_of_range', [ 'action' => $action, 'param' => 'regular_price', 'value' => $price_val ] );
						return new WP_Error( 'invalid_price', 'Price must be between 0 and 999,999.' );
					}
				}
				break;

			case 'update_stock':
				if ( empty( $p['product_id'] ) || ! is_numeric( $p['product_id'] ) || (int) $p['product_id'] < 1 ) {
					return new WP_Error( 'missing_product_id', "Which product's stock would you like to update?" );
				}
				if ( ! isset( $p['quantity'] ) && ! isset( $p['set_to'] ) && ! isset( $p['increase_by'] ) ) {
					return new WP_Error( 'missing_quantity', 'What should the stock be set to?' );
				}
				$qty = (int) ( $p['quantity'] ?? $p['set_to'] ?? $p['increase_by'] );
				if ( $qty < -999999 || $qty > 999999 ) {
					$this->security_log( 'out_of_range', [ 'action' => $action, 'param' => 'quantity', 'value' => $qty ] );
					return new WP_Error( 'invalid_quantity', 'Stock quantity must be between -999,999 and 999,999.' );
				}
				break;

			case 'change_status':
				if ( empty( $p['product_id'] ) || ! is_numeric( $p['product_id'] ) || (int) $p['product_id'] < 1 ) {
					return new WP_Error( 'missing_product_id', "Which product's status would you like to change?" );
				}
				$valid = [ 'publish', 'draft', 'private', 'pending' ];
				if ( empty( $p['status'] ) || ! in_array( $p['status'], $valid, true ) ) {
					return new WP_Error( 'invalid_status', 'What status? Options are: publish, draft, private, or pending.' );
				}
				break;

			case 'bulk_update_price':
				if ( ! isset( $p['percentage'] ) || ! is_numeric( $p['percentage'] ) || 0 == $p['percentage'] ) {
					return new WP_Error( 'missing_percentage', 'What percentage? For example, 10 to increase by 10% or -5 to decrease by 5%.' );
				}
				$pct = (float) $p['percentage'];
				if ( $pct < -100 || $pct > 1000 ) {
					$this->security_log( 'out_of_range', [ 'action' => $action, 'param' => 'percentage', 'value' => $pct ] );
					return new WP_Error( 'invalid_percentage', 'Percentage must be between -100 and 1000.' );
				}
				if ( isset( $p['category'] ) && strlen( (string) $p['category'] ) > 200 ) {
					return new WP_Error( 'invalid_category', 'Category name is too long.' );
				}
				break;

			case 'get_variation':
				if ( empty( $p['variation_id'] ) || ! is_numeric( $p['variation_id'] ) || (int) $p['variation_id'] < 1 ) {
					return new WP_Error( 'missing_variation_id', 'Which variation ID would you like to look up?' );
				}
				break;

			case 'update_variation_price':
				if ( empty( $p['variation_id'] ) || ! is_numeric( $p['variation_id'] ) || (int) $p['variation_id'] < 1 ) {
					return new WP_Error( 'missing_variation_id', 'Which variation would you like to update the price for?' );
				}
				$price = $p['price'] ?? $p['regular_price'] ?? null;
				if ( null === $price ) {
					return new WP_Error( 'missing_price', 'What should the new price be?' );
				}
				$price_val = (float) $price;
				if ( $price_val < 0 || $price_val > 999999 ) {
					$this->security_log( 'out_of_range', [ 'action' => $action, 'param' => 'price', 'value' => $price_val ] );
					return new WP_Error( 'invalid_price', 'Price must be between 0 and 999,999.' );
				}
				break;

			case 'update_variation_stock':
				if ( empty( $p['variation_id'] ) || ! is_numeric( $p['variation_id'] ) || (int) $p['variation_id'] < 1 ) {
					return new WP_Error( 'missing_variation_id', "Which variation's stock would you like to update?" );
				}
				if ( ! isset( $p['quantity'] ) && ! isset( $p['set_to'] ) ) {
					return new WP_Error( 'missing_quantity', 'What should the stock be set to?' );
				}
				$qty = (int) ( $p['quantity'] ?? $p['set_to'] );
				if ( $qty < 0 || $qty > 999999 ) {
					$this->security_log( 'out_of_range', [ 'action' => $action, 'param' => 'quantity', 'value' => $qty ] );
					return new WP_Error( 'invalid_quantity', 'Stock quantity must be between 0 and 999,999.' );
				}
				break;
		}
		return true;
	}

	private function security_log( string $event, array $context = [] ): void {
		$parts = [ '[WPPilot Security] ' . $event, 'user:' . get_current_user_id() ];
		foreach ( $context as $key => $value ) {
			$parts[] = $key . ':' . $value;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( implode( ' | ', $parts ) );
	}

	/**
	 * Robust JSON parsing — handles markdown fences and leading/trailing text.
	 *
	 * @param string $raw
	 * @return array|WP_Error
	 */
	private function parse_json_response( string $raw ): array|WP_Error {
		$data = json_decode( $raw, true );
		if ( is_array( $data ) ) {
			return $data;
		}

		$cleaned = preg_replace( '/^```(?:json)?\s*/i', '', trim( $raw ) );
		$cleaned = preg_replace( '/\s*```$/i', '', $cleaned );
		$data    = json_decode( $cleaned, true );
		if ( is_array( $data ) ) {
			return $data;
		}

		if ( preg_match( '/\{(?:[^{}]|(?R))*\}/x', $raw, $matches ) ) {
			$data = json_decode( $matches[0], true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		return new WP_Error( 'invalid_json', 'AI returned invalid JSON', [ 'response' => substr( $raw, 0, 300 ) ] );
	}

	private function map_wp_error( WP_Error $error ): array {
		$message = strtolower( $error->get_error_message() );

		if ( str_contains( $message, 'api key' ) || str_contains( $message, 'authentication' ) || str_contains( $message, 'unauthorized' ) ) {
			return $this->error( 'invalid_api_key', "Your API key isn't working. Check Settings → Connectors." );
		}
		if ( str_contains( $message, 'rate' ) || str_contains( $message, 'quota' ) || str_contains( $message, 'overload' ) || str_contains( $message, '529' ) ) {
			return $this->error( 'rate_limited', 'The AI provider is busy right now — please wait a moment and try again.' );
		}
		if ( str_contains( $message, 'timeout' ) || str_contains( $message, 'timed out' ) ) {
			return $this->error( 'timeout', 'The request timed out. Please try again.' );
		}
		if ( str_contains( $message, 'content filtering' ) || str_contains( $message, 'blocked by' ) || str_contains( $message, 'policy' ) ) {
			return $this->error( 'content_filtered', "That request was blocked by the AI provider's content policy. Try rephrasing." );
		}

		return $this->error( 'ai_error', 'AI error (' . $error->get_error_code() . '): ' . $error->get_error_message() );
	}

	private function error( string $code, string $message ): array {
		return [ 'type' => 'error', 'error_code' => $code, 'message' => $message ];
	}
}
