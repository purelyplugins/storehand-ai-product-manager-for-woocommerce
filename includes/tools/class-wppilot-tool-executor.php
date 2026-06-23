<?php
/**
 * Executes tool calls using native WP/WooCommerce PHP functions.
 * Running inside WP means we bypass all REST API hook issues.
 *
 * @package WPPilot
 */

defined( 'ABSPATH' ) || exit;

class WPPilot_Tool_Executor {

	/**
	 * Execute a tool call and return the result.
	 *
	 * @param string $tool_name  Tool identifier from the registry.
	 * @param array  $args       Arguments from the AI.
	 * @return array             Result passed back to AI.
	 */
	public function execute( string $tool_name, array $args ): array {
		$result = match ( $tool_name ) {
			'search_products'          => $this->search_products( $args ),
			'get_product'              => $this->get_product( $args ),
			'get_variation'            => $this->get_variation( $args ),
			'create_simple_product'    => $this->create_simple_product( $args ),
			'create_variable_product'  => $this->create_variable_product( $args ),
			'update_simple_product'    => $this->update_simple_product( $args ),
			'edit_product'             => $this->edit_product( $args ),
			'update_stock_level'       => $this->update_stock_level( $args ),
			'change_product_status'    => $this->change_product_status( $args ),
			'bulk_update_price'        => $this->bulk_update_price( $args ),
			'update_variation_price'   => $this->update_variation_price( $args ),
			'update_variation_stock'   => $this->update_variation_stock( $args ),
			default                    => [ 'success' => false, 'error' => "Unknown tool: {$tool_name}" ],
		};

		// Private methods may return WP_Error for structured type-guard failures.
		// Convert here so the rest of the stack always receives a plain array.
		if ( is_wp_error( $result ) ) {
			return [
				'success'    => false,
				'error_code' => $result->get_error_code(),
				'error'      => $result->get_error_message(),
			];
		}

		return $result;
	}

	// ── Capability check ────────────────────────────────────────────────────────

	private function check_write_capability(): bool {
		if ( ! current_user_can( 'edit_products' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[WPPilot Security] capability_denied | user:' . get_current_user_id() );
			return false;
		}
		return true;
	}

	private function change_product_status( array $args ): array|WP_Error {
		if ( ! $this->check_write_capability() ) {
			return [ 'success' => false, 'error' => 'You do not have permission to edit products.' ];
		}

		$product_id = absint( $args['product_id'] ?? 0 );
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			return [ 'success' => false, 'error' => "Product {$product_id} not found." ];
		}

		$allowed = [ 'publish', 'draft', 'private', 'pending' ];
		$status  = sanitize_key( $args['status'] ?? '' );

		if ( ! in_array( $status, $allowed, true ) ) {
			return [ 'success' => false, 'error' => "Invalid status \"{$status}\". Use: publish, draft, private, or pending." ];
		}

		$before = $product->get_status();
		$product->set_status( $status );
		$product->save();

		return [
			'success'      => true,
			'product_id'   => $product_id,
			'product_name' => $product->get_name(),
			'before'       => $before,
			'after'        => $status,
			'edit_url'     => admin_url( "post.php?post={$product_id}&action=edit" ),
			'message'      => sprintf( 'Status for "%s" (ID: %d): %s → %s', $product->get_name(), $product_id, $before, $status ),
		];
	}

	// ── Search / Read ───────────────────────────────────────────────────────────

