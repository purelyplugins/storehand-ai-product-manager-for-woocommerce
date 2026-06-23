<?php
/**
 * Bootstraps all plugin components.
 *
 * @package WPPilot
 */

defined( 'ABSPATH' ) || exit;

class WPPilot_Loader {

	private static ?WPPilot_Loader $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init(): void {
		if ( ! $this->woocommerce_active() ) {
			add_action( 'admin_notices', [ $this, 'notice_requires_woocommerce' ] );
			return;
		}

		if ( ! $this->wp_ai_available() ) {
			add_action( 'admin_notices', [ $this, 'notice_requires_wp70' ] );
			return;
		}

		$this->load_dependencies();

		if ( is_admin() ) {
			( new WPPilot_Admin() )->init();
			( new WPPilot_Settings() )->init();
			add_action( 'admin_init', [ new WPPilot_Session(), 'cleanup_expired_pending' ] );
		}

		( new WPPilot_API() )->init();
	}

	private function woocommerce_active(): bool {
		return class_exists( 'WooCommerce' );
	}

	private function wp_ai_available(): bool {
		return function_exists( 'wp_ai_client_prompt' );
	}

	public function notice_requires_woocommerce(): void {
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'StoreHand AI Product Manager for WooCommerce', 'storehand-ai-product-manager-for-woocommerce' ),
			esc_html__( 'requires WooCommerce to be installed and active.', 'storehand-ai-product-manager-for-woocommerce' )
		);
	}

	public function notice_requires_wp70(): void {
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s <a href="%3$s" target="_blank" rel="noopener">%4$s</a> %5$s</p></div>',
			esc_html__( 'StoreHand AI Product Manager for WooCommerce', 'storehand-ai-product-manager-for-woocommerce' ),
			esc_html__( 'requires WordPress 7.0 or higher and the', 'storehand-ai-product-manager-for-woocommerce' ),
			esc_url( 'https://wordpress.org/plugins/ai-provider-for-anthropic/' ),
			esc_html__( 'AI Provider for Anthropic', 'storehand-ai-product-manager-for-woocommerce' ),
			esc_html__( 'plugin to be installed and active.', 'storehand-ai-product-manager-for-woocommerce' )
		);
	}

	private function load_dependencies(): void {
		require_once WPPILOT_DIR . 'includes/class-wppilot-session.php';
		require_once WPPILOT_DIR . 'includes/class-wppilot-settings.php';
		require_once WPPILOT_DIR . 'includes/class-wppilot-admin.php';
		require_once WPPILOT_DIR . 'includes/tools/class-wppilot-tool-executor.php';
		require_once WPPILOT_DIR . 'includes/class-wppilot-agent.php';
		require_once WPPILOT_DIR . 'includes/class-wppilot-api.php';
	}
}
