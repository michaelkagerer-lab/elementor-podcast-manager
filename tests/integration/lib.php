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
