<?php
/** Optional first-party player measurements and durable Studio delivery. @package EPM */
namespace EPM;
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class StudioAnalytics {
	const OPTION = 'epm_studio_analytics';
	const CRON = 'epm_studio_analytics_sync';
	const ENDPOINT = 'https://studio-production-1f9b.up.railway.app/api/integrations/podcast';
	public function init(): void {
		add_action( 'init', [ $this, 'install' ] );
		add_action( 'rest_api_init', function () {
			register_rest_route( 'epm/v1', '/analytics/playback', [ 'methods' => 'POST', 'callback' => [ $this, 'receive' ], 'permission_callback' => '__return_true' ] );
		} );
		add_action( self::CRON, [ $this, 'sync' ] );
		add_action( 'admin_menu', function () { add_submenu_page( 'epm-dashboard', 'BUSINESS TUNING STUDIO', __( 'Studio Analytics', 'elementor-podcast-manager' ), 'manage_options', 'epm-studio-analytics', [ $this, 'page' ] ); }, 20 );
		add_action( 'admin_post_epm_studio_analytics', [ $this, 'save' ] );
	}
	public static function settings(): array { return (array) get_option( self::OPTION, [] ); }
	public static function enabled(): bool { return ! empty( self::settings()['enabled'] ); }
	public static function client_config(): array {
		return [ 'enabled' => self::enabled() && ! current_user_can( 'edit_posts' ), 'endpoint' => rest_url( 'epm/v1/analytics/playback' ) ];
	}
	public function install(): void {
		if ( '1' !== get_option( 'epm_studio_schema' ) ) {
			global $wpdb;
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset = $wpdb->get_charset_collate();
			dbDelta( "CREATE TABLE {$wpdb->prefix}epm_studio_sessions (
			 id varchar(64) NOT NULL,
			 day date NOT NULL,
			 seconds int unsigned NOT NULL DEFAULT 0,
			 PRIMARY KEY  (id),
			 KEY day (day)
			) ENGINE=InnoDB $charset;" );
			dbDelta( "CREATE TABLE {$wpdb->prefix}epm_studio_daily (
			 id varchar(64) NOT NULL,
			 guid varchar(512) NOT NULL,
			 day date NOT NULL,
			 plays int unsigned NOT NULL DEFAULT 0,
			 seconds int unsigned NOT NULL DEFAULT 0,
			 revision int unsigned NOT NULL DEFAULT 0,
			 synced_revision int unsigned NOT NULL DEFAULT 0,
			 PRIMARY KEY  (id),
			 KEY day (day)
			) ENGINE=InnoDB $charset;" );
			if ( ! $wpdb->last_error ) { update_option( 'epm_studio_schema', '1', false ); }
		}
		if ( self::enabled() && ! wp_next_scheduled( self::CRON ) ) { wp_schedule_event( time() + 60, 'hourly', self::CRON ); }
		if ( ! self::enabled() ) { wp_clear_scheduled_hook( self::CRON ); }
	}
	public function receive( \WP_REST_Request $request ) {
		if ( ! self::enabled() ) { return new \WP_Error( 'disabled', 'Messung deaktiviert.', [ 'status' => 403 ] ); }
		$origin = $request->get_header( 'origin' );
		$site = wp_parse_url( home_url() );
		$expected = $site['scheme'] . '://' . $site['host'] . ( isset( $site['port'] ) ? ':' . $site['port'] : '' );
		if ( $origin !== $expected || strlen( $request->get_body() ) > 2048 ) { return new \WP_Error( 'origin', 'Ungültige Anfrage.', [ 'status' => 403 ] ); }
		$b = $request->get_json_params();
		if ( ! is_array( $b ) || ! isset( $b['episodeId'], $b['session'], $b['seconds'], $b['day'], $b['consent'] ) || true !== $b['consent'] || ! is_int( $b['episodeId'] ) || ! is_int( $b['seconds'] ) || $b['seconds'] < 10 || $b['seconds'] > 86400 || ! is_string( $b['session'] ) || ! preg_match( '/^[a-f0-9]{32}$/D', $b['session'] ) || $b['day'] !== gmdate( 'Y-m-d' ) ) {
			return new \WP_Error( 'invalid', 'Ungültige Messwerte.', [ 'status' => 400 ] );
		}
		$post = get_post( $b['episodeId'] );
		if ( ! $post || 'podcast_episode' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) { return new \WP_Error( 'episode', 'Episode nicht öffentlich.', [ 'status' => 404 ] ); }
		// No raw IP is persisted: short-lived keyed digest only for abuse throttling.
		$key = 'epm_sa_' . hash_hmac( 'sha256', (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) . gmdate( 'YmdHi' ), wp_salt( 'nonce' ) );
		$rate = (int) get_transient( $key );
		if ( $rate >= 120 ) { return new \WP_Error( 'rate', 'Bitte später erneut versuchen.', [ 'status' => 429 ] ); }
		set_transient( $key, $rate + 1, 120 );
		return $this->record( Episodes::get_guid( $post->ID ), $b['day'], $b['session'], $b['seconds'] );
	}
	/** Cumulative counters: retries/reordered browser requests cannot double count. */
	public function record( string $guid, string $day, string $session, int $seconds ) {
		global $wpdb;
		$events = $wpdb->prefix . 'epm_studio_sessions';
		$daily = $wpdb->prefix . 'epm_studio_daily';
		$id = hash( 'sha256', $guid . '|' . $day );
		$sid = hash( 'sha256', $id . '|' . $session );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$this->query( $wpdb->prepare( "INSERT IGNORE INTO $events (id,day,seconds) VALUES (%s,%s,0)", $sid, $day ) );
			// Lock via no-op update, portable to WordPress' SQLite test adapter.
			$this->query( $wpdb->prepare( "UPDATE $events SET seconds=seconds WHERE id=%s", $sid ) );
			$old = (int) $wpdb->get_var( $wpdb->prepare( "SELECT seconds FROM $events WHERE id=%s", $sid ) );
			if ( $seconds > $old ) {
				$this->query( $wpdb->prepare( "INSERT IGNORE INTO $daily (id,guid,day) VALUES (%s,%s,%s)", $id, $guid, $day ) );
				$this->query( $wpdb->prepare( "UPDATE $daily SET seconds=seconds+%d, plays=plays+%d, revision=revision+1 WHERE id=%s", $seconds - $old, $old < 10 ? 1 : 0, $id ) );
				$this->query( $wpdb->prepare( "UPDATE $events SET seconds=%d WHERE id=%s", $seconds, $sid ) );
			}
			$this->query( 'COMMIT' );
			return [ 'ok' => true ];
		} catch ( \Throwable $e ) { $wpdb->query( 'ROLLBACK' ); return new \WP_Error( 'storage', 'Messung konnte nicht gespeichert werden.', [ 'status' => 503 ] ); }
	}
	private function query( string $sql ): void {
		global $wpdb;
		if ( false === $wpdb->query( $sql ) ) { throw new \RuntimeException( 'Analytics storage unavailable' ); }
	}
	public function sync(): void {
		$s = self::settings();
		if ( empty( $s['enabled'] ) || empty( $s['token'] ) ) { return; }
		global $wpdb;
		$table = $wpdb->prefix . 'epm_studio_daily';
		$rows = $wpdb->get_results( "SELECT * FROM $table WHERE revision > synced_revision ORDER BY day,id LIMIT 100", ARRAY_A );
		$payload = [ 'version' => 1, 'feedUrl' => Feed::url(), 'rows' => array_map( static function ( $r ) { return [ 'guid' => $r['guid'], 'day' => $r['day'], 'revision' => (int) $r['revision'], 'plays' => (int) $r['plays'], 'seconds' => (int) $r['seconds'] ]; }, $rows ) ];
		// Fixed destination. Token never follows a redirect or enters frontend output.
		$response = wp_remote_post( self::ENDPOINT, [ 'timeout' => 20, 'redirection' => 0, 'limit_response_size' => 8192, 'headers' => [ 'Authorization' => 'Bearer ' . $s['token'], 'Content-Type' => 'application/json' ], 'body' => wp_json_encode( $payload ) ] );
		$code = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
		$body = is_wp_error( $response ) ? [] : json_decode( wp_remote_retrieve_body( $response ), true );
		$ok = 200 === $code && is_array( $body ) && true === ( $body['ok'] ?? false ) && count( $rows ) === ( $body['accepted'] ?? -1 );
		if ( $ok ) {
			foreach ( $rows as $row ) { $wpdb->query( $wpdb->prepare( "UPDATE $table SET synced_revision=GREATEST(synced_revision,%d) WHERE id=%s", (int) $row['revision'], $row['id'] ) ); }
			// Old short-lived playback IDs are unnecessary; durable daily aggregates remain.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}epm_studio_sessions WHERE day < %s", gmdate( 'Y-m-d', time() - 35 * DAY_IN_SECONDS ) ) );
		}
		update_option( 'epm_studio_status', [ 'at' => gmdate( 'c' ), 'ok' => $ok, 'code' => $code, 'sent' => $ok ? count( $rows ) : 0 ], false );
	}
	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Insufficient permissions.', 'elementor-podcast-manager' ) ); }
		check_admin_referer( 'epm_studio_analytics' );
		$s = self::settings();
		if ( isset( $_POST['sync'] ) ) { $this->sync(); } else {
			$token = isset( $_POST['token'] ) ? trim( (string) wp_unslash( $_POST['token'] ) ) : '';
			if ( '' !== $token && ! preg_match( '/^epm_[a-f0-9]{64}$/D', $token ) ) { wp_die( esc_html__( 'Invalid connection key.', 'elementor-podcast-manager' ) ); }
			if ( '' !== $token ) { $s['token'] = $token; delete_option( 'epm_studio_status' ); }
			if ( isset( $_POST['disconnect'] ) ) { unset( $s['token'] ); delete_option( 'epm_studio_status' ); }
			$s['enabled'] = isset( $_POST['enabled'] ) && ! isset( $_POST['disconnect'] );
			update_option( self::OPTION, $s, false );
			$this->install();
		}
		wp_safe_redirect( admin_url( 'admin.php?page=epm-studio-analytics' ) ); exit;
	}
	public function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$s = self::settings(); $status = (array) get_option( 'epm_studio_status', [] );
		global $wpdb; $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}epm_studio_daily WHERE revision > synced_revision" );
		?>
		<div class="wrap"><h1>BUSINESS TUNING STUDIO</h1>
		<p><?php esc_html_e( 'Send daily website-player measurements to your Studio automatically. This does not include Spotify, Apple Podcasts, external downloads or unique listeners.', 'elementor-podcast-manager' ); ?></p>
		<p><?php echo esc_html( sprintf( __( 'Pending daily snapshots: %d', 'elementor-podcast-manager' ), $pending ) ); ?></p>
		<?php if ( $status ) : ?><div class="notice <?php echo ! empty( $status['ok'] ) ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( ! empty( $status['ok'] ) ? __( 'Last delivery succeeded.', 'elementor-podcast-manager' ) : __( 'Delivery failed. Check the key, Studio RSS import and network connection. Pending measurements are retained and retried.', 'elementor-podcast-manager' ) ); ?> <?php echo esc_html( $status['at'] ?? '' ); ?></p></div><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="epm_studio_analytics"><?php wp_nonce_field( 'epm_studio_analytics' ); ?>
		<p><label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>> <?php esc_html_e( 'Enable measurement and hourly delivery', 'elementor-podcast-manager' ); ?></label></p>
		<p><label for="epm-studio-token"><?php esc_html_e( 'Studio connection key', 'elementor-podcast-manager' ); ?></label><br><input id="epm-studio-token" name="token" type="password" autocomplete="new-password" class="regular-text" value=""><br><?php echo esc_html( empty( $s['token'] ) ? __( 'Create a key in Studio settings and paste it here.', 'elementor-podcast-manager' ) : __( 'A key is stored. Leave empty to keep it.', 'elementor-podcast-manager' ) ); ?></p>
		<p><?php esc_html_e( 'For Borlabs Cookie 3, create a statistics service with ID epm-studio-analytics. Its consent is detected automatically; no custom opt-in code is needed.', 'elementor-podcast-manager' ); ?></p>
		<p><?php esc_html_e( 'Measurement starts only after your consent manager grants window.epmAnalyticsConsent = true. It respects Do Not Track and Global Privacy Control. No analytics cookies or persistent browser identifiers are set. A playback counts after 10 seconds of actual playback; repeated page visits are separate sessions.', 'elementor-podcast-manager' ); ?></p>
		<?php submit_button( __( 'Save connection', 'elementor-podcast-manager' ) ); ?>
		<button class="button" name="sync" value="1"><?php esc_html_e( 'Send now / test connection', 'elementor-podcast-manager' ); ?></button>
		<button class="button" name="disconnect" value="1"><?php esc_html_e( 'Disconnect and stop measurement', 'elementor-podcast-manager' ); ?></button>
		</form></div>
		<?php
	}
}
