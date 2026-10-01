<?php
/**
 * Shared helpers for the integration suites (run inside WordPress with
 * `wp eval-file`): the assertion runner and feed helpers.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EPM\Feed;

if ( ! class_exists( 'EPM_Test_Runner' ) ) {

	/**
	 * Minimal assertion runner: one line per test, a summary at the end and
	 * a non-zero exit code when anything failed.
	 */
	final class EPM_Test_Runner {

		/** @var int */
		private $passed = 0;

		/** @var string[] */
		private $failures = [];

		/** @var string */
		private $current = '';

		/**
		 * Run one test; an exception counts as a failure.
		 *
		 * @param string   $name Test name.
		 * @param callable $fn   Receives the runner.
		 * @return void
		 */
		public function test( string $name, callable $fn ): void {
			$this->current = $name;
			$before        = count( $this->failures );
			try {
				$fn( $this );
			} catch ( Throwable $e ) {
				$this->failures[] = $name . ': exception ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . basename( $e->getFile() ) . ':' . $e->getLine();
			}
			$ok = count( $this->failures ) === $before;
			WP_CLI::log( ( $ok ? '  ✓ ' : '  ✗ ' ) . $name );
		}

		/**
		 * Record an expectation.
		 *
		 * @param bool   $condition Whether it holds.
		 * @param string $message   Shown when it fails.
		 * @return void
		 */
		public function assert( bool $condition, string $message = '' ): void {
			if ( $condition ) {
				++$this->passed;
				return;
			}
			$this->failures[] = $this->current . ( '' !== $message ? ': ' . $message : '' );
		}

		/**
		 * Strict equality.
		 *
		 * @param mixed  $expected Expected value.
		 * @param mixed  $actual   Actual value.
		 * @param string $message  Shown when it fails.
		 * @return void
		 */
		public function same( $expected, $actual, string $message = '' ): void {
			$this->assert(
				$expected === $actual,
				trim( $message . ' expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) )
			);
		}

		/**
		 * Print the summary; exit non-zero when anything failed.
		 *
		 * @return void
		 */
		public function finish(): void {
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( '%d assertions passed, %d failed.', $this->passed, count( $this->failures ) ) );
			foreach ( $this->failures as $failure ) {
				WP_CLI::log( '  FAIL ' . $failure );
			}
			if ( ! empty( $this->failures ) ) {
				WP_CLI::halt( 1 );
			}
		}
	}
}

if ( ! function_exists( 'epm_test_feed' ) ) {

	/**
	 * Build the feed XML without cache.
	 *
	 * @return DOMDocument
	 */
	function epm_test_feed(): DOMDocument {
		Feed::flush_cache();
		$document = epm()->feed->get_document();
		$dom      = new DOMDocument();
		$loaded   = $dom->loadXML( $document['xml'] );
		if ( ! $loaded ) {
			throw new RuntimeException( 'Feed is not well-formed XML.' );
		}

		return $dom;
	}

	/**
	 * XPath with the podcast namespaces registered.
	 *
	 * @param DOMDocument $dom Feed.
	 * @return DOMXPath
	 */
	function epm_test_xpath( DOMDocument $dom ): DOMXPath {
		$xp = new DOMXPath( $dom );
		$xp->registerNamespace( 'itunes', 'http://www.itunes.com/dtds/podcast-1.0.dtd' );
		$xp->registerNamespace( 'podcast', 'https://podcastindex.org/namespace/1.0' );
		$xp->registerNamespace( 'content', 'http://purl.org/rss/1.0/modules/content/' );
		$xp->registerNamespace( 'atom', 'http://www.w3.org/2005/Atom' );

		return $xp;
	}

	/**
	 * Run code with changed podcast settings, restored afterwards.
	 *
	 * @param array    $changes Settings to change.
	 * @param callable $fn      Code to run.
	 * @return void
	 */
	function epm_test_with_settings( array $changes, callable $fn ): void {
		$original = get_option( 'epm_podcast_settings' );
		update_option( 'epm_podcast_settings', array_merge( (array) $original, $changes ) );
		try {
			$fn();
		} finally {
			update_option( 'epm_podcast_settings', $original );
			Feed::flush_cache();
		}
	}
}