	private function search_products( array $args ): array {
		$query = sanitize_text_field( $args['query'] ?? '' );

		if ( empty( $query ) ) {
			return [ 'success' => false, 'error' => 'No search query provided.' ];
		}

		$posts = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'any',
			's'              => $query,
			'posts_per_page' => 10,
			'fields'         => 'ids',
		] );

		if ( empty( $posts ) ) {
			return [ 'success' => true, 'products' => [], 'message' => "No products found matching \"{$query}\"." ];
		}

		$products = [];
		foreach ( $posts as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$products[] = [
				'product_id'   => $id,
				'title'        => $product->get_name(),
				'status'       => $product->get_status(),
				'price'        => $product->get_regular_price(),
				'sku'          => $product->get_sku(),
				'product_type' => $product->get_type(),
			];
		}

		return [
			'success'  => true,
			'products' => $products,
			'message'  => sprintf( 'Found %d product(s) matching "%s".', count( $products ), $query ),
		];
	}

	// ── Read ────────────────────────────────────────────────────────────────────

	private function get_product( array $args ): array {
		$product_id = absint( $args['product_id'] ?? 0 );
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			return [ 'success' => false, 'error' => "Product {$product_id} not found." ];
		}

		$category_ids = $product->get_category_ids();
		$categories   = $this->get_term_names( $category_ids, 'product_cat' );

		$tag_ids = $product->get_tag_ids();
		$tags    = $this->get_term_names( $tag_ids, 'product_tag' );

		$result = [
			'success'           => true,
			'product_id'        => $product_id,
			'product_type'      => $product->get_type(),
			'title'             => $product->get_name(),
			'status'            => $product->get_status(),
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'regular_price'     => $product->get_regular_price(),
			'sku'               => $product->get_sku(),
			'image_id'          => $product->get_image_id(),
			'manage_stock'      => $product->get_manage_stock(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'stock_status'      => $product->get_stock_status(),
			'categories'        => $categories,
			'tags'              => $tags,
			'edit_url'          => admin_url( "post.php?post={$product_id}&action=edit" ),
		];

		if ( $product->is_type( 'variable' ) ) {
			$result['variations'] = $this->get_variation_list( $product );
		}

		return $result;
	}

	// ── Simple Products ─────────────────────────────────────────────────────────

	private function create_simple_product( array $args ): array {
		if ( ! $this->check_write_capability() ) {
			return [ 'success' => false, 'error' => 'You do not have permission to edit products.' ];
		}
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return [ 'success' => false, 'error' => 'WooCommerce is not active.' ];
		}

		$product = new WC_Product_Simple();
		$product->set_name( sanitize_text_field( $args['name'] ) );
		$product->set_status( 'draft' );

		if ( ! empty( $args['description'] ) ) {
			$product->set_description( wp_kses_post( $args['description'] ) );
		}
		if ( ! empty( $args['short_description'] ) ) {
			$product->set_short_description( wp_kses_post( $args['short_description'] ) );
		}
		if ( isset( $args['regular_price'] ) ) {
			$product->set_regular_price( wc_format_decimal( $args['regular_price'] ) );
		}
		if ( isset( $args['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $args['manage_stock'] );
		}
		if ( isset( $args['stock_quantity'] ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $args['stock_quantity'] );
		}
		if ( ! empty( $args['sku'] ) ) {
			$product->set_sku( sanitize_text_field( $args['sku'] ) );
		}
		if ( ! empty( $args['categories'] ) ) {
			$product->set_category_ids( $this->get_or_create_term_ids( $args['categories'], 'product_cat' ) );
		}
		if ( ! empty( $args['tags'] ) ) {
			$product->set_tag_ids( $this->get_or_create_term_ids( $args['tags'], 'product_tag' ) );
		}
		if ( ! empty( $args['image_id'] ) ) {
			$product->set_image_id( (int) $args['image_id'] );
		}

		$product_id = $product->save();

		if ( ! $product_id ) {
			return [ 'success' => false, 'error' => 'Failed to create product.' ];
		}

		return [
			'success'      => true,
			'product_id'   => $product_id,
			'product_name' => sanitize_text_field( $args['name'] ),
			'edit_url'     => admin_url( "post.php?post={$product_id}&action=edit" ),
			'message'      => sprintf( 'Created product "%s" (ID: %d) as draft.', $args['name'], $product_id ),
		];
	}

	private function create_variable_product( array $args ): array {
		if ( ! $this->check_write_capability() ) {
			return [ 'success' => false, 'error' => 'You do not have permission to edit products.' ];
		}

		// ── 1. Validate ALL inputs before touching the database ────────────────────

		$name = sanitize_text_field( $args['name'] ?? '' );
		if ( '' === $name ) {
			return [ 'success' => false, 'error' => 'Product name is required.' ];
		}

		$attribute_name = sanitize_text_field( $args['attribute_name'] ?? '' );
		if ( '' === $attribute_name ) {
			return [ 'success' => false, 'error' => 'Attribute name is required (e.g. Size, Colour).' ];
		}

		$raw_variations = $args['variations'] ?? [];
		if ( ! is_array( $raw_variations ) || empty( $raw_variations ) ) {
			return [ 'success' => false, 'error' => 'At least one variation is required.' ];
		}

		if ( count( $raw_variations ) > 12 ) {
			return [ 'success' => false, 'error' => 'Maximum 12 variations are supported. Add more in WooCommerce admin after creation.' ];
		}

		$validated = [];
		foreach ( $raw_variations as $i => $var ) {
			$value = ucwords( strtolower( sanitize_text_field( $var['value'] ?? '' ) ) );
			if ( '' === $value ) {
				return [ 'success' => false, 'error' => 'Every variation must have a value (e.g. Small, Red).' ];
			}

			$raw_price = $var['price'] ?? null;
			if ( null === $raw_price || ! is_numeric( $raw_price ) || (float) $raw_price <= 0 ) {
				return [ 'success' => false, 'error' => "Variation \"{$value}\" is missing a valid price. All variations need a price before the product can be created." ];
			}
			if ( (float) $raw_price > 999999 ) {
				return [ 'success' => false, 'error' => "Price for \"{$value}\" must be between 0 and 999,999." ];
			}

			$validated[] = [
				'value'          => $value,
				'price'          => wc_format_decimal( $raw_price ),
				'sku'            => sanitize_text_field( $var['sku'] ?? '' ),
				'stock_quantity' => isset( $var['stock_quantity'] ) ? (int) $var['stock_quantity'] : null,
			];
		}

		// ── 2. Create and save parent product ──────────────────────────────────────

		$parent = new WC_Product_Variable();
		$parent->set_name( $name );
		$parent->set_status( 'draft' );

		if ( ! empty( $args['description'] ) ) {
			$parent->set_description( wp_kses_post( $args['description'] ) );
		}
		if ( ! empty( $args['short_description'] ) ) {
			$parent->set_short_description( wp_kses_post( $args['short_description'] ) );
		}
		if ( ! empty( $args['categories'] ) && is_array( $args['categories'] ) ) {
			$parent->set_category_ids( $this->get_or_create_term_ids( $args['categories'], 'product_cat' ) );
		}
		if ( ! empty( $args['tags'] ) && is_array( $args['tags'] ) ) {
			$parent->set_tag_ids( $this->get_or_create_term_ids( $args['tags'], 'product_tag' ) );
		}
		if ( ! empty( $args['image_id'] ) ) {
			$parent->set_image_id( (int) $args['image_id'] );
		}

		$parent_id = $parent->save();

		if ( ! $parent_id ) {
			return [ 'success' => false, 'error' => 'Failed to create variable product.' ];
		}

		// ── 3. Attach local attribute to parent (marked as used for variations) ────

		$attribute_values = array_column( $validated, 'value' );

		$attribute = new WC_Product_Attribute();
		$attribute->set_id( 0 ); // 0 = local attribute, not a global taxonomy
		$attribute->set_name( $attribute_name );
		$attribute->set_options( $attribute_values );
		$attribute->set_position( 0 );
		$attribute->set_visible( true );
		$attribute->set_variation( true );

		$parent->set_attributes( [ $attribute ] );
		$parent->save();

		// ── 4. Create each variation ───────────────────────────────────────────────

		// WooCommerce admin compares variation attribute values using exact string equality
		// against the parent's options array. set_attributes() calls sanitize_title()
		// internally (e.g. "Small" → "small"), which breaks the match. We save the
		// variation first without the attribute, then write the exact value via post meta.
		$attribute_key        = sanitize_title( $attribute_name );
		$created_variation_ids = [];

		foreach ( $validated as $index => $var_data ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $parent_id );
			$variation->set_status( 'publish' ); // variations must be publish to show on the frontend
			$variation->set_menu_order( $index );  // preserve the order the user specified
			$variation->set_regular_price( $var_data['price'] );

			if ( '' !== $var_data['sku'] ) {
				$variation->set_sku( $var_data['sku'] );
			}
			if ( null !== $var_data['stock_quantity'] ) {
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( $var_data['stock_quantity'] );
			}

			$var_id = $variation->save();

			if ( ! $var_id ) {
				// All-or-nothing: roll back every variation and the parent.
				foreach ( $created_variation_ids as $id ) {
					wp_delete_post( $id, true );
				}
				wp_delete_post( $parent_id, true );

				return [
					'success' => false,
					'error'   => sprintf( 'Failed to create variation "%s". No changes were saved.', $var_data['value'] ),
				];
			}

			// Write the attribute value directly so it exactly matches the parent's
			// options string — WooCommerce admin uses === comparison, not sanitize_title.
			update_post_meta( $var_id, 'attribute_' . $attribute_key, $var_data['value'] );

			$created_variation_ids[] = $var_id;
		}

		// ── 5. Sync variation attributes and price cache ───────────────────────────

		$fresh_parent = wc_get_product( $parent_id );
		if ( $fresh_parent ) {
			$fresh_parent->save();
		}
		wc_delete_product_transients( $parent_id );

		return [
			'success'         => true,
			'product_id'      => $parent_id,
			'product_name'    => $name,
			'variation_count' => count( $created_variation_ids ),
			'edit_url'        => admin_url( "post.php?post={$parent_id}&action=edit" ),
			'message'         => sprintf(
				'Created variable product "%s" (ID: %d) as draft with %d variation(s).',
				$name,
				$parent_id,
				count( $created_variation_ids )
			),
		];
	}

	private function update_simple_product( array $args ): array {
		$product_id = (int) ( $args['product_id'] ?? 0 );
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			return [ 'success' => false, 'error' => "Product {$product_id} not found." ];
		}

		if ( $product->is_type( 'variable' ) ) {
			return new WP_Error(
				'unsupported_for_variable',
				__( 'This product has multiple variations (sizes/colours). Use update_variation_price with a specific variation_id instead, or manage it directly in WooCommerce admin.', 'storehand-ai-product-manager-for-woocommerce' )
			);
		}

		if ( isset( $args['name'] ) )              $product->set_name( sanitize_text_field( $args['name'] ) );
		if ( isset( $args['description'] ) )       $product->set_description( wp_kses_post( $args['description'] ) );
		if ( isset( $args['short_description'] ) ) $product->set_short_description( wp_kses_post( $args['short_description'] ) );
		if ( isset( $args['regular_price'] ) )     $product->set_regular_price( wc_format_decimal( $args['regular_price'] ) );
		if ( isset( $args['manage_stock'] ) )      $product->set_manage_stock( (bool) $args['manage_stock'] );
		if ( isset( $args['stock_quantity'] ) )    $product->set_stock_quantity( (int) $args['stock_quantity'] );
		if ( isset( $args['sku'] ) )               $product->set_sku( sanitize_text_field( $args['sku'] ) );
		if ( isset( $args['status'] ) )            $product->set_status( sanitize_key( $args['status'] ) );
		if ( ! empty( $args['image_id'] ) )        $product->set_image_id( (int) $args['image_id'] );

		$product->save();

		return [
			'success'    => true,
			'product_id' => $product_id,
			'edit_url'   => admin_url( "post.php?post={$product_id}&action=edit" ),
			'message'    => sprintf( 'Updated product "%s" (ID: %d).', $product->get_name(), $product_id ),
		];
	}

	private function edit_product( array $args ): array|WP_Error {
		if ( ! $this->check_write_capability() ) {
			return [ 'success' => false, 'error' => 'You do not have permission to edit products.' ];
		}

		$product_id = absint( $args['product_id'] ?? 0 );
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			return [ 'success' => false, 'error' => "Product {$product_id} not found." ];
		}

		// Variable products support parent-level fields (title, description, image,
		// categories, tags) but not price/stock, which live on individual variations.
		if ( $product->is_type( 'variable' ) ) {
			if ( isset( $args['regular_price'] ) ) {
				return new WP_Error( 'unsupported_for_variable', __( 'Variable products do not have a single price. Use update_variation_price with a specific variation_id.', 'storehand-ai-product-manager-for-woocommerce' ) );
			}
			if ( isset( $args['stock_quantity'] ) ) {
				return new WP_Error( 'unsupported_for_variable', __( 'Variable products do not have a single stock level. Use update_variation_stock with a specific variation_id.', 'storehand-ai-product-manager-for-woocommerce' ) );
			}
		} elseif ( ! $product->is_type( 'simple' ) ) {
			return new WP_Error( 'unsupported_product_type', __( 'Only simple and variable products are supported.', 'storehand-ai-product-manager-for-woocommerce' ) );
		}

		$editable = [ 'title', 'description', 'short_description', 'sku', 'regular_price', 'stock_quantity', 'stock_status', 'categories', 'tags', 'image_id' ];
		if ( empty( array_intersect( array_keys( $args ), $editable ) ) ) {
			return [ 'success' => false, 'error' => 'No editable fields provided.' ];
		}

		$changes = [];

		if ( isset( $args['title'] ) ) {
			$before = $product->get_name();
			$product->set_name( sanitize_text_field( $args['title'] ) );
			$changes['title'] = [ 'before' => $before, 'after' => $args['title'] ];
		}
		if ( isset( $args['description'] ) ) {
			$before = wp_strip_all_tags( $product->get_description() );
			$before = strlen( $before ) > 80 ? substr( $before, 0, 80 ) . '…' : $before;
			$product->set_description( wp_kses_post( $args['description'] ) );
			$changes['description'] = [ 'before' => $before ?: '(none)', 'after' => '(updated)' ];
		}
		if ( isset( $args['short_description'] ) ) {
			$before = wp_strip_all_tags( $product->get_short_description() );
			$product->set_short_description( wp_kses_post( $args['short_description'] ) );
			$changes['short_description'] = [ 'before' => $before ?: '(none)', 'after' => $args['short_description'] ];
		}
		if ( isset( $args['sku'] ) ) {
			$before = $product->get_sku();
			$product->set_sku( sanitize_text_field( $args['sku'] ) );
			$changes['sku'] = [ 'before' => $before ?: '(none)', 'after' => $args['sku'] ];
		}
		if ( isset( $args['regular_price'] ) ) {
			$before = $product->get_regular_price();
			$product->set_regular_price( wc_format_decimal( $args['regular_price'] ) );
			$changes['regular_price'] = [ 'before' => $before ?: '(none)', 'after' => $args['regular_price'] ];
		}
		if ( isset( $args['stock_quantity'] ) ) {
			$before = $product->get_stock_quantity();
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $args['stock_quantity'] );
			$changes['stock_quantity'] = [ 'before' => $before ?? '(not tracked)', 'after' => (int) $args['stock_quantity'] ];
		}
		if ( isset( $args['stock_status'] ) ) {
			$before = $product->get_stock_status();
			$product->set_stock_status( sanitize_key( $args['stock_status'] ) );
			$changes['stock_status'] = [ 'before' => $before, 'after' => $args['stock_status'] ];
		}
		if ( isset( $args['categories'] ) ) {
			$before_names = $this->get_term_names( $product->get_category_ids(), 'product_cat' );
			$product->set_category_ids( $this->get_or_create_term_ids( $args['categories'], 'product_cat' ) );
			$changes['categories'] = [ 'before' => $before_names ?: '(none)', 'after' => implode( ', ', $args['categories'] ) ];
		}
		if ( isset( $args['tags'] ) ) {
			$before_names = $this->get_term_names( $product->get_tag_ids(), 'product_tag' );
			$product->set_tag_ids( $this->get_or_create_term_ids( $args['tags'], 'product_tag' ) );
			$changes['tags'] = [ 'before' => $before_names ?: '(none)', 'after' => implode( ', ', $args['tags'] ) ];
		}
		if ( ! empty( $args['image_id'] ) ) {
			$product->set_image_id( (int) $args['image_id'] );
			$changes['image'] = [ 'before' => '(previous)', 'after' => '(updated)' ];
		}

		$product->save();

		$summary_parts = [];
		foreach ( $changes as $field => $vals ) {
			$summary_parts[] = str_replace( '_', ' ', $field ) . ': "' . $vals['before'] . '" → "' . $vals['after'] . '"';
		}

		return [
			'success'      => true,
			'product_id'   => $product_id,
			'product_name' => $product->get_name(),
			'changes'      => $changes,
			'edit_url'     => admin_url( "post.php?post={$product_id}&action=edit" ),
			'message'      => sprintf( 'Updated "%s" (ID: %d): %s', $product->get_name(), $product_id, implode( '; ', $summary_parts ) ),
		];
	}

	private function update_stock_level( array $args ): array|WP_Error {
		if ( ! $this->check_write_capability() ) {
			return [ 'success' => false, 'error' => 'You do not have permission to edit products.' ];
		}

		$product_id = absint( $args['product_id'] ?? 0 );
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			return [ 'success' => false, 'error' => "Product {$product_id} not found." ];
		}

		if ( $product->get_type() !== 'simple' ) {
			return new WP_Error(
				'unsupported_for_variable',
				__( 'Variable products do not have a single stock level — stock is managed per variation. Use update_variation_stock with a specific variation_id, or update stock in WooCommerce admin under the product Variations tab.', 'storehand-ai-product-manager-for-woocommerce' )
			);
		}

		$before = (int) ( $product->get_stock_quantity() ?? 0 );

		if ( isset( $args['set_to'] ) ) {
			$new_qty = (int) $args['set_to'];
		} elseif ( isset( $args['increase_by'] ) ) {
			$new_qty = $before + (int) $args['increase_by'];
		} else {
			return [ 'success' => false, 'error' => 'Provide either set_to or increase_by.' ];
		}

		$product->set_manage_stock( true );
		$product->set_stock_quantity( $new_qty );
		$product->save();

		return [
			'success'      => true,
			'product_id'   => $product_id,
			'product_name' => $product->get_name(),
			'before'       => $before,
			'after'        => $new_qty,
			'edit_url'     => admin_url( "post.php?post={$product_id}&action=edit" ),
			'message'      => sprintf( 'Stock for "%s" (ID: %d): %d → %d', $product->get_name(), $product_id, $before, $new_qty ),
		];
	}

	private function bulk_update_price( array $args ): array {
		if ( ! $this->check_write_capability() ) {
			return [ 'success' => false, 'error' => 'You do not have permission to edit products.' ];
		}

		$percentage = (float) ( $args['percentage'] ?? 0 );
		$category   = sanitize_text_field( $args['category'] ?? '' );

		if ( 0.0 === $percentage ) {
			return [ 'success' => false, 'error' => 'Percentage must not be zero.' ];
		}

		$query_args = [
			'status' => [ 'publish', 'draft', 'private', 'pending' ],
			'type'   => [ 'simple', 'variable' ],
			'limit'  => 500,
		];

		if ( $category ) {
			$term = get_term_by( 'name', $category, 'product_cat' );
			if ( $term ) {
				$query_args['category'] = [ $term->slug ];
			} else {
				return [ 'success' => false, 'error' => "Category \"{$category}\" not found." ];
			}
		}

		$products   = wc_get_products( $query_args );
		$multiplier = 1 + ( $percentage / 100 );
		$updated           = 0;
		$skipped           = 0;
		$products_affected = 0;

		foreach ( $products as $product ) {
			if ( $product->is_type( 'variable' ) ) {
				$products_affected++;
				foreach ( $product->get_children() as $variation_id ) {
					$variation = wc_get_product( $variation_id );
					if ( ! $variation ) {
						continue;
					}
					$old_price = (float) $variation->get_regular_price();
					if ( $old_price <= 0 ) {
						$skipped++;
						continue;
					}
					$new_price = round( $old_price * $multiplier, 2 );
					$variation->set_regular_price( (string) $new_price );
					$variation->save();
					$updated++;
				}
			} else {
				$old_price = (float) $product->get_regular_price();
				if ( $old_price <= 0 ) {
					$skipped++;
					continue;
				}
				$new_price = round( $old_price * $multiplier, 2 );
				$product->set_regular_price( (string) $new_price );
				$product->save();
				$updated++;
				$products_affected++;
			}
		}

		$direction = $percentage > 0 ? 'increased' : 'decreased';
		$pct_label = abs( $percentage ) . '%';
		$note      = $skipped ? " ({$skipped} skipped — no price set)" : '';

		if ( $updated > $products_affected ) {
			$summary = "{$updated} price updates across {$products_affected} products";
		} else {
			$summary = "{$updated} product(s)";
		}

		return [
			'success'           => true,
			'updated'           => $updated,
			'skipped'           => $skipped,
			'products_affected' => $products_affected,
			'message'           => "Prices {$direction} by {$pct_label} for {$summary}{$note}.",
		];
	}

	// ── Variations ──────────────────────────────────────────────────────────────

	private function get_variation( array $args ): array {
		$variation_id = absint( $args['variation_id'] ?? 0 );
		$variation    = wc_get_product( $variation_id );

		if ( ! $variation ) {
			return [ 'success' => false, 'error' => "Variation {$variation_id} not found." ];
		}

		if ( $variation->get_type() !== 'variation' ) {
			return [ 'success' => false, 'error' => "ID {$variation_id} is not a product variation." ];
		}

		$parent_id = $variation->get_parent_id();
		$parent    = wc_get_product( $parent_id );

		return [
			'success'        => true,
			'variation_id'   => $variation_id,
			'parent_id'      => $parent_id,
			'parent_name'    => $parent ? $parent->get_name() : '',
			'attributes'     => $this->readable_variation_attributes( $variation ),
			'regular_price'  => $variation->get_regular_price(),
			'sku'            => $variation->get_sku(),
			'manage_stock'   => $variation->get_manage_stock(),
			'stock_quantity' => $variation->get_stock_quantity(),
			'stock_status'   => $variation->get_stock_status(),
			'edit_url'       => admin_url( "post.php?post={$parent_id}&action=edit" ),
		];
	}

	private function update_variation_price( array $args ): array|WP_Error {
		if ( ! $this->check_write_capability() ) {
			return [ 'success' => false, 'error' => 'You do not have permission to edit products.' ];
		}

		$variation_id = absint( $args['variation_id'] ?? 0 );
		$variation    = wc_get_product( $variation_id );

		if ( ! $variation ) {
			return [ 'success' => false, 'error' => "Variation {$variation_id} not found." ];
		}

		if ( $variation->get_type() !== 'variation' ) {
			return new WP_Error( 'not_a_variation', "ID {$variation_id} is not a product variation. Use update_price for simple products." );
		}

		$price = wc_format_decimal( $args['price'] ?? $args['regular_price'] ?? '' );
		if ( '' === $price ) {
			return [ 'success' => false, 'error' => 'No price provided.' ];
		}

		$before      = $variation->get_regular_price();
		$parent_id   = $variation->get_parent_id();
		$parent      = wc_get_product( $parent_id );
		$parent_name = $parent ? $parent->get_name() : '';
		$attrs       = implode( ', ', array_filter( $this->readable_variation_attributes( $variation ) ) );

		$variation->set_regular_price( $price );
		$variation->save();

		return [
			'success'      => true,
			'variation_id' => $variation_id,
			'parent_name'  => $parent_name,
			'attributes'   => $attrs,
			'before'       => $before,
			'after'        => $price,
			'edit_url'     => admin_url( "post.php?post={$parent_id}&action=edit" ),
			'message'      => sprintf( 'Price for %s (%s): %s → %s', $parent_name, $attrs ?: "variation #{$variation_id}", $before, $price ),
		];
	}

	private function update_variation_stock( array $args ): array|WP_Error {
		if ( ! $this->check_write_capability() ) {
			return [ 'success' => false, 'error' => 'You do not have permission to edit products.' ];
		}

		$variation_id = absint( $args['variation_id'] ?? 0 );
		$variation    = wc_get_product( $variation_id );

		if ( ! $variation ) {
			return [ 'success' => false, 'error' => "Variation {$variation_id} not found." ];
		}

		if ( $variation->get_type() !== 'variation' ) {
			return new WP_Error( 'not_a_variation', "ID {$variation_id} is not a product variation. Use update_stock for simple products." );
		}

		$before = (int) ( $variation->get_stock_quantity() ?? 0 );

		if ( isset( $args['set_to'] ) ) {
			$new_qty = (int) $args['set_to'];
		} elseif ( isset( $args['quantity'] ) ) {
			$new_qty = (int) $args['quantity'];
		} else {
			return [ 'success' => false, 'error' => 'Provide quantity.' ];
		}

		$parent_id   = $variation->get_parent_id();
		$parent      = wc_get_product( $parent_id );
		$parent_name = $parent ? $parent->get_name() : '';
		$attrs       = implode( ', ', array_filter( $this->readable_variation_attributes( $variation ) ) );

		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( $new_qty );
		$variation->save();

		return [
			'success'      => true,
			'variation_id' => $variation_id,
			'parent_name'  => $parent_name,
			'attributes'   => $attrs,
			'before'       => $before,
			'after'        => $new_qty,
			'edit_url'     => admin_url( "post.php?post={$parent_id}&action=edit" ),
			'message'      => sprintf( 'Stock for %s (%s): %d → %d', $parent_name, $attrs ?: "variation #{$variation_id}", $before, $new_qty ),
		];
	}

	// ── Helpers ─────────────────────────────────────────────────────────────────

	private function get_term_names( array $ids, string $taxonomy ): string {
		$names = [];
		foreach ( $ids as $id ) {
			$term = get_term( $id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}
		return implode( ', ', $names );
	}

	/**
	 * Build a human-readable attribute array for a variation.
	 * Returns e.g. ['Color' => 'Red', 'Size' => 'Large'].
	 */
	private function readable_variation_attributes( WC_Product_Variation $variation ): array {
		$result = [];
		foreach ( $variation->get_variation_attributes() as $tax_key => $term_slug ) {
			$taxonomy = str_replace( 'attribute_', '', $tax_key );
			$label    = wc_attribute_label( $taxonomy );
			if ( taxonomy_exists( $taxonomy ) ) {
				$term  = get_term_by( 'slug', $term_slug, $taxonomy );
				$value = ( $term && ! is_wp_error( $term ) ) ? $term->name : $term_slug;
			} else {
				$value = $term_slug;
			}
			$result[ $label ] = $value ?: 'Any';
		}
		return $result;
	}

	/**
	 * Return a flat list of variation summaries for a variable product.
	 */
	private function get_variation_list( WC_Product_Variable $parent ): array {
		$list = [];
		foreach ( $parent->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation ) {
				continue;
			}
			$list[] = [
				'variation_id'   => $variation_id,
				'attributes'     => $this->readable_variation_attributes( $variation ),
				'price'          => $variation->get_regular_price(),
				'stock_quantity' => $variation->get_stock_quantity(),
				'sku'            => $variation->get_sku(),
			];
		}
		return $list;
	}

	private function get_or_create_term_ids( array $names, string $taxonomy ): array {
		$ids = [];
		foreach ( $names as $name ) {
			$name = sanitize_text_field( $name );
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( $term ) {
				$ids[] = $term->term_id;
			}
		}
		return $ids;
	}
}
