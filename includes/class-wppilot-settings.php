<?php
/**
 * Settings page — AI provider configuration.
 *
 * In WordPress 7.0, API keys are managed via Settings → Connectors.
 * This page shows the Connectors status and retains a legacy Anthropic
 * API key field for backward compatibility with earlier installs.
 *
 * @package WPPilot
 */

defined( 'ABSPATH' ) || exit;

class WPPilot_Settings {

	public const OPTION_HELP_MODE = 'wppilot_help_mode_enabled';

	public function init(): void {
		add_action( 'admin_menu',            [ $this, 'register_page' ] );
		add_action( 'admin_init',            [ $this, 'register_fields' ] );
		add_filter( 'plugin_action_links_' . WPPILOT_BASENAME, [ $this, 'action_links' ] );
	}

	public function register_page(): void {
		add_options_page(
			__( 'StoreHand AI Product Manager Settings', 'storehand-ai-product-manager-for-woocommerce' ),
			__( 'StoreHand', 'storehand-ai-product-manager-for-woocommerce' ),
			'manage_options',
			'storehand-ai-product-manager-for-woocommerce',
			[ $this, 'render_page' ]
		);
	}

	public function register_fields(): void {
		register_setting( 'storehand-ai-product-manager-for-woocommerce', self::OPTION_HELP_MODE, [
			'type'              => 'string',
			'sanitize_callback' => static function( $value ) {
				return $value ? '1' : '0';
			},
		] );

		add_settings_section( 'wppilot_behaviour', __( 'Behaviour', 'storehand-ai-product-manager-for-woocommerce' ), '__return_empty_string', 'storehand-ai-product-manager-for-woocommerce' );

		add_settings_field(
			'wppilot_help_mode',
			__( 'General WooCommerce help questions', 'storehand-ai-product-manager-for-woocommerce' ),
			[ $this, 'render_help_mode_field' ],
			'storehand-ai-product-manager-for-woocommerce',
			'wppilot_behaviour'
		);

	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$connectors_url = admin_url( 'options-general.php?page=connectors' );
		$ai_ready       = function_exists( 'wp_supports_ai' ) && wp_supports_ai();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'StoreHand AI Product Manager Settings', 'storehand-ai-product-manager-for-woocommerce' ); ?></h1>

			<?php if ( $ai_ready ) : ?>
			<div class="notice notice-success inline" style="margin: 16px 0; padding: 12px 16px;">
				<p>
					<strong>✓ <?php esc_html_e( 'AI provider configured and ready.', 'storehand-ai-product-manager-for-woocommerce' ); ?></strong>
					<?php echo wp_kses(
						sprintf(
							/* translators: %s: URL to Connectors settings */
							__( 'Manage your API key and switch providers in <a href="%s">Settings → Connectors</a>.', 'storehand-ai-product-manager-for-woocommerce' ),
							esc_url( $connectors_url )
						),
						[ 'a' => [ 'href' => [] ] ]
					); ?>
				</p>
			</div>
			<?php else : ?>
			<div class="notice notice-warning inline" style="margin: 16px 0; padding: 12px 16px;">
				<p>
					<strong>⚠️ <?php esc_html_e( 'No AI provider configured.', 'storehand-ai-product-manager-for-woocommerce' ); ?></strong>
					<?php echo wp_kses(
						sprintf(
							/* translators: %s: URL to Connectors settings */
							__( 'StoreHand AI Product Manager requires an AI provider. Install a provider plugin and add your API key in <a href="%s">Settings → Connectors</a>.', 'storehand-ai-product-manager-for-woocommerce' ),
							esc_url( $connectors_url )
						),
						[ 'a' => [ 'href' => [] ] ]
					); ?>
				</p>
				<p style="margin-top: 8px;">
					<?php esc_html_e( 'Supported providers:', 'storehand-ai-product-manager-for-woocommerce' ); ?>
					<?php echo wp_kses(
						__( '<a href="https://wordpress.org/plugins/ai-provider-for-anthropic/" target="_blank" rel="noopener noreferrer">Anthropic Claude</a> (recommended — includes $5 free credit) · <a href="https://wordpress.org/plugins/ai-provider-for-openai/" target="_blank" rel="noopener noreferrer">OpenAI GPT</a> · <a href="https://wordpress.org/plugins/ai-provider-for-google/" target="_blank" rel="noopener noreferrer">Google Gemini</a>', 'storehand-ai-product-manager-for-woocommerce' ),
						[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
					); ?>
				</p>
			</div>
			<?php endif; ?>

			<div class="notice notice-warning inline" style="margin: 16px 0; padding: 14px 16px;">
				<p><strong>⚠️ <?php esc_html_e( 'Safety Notice', 'storehand-ai-product-manager-for-woocommerce' ); ?></strong></p>
				<p style="margin: 8px 0 6px;"><?php esc_html_e( 'StoreHand AI Product Manager makes real changes to your WooCommerce products based on AI interpretation of your requests.', 'storehand-ai-product-manager-for-woocommerce' ); ?></p>
				<p style="margin: 0 0 4px;"><strong><?php esc_html_e( 'Recommendations:', 'storehand-ai-product-manager-for-woocommerce' ); ?></strong></p>
				<ul style="margin: 0 0 8px 16px; list-style: disc;">
					<li><?php esc_html_e( 'Test on a staging site before using in production', 'storehand-ai-product-manager-for-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'Review all changes before confirming', 'storehand-ai-product-manager-for-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'Keep backups of your product data', 'storehand-ai-product-manager-for-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'Start with simple requests to understand how StoreHand AI Product Manager works', 'storehand-ai-product-manager-for-woocommerce' ); ?></li>
				</ul>
				<p style="margin: 0;"><?php esc_html_e( 'StoreHand AI Product Manager changes products to draft status instead of deleting them, making most actions reversible.', 'storehand-ai-product-manager-for-woocommerce' ); ?></p>
			</div>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'storehand-ai-product-manager-for-woocommerce' );
				do_settings_sections( 'storehand-ai-product-manager-for-woocommerce' );
				submit_button();
				?>
			</form>

			<p style="margin-top: 24px; color: #5f5e5a; font-size: 14px;">
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: wppilot ToS URL */
						__( 'By using StoreHand AI Product Manager, you agree to our <a href="%s" target="_blank" rel="noopener noreferrer">Terms of Service</a>. You also agree to your configured AI provider\'s terms and privacy policy. You acknowledge that product data is sent to your AI provider\'s servers for processing and that you are responsible for reviewing all AI-generated changes.', 'storehand-ai-product-manager-for-woocommerce' ),
						'https://purelyplugins.com/terms-and-conditions.html'
					),
					[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ]
				);
				?>
			</p>
		</div>
		<?php
	}

	public function render_help_mode_field(): void {
		$raw     = get_option( self::OPTION_HELP_MODE, '1' );
		$enabled = ( '0' !== (string) $raw );
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_HELP_MODE ); ?>"
				value="1"
				<?php checked( $enabled ); ?>
			/>
			<?php esc_html_e( 'Enable general WooCommerce help questions (beta)', 'storehand-ai-product-manager-for-woocommerce' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, StoreHand AI Product Manager can answer how-to questions about WooCommerce (e.g. "how do I set up a coupon?") in addition to managing products.', 'storehand-ai-product-manager-for-woocommerce' ); ?>
		</p>
		<?php
	}

	public function action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=storehand-ai-product-manager-for-woocommerce' ) ),
			esc_html__( 'Settings', 'storehand-ai-product-manager-for-woocommerce' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

}