if ( ! function_exists( 'epm_test_as_episode_page' ) ) {

	/**
	 * Run a callback as if the request were the single page (or embed) of an
	 * episode, then restore the previous query.
	 *
	 * @param int      $post_id Episode ID.
	 * @param callable $fn      Callback.
	 * @param bool     $embed   Pretend to be /podcast/{slug}/embed/.
	 * @return mixed Callback result.
	 */
	function epm_test_as_episode_page( int $post_id, callable $fn, bool $embed = false ) {
		global $wp_query, $wp_the_query, $post;

		$saved = [ $wp_query, $wp_the_query, $post ];

		$wp_query = new WP_Query( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			[
				'p'         => $post_id,
				'post_type' => \EPM\EpisodePostType::CPT,
			]
		);
		if ( $embed ) {
			$wp_query->is_embed = true;
		}
		$wp_the_query = $wp_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$post         = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );

		try {
			return $fn();
		} finally {
			[ $wp_query, $wp_the_query, $post ] = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			wp_reset_postdata();
		}
	}
}

if ( ! function_exists( 'epm_test_signature' ) ) {

	/**
	 * Which details rendered podcast components show: the player layout
	 * and its visible parts, every list row or card, the latest-episode
	 * card. Independent of ids, texts and attribute order, so it compares
	 * what visitors see across versions.
	 *
	 * @param string $html Markup.
	 * @return array<int, array<string, mixed>> One entry per component, in document order.
	 */
	function epm_test_signature( string $html ): array {
		if ( '' === trim( $html ) ) {
			return [];
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();
		$xp = new DOMXPath( $dom );

		$has_class = static function ( string $class ): string {
			return 'contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")';
		};
		$present   = static function ( DOMNode $node, array $parts ) use ( $xp, $has_class ): array {
			$out = [];
			foreach ( $parts as $name => $query ) {
				$query = '#' === $query[0] ? substr( $query, 1 ) : './/*[' . $has_class( $query ) . ']';
				if ( $xp->query( $query, $node )->length > 0 ) {
					$out[] = $name;
				}
			}
			return $out;
		};
		$meta      = static function ( DOMNode $node ) use ( $xp, $has_class ): array {
			$out = [];
			foreach ( $xp->query( './/*[' . $has_class( 'epm-meta__item' ) . ']', $node ) as $item ) {
				if ( preg_match( '/epm-meta__item--([a-z_]+)/', (string) $item->getAttribute( 'class' ), $m ) ) {
					$out[] = $m[1];
				}
			}
			return $out;
		};

		$player_parts = [
			'artwork'     => 'epm-player__artwork',
			'label'       => 'epm-player__label',
			'title'       => 'epm-player__title',
			'skip_back'   => '#.//*[@data-epm-seek-rel="-15"]',
			'skip_fwd'    => '#.//*[@data-epm-seek-rel="30"]',
			'speed'       => '#.//*[@data-epm-speed]',
			'volume'      => '#.//*[@data-epm-volume]',
			'download'    => 'epm-player__download',
			'share'       => '#.//*[@data-epm-share]',
			'description' => 'epm-player__description',
			'chapters'    => 'epm-player__chapters',
			'platforms'   => 'epm-player__platforms',
		];
		$item_parts   = [
			'artwork' => '#.//*[contains(@class, "__artwork")]',
			'label'   => 'epm-episode-card__label',
			'number'  => 'epm-episode-row__number',
			'guest'   => '#.//*[contains(@class, "__guest")]',
			'title'   => '#.//*[contains(@class, "__title")]',
			'excerpt' => '#.//*[contains(@class, "__excerpt")]',
			'topics'  => 'epm-topics',
			'play'    => '#.//*[@data-epm-card-play]',
		];

		$out   = [];
		$query = '//*[@data-epm-player] | //*[' . $has_class( 'epm-episode-list' ) . '] | //*[' . $has_class( 'epm-latest__header' ) . ']';
		foreach ( $xp->query( $query ) as $node ) {
			$class = ' ' . $node->getAttribute( 'class' ) . ' ';
			if ( $node->hasAttribute( 'data-epm-player' ) ) {
				$layout = preg_match( '/ epm-player--(minimal|compact|editorial|artwork|full) /', $class, $m ) ? $m[1] : '';
				$label  = $xp->query( './/*[' . $has_class( 'epm-player__label' ) . ']', $node );
				$out[]  = [
					'type'   => 'player',
					'layout' => $layout,
					'parts'  => $present( $node, $player_parts ),
					'meta'   => $meta( $node ),
					'label'  => $label->length ? trim( (string) $label->item( 0 )->textContent ) : '',
					'sticky' => (string) $node->getAttribute( 'data-epm-sticky-player' ),
				];
			} elseif ( false !== strpos( $class, ' epm-latest__header ' ) ) {
				$out[] = [
					'type'  => 'latest-card',
					'parts' => $present(
						$node,
						[
							'artwork'     => 'epm-latest__artwork',
							'title'       => 'epm-latest__title',
							'description' => 'epm-latest__description',
						]
					),
					'meta'  => $meta( $node ),
				];
			} else {
				$layout = preg_match( '/ epm-episode-list--(list|editorial-rows|cards|grid|minimal) /', $class, $m ) ? $m[1] : '';
				$items  = [];
				foreach ( $xp->query( './*[' . $has_class( 'epm-episode-row' ) . ' or ' . $has_class( 'epm-episode-card' ) . ']', $node ) as $item ) {
					$items[] = implode(
						',',
						array_merge(
							$present( $item, $item_parts ),
							array_map(
								static function ( $field ) {
									return 'meta:' . $field;
								},
								$meta( $item )
							)
						)
					);
				}
				$out[] = [
					'type'     => 'list',
					'layout'   => $layout,
					'numbered' => false !== strpos( $class, ' epm-episode-list--numbered ' ),
					'items'    => $items,
				];
			}
		}

		return $out;
	}

	/**
	 * Render an Elementor widget from stored settings, as the frontend does.
	 *
	 * @param string $type     Widget type, e.g. epm-podcast-player.
	 * @param array  $settings Stored settings (as in _elementor_data).
	 * @return string Markup.
	 */
	function epm_test_widget( string $type, array $settings ): string {
		$element = \Elementor\Plugin::$instance->elements_manager->create_element_instance(
			[
				'id'         => substr( md5( $type . wp_json_encode( $settings ) . wp_rand() ), 0, 7 ),
				'elType'     => 'widget',
				'widgetType' => $type,
				'settings'   => $settings,
				'elements'   => [],
			]
		);
		if ( ! $element ) {
			return '';
		}
		ob_start();
		$element->print_element();
		return (string) ob_get_clean();
	}

	/**
	 * The automatic episode page body of an episode (the_content filter).
	 *
	 * @param int $episode_id Episode ID.
	 * @return string Markup.
	 */
	function epm_test_episode_page( int $episode_id ): string {
		return (string) epm_test_as_episode_page(
			$episode_id,
			static function () {
				return ( new \EPM\EpisodeTemplate() )->filter_content( '<p>EPM-DESCRIPTION</p>' );
			}
		);
	}
}

if ( ! function_exists( 'epm_test_details_consumers' ) ) {

	/**
	 * Everything that shows episode details with its defaults: widgets
	 * saved by 1.3.0 (no schema marker), the same widgets inserted after
	 * the details change (schema marker '2', every detail on "Default"),
	 * shortcodes without attributes, and the automatic episode page.
	 * tests/fixtures/details-1.3.0.json holds what 1.3.0 rendered for each
	 * on an untouched site (generated with tests/fixtures/details-snapshot.php
	 * before the change).
	 *
	 * @param array $fx Fixture IDs.
	 * @return array<string, callable(): string>
	 */
	function epm_test_details_consumers( array $fx ): array {
		$ep1 = (int) $fx['ep1'];
		$new = [ 'epm_schema' => '2' ];

		$consumers = [
			'player widget'                    => static fn() => epm_test_widget( 'epm-podcast-player', [ 'source' => 'specific', 'episode_id' => $ep1 ] ),
			'player widget, full'              => static fn() => epm_test_widget( 'epm-podcast-player', [ 'source' => 'specific', 'episode_id' => $ep1, 'layout' => 'full' ] ),
			'latest widget'                    => static fn() => epm_test_widget( 'epm-latest-episode', [] ),
			'latest widget, full'              => static fn() => epm_test_widget( 'epm-latest-episode', [ 'layout' => 'full' ] ),
			'latest widget, no player'         => static fn() => epm_test_widget( 'epm-latest-episode', [ 'show_player' => '' ] ),
			'list widget'                      => static fn() => epm_test_widget( 'epm-episode-list', [ 'number' => 3 ] ),
			'list widget, cards'               => static fn() => epm_test_widget( 'epm-episode-list', [ 'number' => 3, 'layout' => 'cards' ] ),
			'list widget, editorial rows'      => static fn() => epm_test_widget( 'epm-episode-list', [ 'number' => 3, 'layout' => 'editorial-rows' ] ),
			'[podcast_player id]'              => static fn() => do_shortcode( '[podcast_player id="' . $ep1 . '"]' ),
			'[podcast_player id layout=full]'  => static fn() => do_shortcode( '[podcast_player id="' . $ep1 . '" layout="full"]' ),
			'[podcast_latest]'                 => static fn() => do_shortcode( '[podcast_latest]' ),
			'[podcast_latest layout=full]'     => static fn() => do_shortcode( '[podcast_latest layout="full"]' ),
			'[podcast_episodes]'               => static fn() => do_shortcode( '[podcast_episodes limit="3"]' ),
			'[podcast_episodes layout=cards]'  => static fn() => do_shortcode( '[podcast_episodes limit="3" layout="cards"]' ),
			'[podcast_episodes layout=editorial-rows]' => static fn() => do_shortcode( '[podcast_episodes limit="3" layout="editorial-rows"]' ),
			'episode page'                     => static fn() => epm_test_episode_page( $ep1 ),
			'episode page, bonus episode'      => static fn() => epm_test_episode_page( (int) $fx['ep3'] ),
		];

		// The same widgets inserted after the change: nothing chosen locally.
		foreach ( [
			'player widget'               => [ 'epm-podcast-player', [ 'source' => 'specific', 'episode_id' => $ep1 ] ],
			'player widget, full'         => [ 'epm-podcast-player', [ 'source' => 'specific', 'episode_id' => $ep1, 'layout' => 'full' ] ],
			'latest widget'               => [ 'epm-latest-episode', [] ],
			'latest widget, full'         => [ 'epm-latest-episode', [ 'layout' => 'full' ] ],
			'latest widget, no player'    => [ 'epm-latest-episode', [ 'show_player' => '' ] ],
			'list widget'                 => [ 'epm-episode-list', [ 'number' => 3 ] ],
			'list widget, cards'          => [ 'epm-episode-list', [ 'number' => 3, 'layout' => 'cards' ] ],
			'list widget, editorial rows' => [ 'epm-episode-list', [ 'number' => 3, 'layout' => 'editorial-rows' ] ],
		] as $name => [ $type, $settings ] ) {
			$consumers[ 'new ' . $name ] = static fn() => epm_test_widget( $type, $new + $settings );
		}

		return $consumers;
	}
}

if ( ! function_exists( 'epm_test_controls' ) ) {

	/**
	 * Every control of a widget type with labels, options and
	 * descriptions, as the editor gets them. (On frontend requests
	 * Elementor keeps only the parts needed to render.)
	 *
	 * @param string $type Widget type.
	 * @return array<string, array<string, mixed>>
	 */
	function epm_test_controls( string $type ): array {
		$flag = new ReflectionProperty( \Elementor\Core\Frontend\Performance::class, 'is_frontend' );
		$flag->setAccessible( true );
		$was = $flag->getValue();
		$flag->setValue( null, false );

		$element = \Elementor\Plugin::$instance->elements_manager->create_element_instance( [ 'id' => 'ctrl001', 'elType' => 'widget', 'widgetType' => $type, 'settings' => [], 'elements' => [] ] );
		\Elementor\Plugin::$instance->controls_manager->delete_stack( $element );
		$controls = (array) $element->get_controls();

		// Back to what rendering uses.
		$flag->setValue( null, $was );
		\Elementor\Plugin::$instance->controls_manager->delete_stack( $element );

		return $controls;
	}
}
