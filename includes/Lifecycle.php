<?php
/** Site-aware activation, deactivation and uninstall iteration. @package EPM */
namespace EPM;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Lifecycle {
	/** Visit sites in bounded batches and always restore the caller's context. */
	public static function each_site( callable $callback, bool $network_wide = false, bool $all_networks = false ): void {
		if ( ! is_multisite() || ! $network_wide ) {
			$callback();
			return;
		}
		$offset = 0;
		do {
			$args = [ 'fields' => 'ids', 'number' => 100, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC' ];
			if ( ! $all_networks ) {
				$args['network_id'] = get_current_network_id();
			}
			$ids = get_sites( $args );
			foreach ( $ids as $id ) {
				switch_to_blog( (int) $id );
				try {
					$callback();
				} finally {
					restore_current_blog();
				}
			}
			$offset += count( $ids );
		} while ( count( $ids ) === 100 );
	}

	/** Network sites rebuild their own rewrites on the next request. */
	public static function activate( bool $network_wide = false ): void {
		self::each_site(
			static function () use ( $network_wide ): void {
				self::activate_site( ! $network_wide );
			},
			$network_wide
		);
	}

	private static function activate_site( bool $flush ): void {
		Feed::register_endpoint();
		EpisodePostType::register();
		if ( $flush ) {
			flush_rewrite_rules();
		} else {
			delete_option( 'rewrite_rules' );
		}
		update_option( 'epm_version', EPM_VERSION );
		add_option( 'epm_activation_redirect', 1, '', false );
		Hosting::ensure_schedule();
		epm()->resume_interrupted_import();
	}

	public static function deactivate( bool $network_wide = false ): void {
		self::each_site(
			static function (): void {
				Hosting::unschedule_all();
				delete_option( 'rewrite_rules' );
			},
			$network_wide
		);
	}

	/** New network sites get the same initialization as existing sites. */
	public static function initialize_site( \WP_Site $site ): void {
		$active = get_network_option( (int) $site->network_id, 'active_sitewide_plugins', [] );
		if ( ! isset( $active[ plugin_basename( EPM_FILE ) ] ) ) {
			return;
		}
		switch_to_blog( (int) $site->blog_id );
		try {
			self::activate_site( false );
		} finally {
			restore_current_blog();
		}
	}
}
