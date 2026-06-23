<?php
/**
 * Plugin Name: StoreHand AI Product Manager for WooCommerce
 * Plugin URI:  https://purelyplugins.com/storehand-ai-product-manager-for-woocommerce
 * Description: AI-powered WooCommerce assistant. Create and update products using natural language.
 * Version:     1.0.0
 * Requires at least: 7.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Recommends: ai-provider-for-anthropic, ai-provider-for-openai, ai-provider-for-google
 * Author:      Purely Plugins
 * Author URI:  https://purelyplugins.com
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: storehand-ai-product-manager-for-woocommerce
 *
 * @package WPPilot
 */

defined( 'ABSPATH' ) || exit;

define( 'WPPILOT_VERSION',  '1.0.0' );
define( 'WPPILOT_DIR',      plugin_dir_path( __FILE__ ) );
define( 'WPPILOT_URL',      plugin_dir_url( __FILE__ ) );
define( 'WPPILOT_BASENAME', plugin_basename( __FILE__ ) );

// ── Onboarding notice ─────────────────────────────────────────────────────────

/**
 * Set the onboarding transient when this plugin is activated.
 */
if ( ! function_exists( 'wppilot_on_activation' ) ) {
	function wppilot_on_activation( string $plugin ): void {
		if ( WPPILOT_BASENAME === $plugin ) {
			set_transient( 'wppilot_show_onboarding', true, WEEK_IN_SECONDS );
		}
	}
	add_action( 'activated_plugin', 'wppilot_on_activation' );
}

/**
 * Show a one-time dismissible onboarding notice in WP admin.
 * Skipped if the WordPress 7.0 AI platform is already active.
 *
 * Note: there is no reliable public API to confirm a specific Connector
 * is configured — wp_supports_ai() is used as a proxy. If it returns
 * true the user already has a working provider; skip the notice.
 * Future improvement: detect the specific Connector state when the API
 * is available.
 */
if ( ! function_exists( 'wppilot_admin_onboarding_notice' ) ) {
	function wppilot_admin_onboarding_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		if ( ! get_transient( 'wppilot_show_onboarding' ) ) {
			return;
		}

		// Provider already connected — clear the transient and stay silent.
		if ( function_exists( 'wp_supports_ai' ) && wp_supports_ai() ) {
			delete_transient( 'wppilot_show_onboarding' );
			return;
		}

		// Only show on WooCommerce admin pages or the plugins page.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}
		$is_woo_page = str_starts_with( $screen->id, 'woocommerce' )
			|| in_array( $screen->id, [ 'product', 'edit-product', 'plugins' ], true );
		if ( ! $is_woo_page ) {
			return;
		}

		$dismiss_url    = wp_nonce_url(
			admin_url( 'admin-post.php?action=wppilot_dismiss_onboarding' ),
			'wppilot_dismiss_onboarding'
		);
		$connectors_url = admin_url( 'options-general.php?page=connectors' );

		printf(
			'<div class="notice notice-info is-dismissible"><p>'
			. '<strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a> %5$s'
			. ' &nbsp;<a href="%6$s" class="button button-small">%7$s</a></p></div>',
			esc_html__( 'Thanks for installing StoreHand AI Product Manager for WooCommerce!', 'storehand-ai-product-manager-for-woocommerce' ),
			esc_html__( 'To get started, go to', 'storehand-ai-product-manager-for-woocommerce' ),
			esc_url( $connectors_url ),
			esc_html__( 'Settings → Connectors', 'storehand-ai-product-manager-for-woocommerce' ),
			esc_html__( 'and connect your AI provider, then open the StoreHand AI chat.', 'storehand-ai-product-manager-for-woocommerce' ),
			esc_url( $dismiss_url ),
			esc_html__( 'Got it', 'storehand-ai-product-manager-for-woocommerce' )
		);
	}
	add_action( 'admin_notices', 'wppilot_admin_onboarding_notice' );
}

/**
 * Handle the "Got it" dismiss link — delete the transient and redirect back.
 */
if ( ! function_exists( 'wppilot_handle_dismiss_onboarding' ) ) {
	function wppilot_handle_dismiss_onboarding(): void {
		check_admin_referer( 'wppilot_dismiss_onboarding' );

		if ( current_user_can( 'manage_woocommerce' ) ) {
			delete_transient( 'wppilot_show_onboarding' );
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
	add_action( 'admin_post_wppilot_dismiss_onboarding', 'wppilot_handle_dismiss_onboarding' );
}

// ── Plugin lifecycle hooks ────────────────────────────────────────────────────

/**
 * Activation — flush rewrite rules.
 */
if ( ! function_exists( 'wppilot_activate' ) ) {
	function wppilot_activate(): void {
		flush_rewrite_rules();
	}
	register_activation_hook( __FILE__, 'wppilot_activate' );
}

/**
 * Deactivation.
 */
if ( ! function_exists( 'wppilot_deactivate' ) ) {
	function wppilot_deactivate(): void {
		flush_rewrite_rules();
	}
	register_deactivation_hook( __FILE__, 'wppilot_deactivate' );
}

/**
 * Boot the plugin after all plugins are loaded.
 * Note: load_plugin_textdomain() omitted — WordPress.org handles translations automatically.
 */
function wppilot_init(): void {
	require_once WPPILOT_DIR . 'includes/class-wppilot-loader.php';
	WPPilot_Loader::instance()->init();
}
add_action( 'plugins_loaded', 'wppilot_init' );
