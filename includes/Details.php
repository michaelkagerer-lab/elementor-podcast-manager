<?php
/**
 * "Details shown by default" (Layer 2): which parts players and episode
 * lists show when a widget or shortcode does not choose for itself.
 *
 * This is a separate inheritance from the design tokens. Podcast → Design
 * colors, sizes and fonts (and a widget's "Use Podcast → Design styles")
 * govern looks only; the details decide which parts are rendered.
 *
 * Resolution for every detail of a context:
 *
 *   1. an explicit local value (a widget's Show/Hide, a shortcode
 *      attribute that is present, the episode page filter afterwards);
 *   2. the site's "Details shown by default" for that context
 *      (Podcast → Design; only details the site has set);
 *   3. the built-in default of that consumer: exactly what 1.3.0 showed,
 *      so a site that never sets details renders as before.
 *
 * Contexts: player (Podcast Player widget, [podcast_player]), latest
 * (Latest Episode widget, [podcast_latest]), list (Episode List widget,
 * [podcast_episodes]) and episode_page (the automatic episode page). The
 * embed card is not a context: its contents are fixed by its 200 px frame
 * (filter epm_embed_player_args).
 *
 * Stored in the design option (DesignSettings) as sparse maps:
 *   details           => [ context => [ detail => bool ] ]  active
 *   details_suggested => same shape: preset maps stored by 1.1–1.3 (never
 *                        effective there), offered on the Design screen
 *                        until applied or dismissed.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Details {

	/**
	 * Contexts, in the order the Design screen lists them.
	 */
	public const CONTEXTS = [ 'player', 'latest', 'list', 'episode_page' ];

	/**
	 * Details of the player contexts, in display order.
	 */
	private const PLAYER = [
		'show_artwork',
		'show_episode_label',
		'show_episode_number',
		'show_season',
		'show_title',
		'show_guest',
		'show_description',
		'show_date',
		'show_duration',
		'show_playback_speed',
		'show_skip_backward',
		'show_skip_forward',
		'show_volume',
		'show_download',
		'show_share',
		'show_chapters_link',
		'show_platform_links',
	];

	/**
	 * Details of a context, in display order.
	 *
	 * @param string $context Context.
	 * @return string[]
	 */
	public static function flags( string $context ): array {
		switch ( $context ) {
			case 'player':
			case 'latest':
				return self::PLAYER;
			case 'episode_page':
				// The theme prints the episode title as the page heading.
				return array_values( array_diff( self::PLAYER, [ 'show_title' ] ) );
			case 'list':
				return [ 'show_artwork', 'show_episode_number', 'show_title', 'show_guest', 'show_excerpt', 'show_date', 'show_duration', 'show_play_button', 'show_topics' ];
		}

		return [];
	}

	/**
	 * Built-in defaults: what 1.3.0 showed. Consumers that showed something
	 * else than the widget of their context keep their own values:
	 * [podcast_latest] hid the date ('shortcode'), and [podcast_episodes]
	 * in a row layout hid the excerpt ('shortcode-rows').
	 *
	 * @param string $context  Context.
	 * @param string $consumer '' (widget), 'shortcode' or 'shortcode-rows'.
	 * @return array<string, bool>
	 */
	public static function neutral( string $context, string $consumer = '' ): array {
		$player = [
			'show_artwork'        => true,
			'show_episode_label'  => true,
			'show_episode_number' => false,
			'show_season'         => false,
			'show_title'          => true,
			'show_guest'          => true,
			'show_description'    => false,
			'show_date'           => false,
			'show_duration'       => true,
			'show_playback_speed' => true,
			'show_skip_backward'  => true,
			'show_skip_forward'   => true,
			'show_volume'         => true,
			'show_download'       => false,
			'show_share'          => true,
			'show_chapters_link'  => false,
			'show_platform_links' => false,
		];

		switch ( $context ) {
			case 'player':
				return $player;

			case 'latest':
				$latest                     = $player;
				$latest['show_description'] = true;
				$latest['show_date']        = 'shortcode' !== $consumer;
				return $latest;

			case 'episode_page':
				$page = $player;
				unset( $page['show_title'] );
				$page['show_episode_number'] = true;
				$page['show_date']           = true;
				$page['show_download']       = true;
				return $page;

			case 'list':
				return [
					'show_artwork'        => true,
					'show_episode_number' => true,
					'show_title'          => true,
					'show_guest'          => true,
					'show_excerpt'        => 'shortcode-rows' !== $consumer,
					'show_date'           => true,
					'show_duration'       => true,
					'show_play_button'    => true,
					'show_topics'         => false,
				];
		}

		return [];
	}

	/**
	 * Human label of a detail.
	 *
	 * @param string $flag Detail key, e.g. show_volume.
	 * @return string
	 */
	public static function label( string $flag ): string {
		$labels = [
			'show_artwork'        => __( 'Artwork', 'elementor-podcast-manager' ),
			'show_episode_label'  => __( 'Episode label', 'elementor-podcast-manager' ),
			'show_episode_number' => __( 'Episode number', 'elementor-podcast-manager' ),
			'show_season'         => __( 'Season', 'elementor-podcast-manager' ),
			'show_title'          => __( 'Title', 'elementor-podcast-manager' ),
			'show_guest'          => __( 'Guest', 'elementor-podcast-manager' ),
			'show_description'    => __( 'Description', 'elementor-podcast-manager' ),
			'show_excerpt'        => __( 'Excerpt', 'elementor-podcast-manager' ),
			'show_date'           => __( 'Date', 'elementor-podcast-manager' ),
			'show_duration'       => __( 'Duration', 'elementor-podcast-manager' ),
			'show_playback_speed' => __( 'Playback speed', 'elementor-podcast-manager' ),
			'show_skip_backward'  => __( 'Skip back 15 seconds', 'elementor-podcast-manager' ),
			'show_skip_forward'   => __( 'Skip forward 30 seconds', 'elementor-podcast-manager' ),
			'show_volume'         => __( 'Volume slider', 'elementor-podcast-manager' ),
			'show_download'       => __( 'Download button', 'elementor-podcast-manager' ),
			'show_share'          => __( 'Share menu', 'elementor-podcast-manager' ),
			'show_chapters_link'  => __( 'Chapters', 'elementor-podcast-manager' ),
			'show_platform_links' => __( 'Platform links', 'elementor-podcast-manager' ),
			'show_play_button'    => __( 'Play button', 'elementor-podcast-manager' ),
			'show_topics'         => __( 'Topics', 'elementor-podcast-manager' ),
		];

		return $labels[ $flag ] ?? ucfirst( trim( str_replace( '_', ' ', (string) preg_replace( '/^show_/', '', $flag ) ) ) );
	}

	/**
	 * Human label of a context.
	 *
	 * @param string $context Context.
	 * @return string
	 */
	public static function context_label( string $context ): string {
		$labels = [
			'player'       => __( 'Player', 'elementor-podcast-manager' ),
			'latest'       => __( 'Latest episode', 'elementor-podcast-manager' ),
			'list'         => __( 'Episode lists', 'elementor-podcast-manager' ),
			'episode_page' => __( 'Episode pages', 'elementor-podcast-manager' ),
		];

		return $labels[ $context ] ?? $context;
	}

	/**
	 * Who uses a context (shown on the Design screen).
	 *
	 * @param string $context Context.
	 * @return string
	 */
	public static function context_help( string $context ): string {
		$help = [
			'player'       => __( 'Podcast Player widget and [podcast_player].', 'elementor-podcast-manager' ),
			'latest'       => __( 'Latest Episode widget and [podcast_latest].', 'elementor-podcast-manager' ),
			'list'         => __( 'Episode List widget and [podcast_episodes].', 'elementor-podcast-manager' ),
			'episode_page' => __( 'The player added to every episode page automatically.', 'elementor-podcast-manager' ),
		];

		return $help[ $context ] ?? '';
	}

	/**
	 * Keep only known contexts and details, with boolean values.
	 *
	 * @param mixed $value Raw map.
	 * @return array<string, array<string, bool>>
	 */
	public static function sanitize_map( $value ): array {
		$out = [];
		if ( ! is_array( $value ) ) {
			return $out;
		}

		foreach ( self::CONTEXTS as $context ) {
			if ( ! isset( $value[ $context ] ) || ! is_array( $value[ $context ] ) ) {
				continue;
			}
			foreach ( self::flags( $context ) as $flag ) {
				if ( array_key_exists( $flag, $value[ $context ] ) && is_scalar( $value[ $context ][ $flag ] ) ) {
					$out[ $context ][ $flag ] = self::to_bool( $value[ $context ][ $flag ] );
				}
			}
		}

		return $out;
	}

	/**
	 * Coerce a stored, submitted or imported value to boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 != $value; // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
		}

		return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes', 'on', 'show' ], true );
	}

	/**
	 * The site's details for a context (only the details it has set).
	 *
	 * @param string $context Context.
	 * @return array<string, bool>
	 */
	public static function site( string $context ): array {
		$details = self::sanitize_map( epm()->design->get( 'details' ) );

		return $details[ $context ] ?? [];
	}

	/**
	 * Resolve every detail of a context for one consumer.
	 *
	 * @param string                    $context  Context.
	 * @param array<string, bool|null>  $explicit Local choices; null (or a
	 *                                            missing key) inherits.
	 * @param string                    $consumer See neutral().
	 * @return array<string, bool>
	 */
	public static function resolve( string $context, array $explicit = [], string $consumer = '' ): array {
		$site = self::site( $context );
		$out  = [];

		foreach ( self::neutral( $context, $consumer ) as $flag => $default ) {
			if ( isset( $explicit[ $flag ] ) ) {
				$out[ $flag ] = (bool) $explicit[ $flag ];
			} elseif ( array_key_exists( $flag, $site ) ) {
				$out[ $flag ] = $site[ $flag ];
			} else {
				$out[ $flag ] = $default;
			}
		}

		/**
		 * Resolved details of a podcast component.
		 *
		 * @param array<string, bool> $out      Detail => shown.
		 * @param string              $context  player|latest|list|episode_page.
		 * @param array               $explicit Local choices (null = inherit).
		 * @param string              $consumer '' (widget), shortcode, shortcode-rows.
		 */
		return (array) apply_filters( 'epm_details', $out, $context, $explicit, $consumer );
	}

	/**
	 * What a context shows for a widget that keeps every detail on
	 * Default: the site's value, else the built-in default.
	 *
	 * @param string $context Context.
	 * @return array<string, bool>
	 */
	public static function effective( string $context ): array {
		return array_merge( self::neutral( $context ), self::site( $context ) );
	}

	/**
	 * Per-context details from the maps presets stored in 1.1–1.3
	 * (visibility, player, episodeList). Visibility and player details go
	 * to every context that has them; the episode list map goes to lists.
	 * The episode page takes neither the description (the page's content
	 * is the description) nor the download button (offered there on
	 * purpose since 1.2): those maps were written for players placed on
	 * pages, and the page has no title detail.
	 *
	 * @param mixed $visibility  Visibility map.
	 * @param mixed $player      Player map.
	 * @param mixed $episodelist Episode list map.
	 * @return array<string, array<string, bool>>
	 */
	public static function from_legacy_maps( $visibility, $player, $episodelist ): array {
		$flat = [];
		foreach ( [ $visibility, $player ] as $map ) {
			if ( is_array( $map ) ) {
				foreach ( $map as $flag => $value ) {
					if ( is_scalar( $value ) ) {
						$flat[ sanitize_key( (string) $flag ) ] = self::to_bool( $value );
					}
				}
			}
		}

		$out = [];
		foreach ( self::CONTEXTS as $context ) {
			foreach ( self::flags( $context ) as $flag ) {
				if ( 'episode_page' === $context && in_array( $flag, [ 'show_description', 'show_download' ], true ) ) {
					continue;
				}
				if ( array_key_exists( $flag, $flat ) ) {
					$out[ $context ][ $flag ] = $flat[ $flag ];
				}
			}
		}

		if ( is_array( $episodelist ) ) {
			foreach ( $episodelist as $flag => $value ) {
				$flag = sanitize_key( (string) $flag );
				if ( is_scalar( $value ) && in_array( $flag, self::flags( 'list' ), true ) ) {
					$out['list'][ $flag ] = self::to_bool( $value );
				}
			}
		}

		return $out;
	}

	/**
	 * Only the details of a map that differ from the built-in defaults.
	 *
	 * @param array<string, array<string, bool>> $map Per-context details.
	 * @return array<string, array<string, bool>>
	 */
	public static function sparse( array $map ): array {
		$out = [];
		foreach ( self::sanitize_map( $map ) as $context => $flags ) {
			$neutral = self::neutral( $context );
			foreach ( $flags as $flag => $value ) {
				if ( $neutral[ $flag ] !== $value ) {
					$out[ $context ][ $flag ] = $value;
				}
			}
		}

		return $out;
	}

	/**
	 * What applying the suggested details would change, detail by detail.
	 *
	 * @return array<int, array{context: string, flag: string, label: string, context_label: string, now: bool, suggested: bool}>
	 */
	public static function suggested_changes(): array {
		$suggested = self::sanitize_map( epm()->design->get( 'details_suggested' ) );
		$changes   = [];

		foreach ( self::CONTEXTS as $context ) {
			if ( empty( $suggested[ $context ] ) ) {
				continue;
			}
			$now = self::effective( $context );
			foreach ( self::flags( $context ) as $flag ) {
				if ( array_key_exists( $flag, $suggested[ $context ] ) && $suggested[ $context ][ $flag ] !== $now[ $flag ] ) {
					$changes[] = [
						'context'       => $context,
						'flag'          => $flag,
						'label'         => self::label( $flag ),
						'context_label' => self::context_label( $context ),
						'now'           => $now[ $flag ],
						'suggested'     => $suggested[ $context ][ $flag ],
					];
				}
			}
		}

		return $changes;
	}
}
