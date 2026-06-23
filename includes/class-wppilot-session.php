<?php
/**
 * Conversation session management via WP transients.
 * Keeps last 20 messages per session (sliding window).
 * Also stores pending tool calls awaiting user confirmation.
 *
 * @package WPPilot
 */

defined( 'ABSPATH' ) || exit;

class WPPilot_Session {

	private const MAX_MESSAGES   = 20;
	private const TTL            = DAY_IN_SECONDS;
	private const PENDING_TTL    = 900; // 15 minutes — short enough that stale confirmations can't fire unexpectedly
	private const PREFIX         = 'wppilot_session_';
	private const PENDING_PREFIX = 'wppilot_pending_';

	// ── Conversation history ─────────────────────────────────────────────────────

	public function get( string $session_id ): array {
		$data = get_transient( self::PREFIX . sanitize_key( $session_id ) );
		return is_array( $data ) ? $data : [];
	}

	public function save( string $session_id, array $messages ): void {
		// Sliding window — keep system prompt + last MAX_MESSAGES.
		if ( count( $messages ) > self::MAX_MESSAGES ) {
			$messages = array_slice( $messages, count( $messages ) - self::MAX_MESSAGES );
		}
		set_transient( self::PREFIX . sanitize_key( $session_id ), $messages, self::TTL );
	}

	public function clear( string $session_id ): void {
		delete_transient( self::PREFIX . sanitize_key( $session_id ) );
		$this->clear_pending( $session_id );
	}

	// ── Pending tool call (awaiting confirmation) ────────────────────────────────

	public function save_pending( string $session_id, array $tool_call ): void {
		set_transient( self::PENDING_PREFIX . sanitize_key( $session_id ), $tool_call, self::PENDING_TTL );
	}

	public function get_pending( string $session_id ): ?array {
		$data = get_transient( self::PENDING_PREFIX . sanitize_key( $session_id ) );
		return is_array( $data ) ? $data : null;
	}

	public function clear_pending( string $session_id ): void {
		delete_transient( self::PENDING_PREFIX . sanitize_key( $session_id ) );
	}

	public function generate_id(): string {
		return wp_generate_uuid4();
	}

	// ── Garbage collection ───────────────────────────────────────────────────────

	/**
	 * Delete expired pending transients from the database.
	 *
	 * WordPress lazily deletes expired transients when they are next read,
	 * but if a user never returns after abandoning a confirmation the record
	 * stays in wp_options indefinitely. This method runs a targeted indexed
	 * query to clean them up. Hooked to admin_init so it runs on every admin
	 * page load — the query is fast because option_name is indexed.
	 */
	public function cleanup_expired_pending(): void {
		global $wpdb;

		$timeout_prefix = $wpdb->esc_like( '_transient_timeout_' . self::PENDING_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT REPLACE(option_name, '_transient_timeout_', '')
				 FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				 AND option_value < %d",
				$timeout_prefix,
				time()
			)
		);

		foreach ( $expired_keys as $transient_key ) {
			delete_transient( sanitize_key( $transient_key ) );
		}
	}
}
