<?php
/**
 * Podcast-wide settings (Layer 1). Stored as a single option.
 *
 * Brand-independent: only podcast metadata, platform links and defaults.
 * Visual design lives in DesignSettings.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PodcastSettings {

	public const OPTION = 'epm_podcast_settings';

	/**
	 * Default values for every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'title'                => '',
			'description'          => '',
			'short_description'    => '',
			'author'               => '',
			'host'                 => '',
			'website_url'          => home_url( '/' ),
			'artwork_id'           => 0,
			'category'             => '',
			'subcategory'          => '',
			'language'             => get_locale(),
			'explicit'             => 'clean',
			'copyright'            => '',
			'owner_name'           => '',
			'owner_email'          => '',
			'type'                 => 'episodic',
			'default_artwork_id'   => 0,
			'default_author'       => '',
			'feed_limit'           => 500,
			// Redirect the address of an earlier WordPress podcast plugin
			// (/feed/podcast/, ?feed=podcast) to the feed.
			'feed_alias'           => false,
			'new_feed_url'         => '',
			'moved_in'             => false,
			'itunes_block'         => false,
			'complete'             => false,
			'locked'               => false,
			'funding_url'          => '',
			'funding_label'        => '',
			'auto_embed'           => true,
			'latest_cta_enabled'   => false,
			'latest_cta_label'     => '',
			'social_links'         => [],
			'platform_links'       => [],
		];
	}

	/**
	 * Wire settings API + sanitization.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Register the option with sanitization.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'epm_podcast_settings_group',
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => self::defaults(),
				'auth_callback'     => [ Capabilities::class, 'can_manage_podcast' ],
			]
		);

		// options.php otherwise demands manage_options; align it with the filter.
		add_filter( 'option_page_capability_epm_podcast_settings_group', [ Capabilities::class, 'manage_podcast' ] );
	}

	/**
	 * Get a single setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( string $key ) {
		$all      = $this->all();
		$defaults = self::defaults();

		return $all[ $key ] ?? $defaults[ $key ] ?? null;
	}

	/**
	 * Get all settings merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Sanitize the settings array.
	 *
	 * @param array $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return self::defaults();
		}

		$out = self::defaults();

		$out['title']             = sanitize_text_field( $input['title'] ?? '' );
		$out['author']            = sanitize_text_field( $input['author'] ?? '' );
		$out['host']              = sanitize_text_field( $input['host'] ?? '' );
		$out['language']          = sanitize_text_field( $input['language'] ?? '' );

		// Category: the settings select sends "Category::Subcategory"; plain
		// category names (pre-1.2 free text) are still accepted as-is.
		[ $category, $subcategory ] = Categories::decode( sanitize_text_field( $input['category'] ?? '' ) );
		if ( '' === $subcategory ) {
			$subcategory = sanitize_text_field( $input['subcategory'] ?? '' );
		}
		$out['category']    = $category;
		$out['subcategory'] = ( '' !== $subcategory && Categories::is_valid_subcategory( $category, $subcategory ) ) ? $subcategory : '';
		$out['copyright']         = sanitize_text_field( $input['copyright'] ?? '' );
		$out['owner_name']        = sanitize_text_field( $input['owner_name'] ?? '' );
		$out['owner_email']       = sanitize_email( $input['owner_email'] ?? '' );
		$out['default_author']    = sanitize_text_field( $input['default_author'] ?? '' );
		$out['description']       = wp_kses_post( $input['description'] ?? '' );
		$out['short_description'] = sanitize_textarea_field( $input['short_description'] ?? '' );
		$out['website_url']       = esc_url_raw( $input['website_url'] ?? '' );
		$out['artwork_id']        = absint( $input['artwork_id'] ?? 0 );
		$out['default_artwork_id'] = absint( $input['default_artwork_id'] ?? 0 );

		$explicit = $input['explicit'] ?? 'clean';
		$out['explicit'] = in_array( $explicit, [ 'clean', 'explicit' ], true ) ? $explicit : 'clean';

		$type = $input['type'] ?? 'episodic';
		$out['type'] = in_array( $type, [ 'episodic', 'serial' ], true ) ? $type : 'episodic';

		$feed_limit = absint( $input['feed_limit'] ?? 500 );
		$out['feed_limit'] = min( 10000, $feed_limit ); // 0 = unlimited.

		$out['latest_cta_enabled'] = ! empty( $input['latest_cta_enabled'] );
		$out['latest_cta_label']   = sanitize_text_field( $input['latest_cta_label'] ?? '' );

		$out['feed_alias']    = ! empty( $input['feed_alias'] );
		$out['new_feed_url']  = esc_url_raw( $input['new_feed_url'] ?? '' );
		$out['moved_in']      = ! empty( $input['moved_in'] );
		$out['itunes_block']  = ! empty( $input['itunes_block'] );
		$out['complete']      = ! empty( $input['complete'] );
		$out['locked']        = ! empty( $input['locked'] );
		$out['funding_url']   = esc_url_raw( $input['funding_url'] ?? '' );
		$out['funding_label'] = sanitize_text_field( $input['funding_label'] ?? '' );
		$out['auto_embed']    = ! empty( $input['auto_embed'] );

		$out['social_links']   = $this->sanitize_links( $input['social_links'] ?? [], __( 'Social links', 'elementor-podcast-manager' ) );
		$out['platform_links'] = $this->sanitize_links( $input['platform_links'] ?? [], __( 'Platform links', 'elementor-podcast-manager' ) );

		return $out;
	}

	/**
	 * Sanitize a list of label/url links.
	 *
	 * Rows with text but no usable http(s) address, and rows beyond the
	 * limit of 20, are not saved; the Settings screen says so instead of
	 * dropping them silently.
	 *
	 * @param mixed  $links Raw input.
	 * @param string $group Name of the list, for the notice ('' for none).
	 * @return array<int, array{label: string, url: string, service: string}>
	 */
	private function sanitize_links( $links, string $group = '' ): array {
		if ( ! is_array( $links ) ) {
			return [];
		}

		$clean   = [];
		$dropped = 0;
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$raw_url = trim( (string) ( $link['url'] ?? '' ) );
			$label   = sanitize_text_field( $link['label'] ?? '' );
			$url     = '' !== $raw_url ? esc_url_raw( $raw_url ) : '';
			if ( '' === $url ) {
				if ( '' !== $raw_url || '' !== $label ) {
					++$dropped;
				}
				continue;
			}
			$clean[] = [
				'label'   => $label,
				'url'     => $url,
				'service' => sanitize_key( $link['service'] ?? 'custom' ),
			];
		}

		$extra = max( 0, count( $clean ) - 20 );

		if ( '' !== $group && ( $dropped > 0 || $extra > 0 ) ) {
			$messages = [];
			if ( $dropped > 0 ) {
				$messages[] = sprintf(
					/* translators: 1: list name, e.g. "Platform links", 2: number of links */
					_n( '%1$s: %2$d link was not saved. Each link needs a web address that starts with https://.', '%1$s: %2$d links were not saved. Each link needs a web address that starts with https://.', $dropped, 'elementor-podcast-manager' ),
					$group,
					$dropped
				);
			}
			if ( $extra > 0 ) {
				$messages[] = sprintf(
					/* translators: 1: list name, 2: number of links that were cut */
					_n( '%1$s: only the first 20 links are kept, so %2$d link was removed.', '%1$s: only the first 20 links are kept, so %2$d links were removed.', $extra, 'elementor-podcast-manager' ),
					$group,
					$extra
				);
			}
			self::report( 'epm-links-' . sanitize_key( $group ), implode( ' ', $messages ) );
		}

		return array_slice( $clean, 0, 20 );
	}

	/**
	 * Show a warning on the Settings screen after saving (once per code).
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @return void
	 */
	private static function report( string $code, string $message ): void {
		// Outside the Settings screen (setup assistant, cron) there is no
		// place to show it.
		if ( ! function_exists( 'add_settings_error' ) || ! function_exists( 'get_settings_errors' ) ) {
			return;
		}

		foreach ( get_settings_errors( self::OPTION ) as $existing ) {
			if ( ( $existing['code'] ?? '' ) === $code ) {
				return;
			}
		}

		add_settings_error( self::OPTION, $code, $message, 'warning' );
	}

	/**
	 * Whether the minimum data required for a valid feed is present.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== (string) $this->get( 'title' );
	}

	/**
	 * Get the effective podcast author.
	 *
	 * Falls back through: author → default_author → site name.
	 *
	 * @return string
	 */
	public function effective_author(): string {
		$author = trim( (string) $this->get( 'author' ) );
		if ( '' !== $author ) {
			return $author;
		}

		$default = trim( (string) $this->get( 'default_author' ) );
		if ( '' !== $default ) {
			return $default;
		}

		return (string) get_bloginfo( 'name' );
	}

	/**
	 * Get the podcast artwork URL (with size control).
	 *
	 * @param string $size Image size.
	 * @return string
	 */
	public function artwork_url( string $size = 'large' ): string {
		$id = (int) $this->get( 'artwork_id' );
		if ( $id > 0 ) {
			$url = wp_get_attachment_image_url( $id, $size );
			if ( $url ) {
				return $url;
			}
		}

		return '';
	}
}
