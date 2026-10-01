<?php
/**
 * Synthetic episode catalogs for the budget tests (see run.sh and
 * catalog-budget.php). Rows are written with bulk SQL, so 10,000 episodes
 * take seconds instead of minutes; the episodes look like imported ones
 * (audio at the host, show notes, an optional transcript).
 *
 * Every episode of a catalog has a post_name starting with "perf-cat-"
 * and the GUID "perf-cat-<n>"; epm_perf_catalog_reset() removes them and
 * nothing else. Only for disposable test sites.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'epm_perf_catalog' ) ) {

	/**
	 * The kind of episode number $n is in a "mixed" catalog: every 13th
	 * one has WAV audio (kept for storage, not distributable) and every
	 * 17th one no audio, so the feed window has to skip them.
	 *
	 * @param int  $n     Episode number (1 = oldest).
	 * @param bool $mixed Mixed catalog.
	 * @return string mp3, wav or none.
	 */
	function epm_perf_kind( int $n, bool $mixed ): string {
		if ( $mixed && 0 === $n % 17 ) {
			return 'none';
		}
		if ( $mixed && 0 === $n % 13 ) {
			return 'wav';
		}

		return 'mp3';
	}

	/**
	 * Episodes of a catalog that the feed can list (MP3 audio).
	 *
	 * @param int  $count Episodes.
	 * @param bool $mixed Mixed catalog.
	 * @return int
	 */
	function epm_perf_distributable( int $count, bool $mixed ): int {
		$n = 0;
		for ( $i = 1; $i <= $count; $i++ ) {
			$n += 'mp3' === epm_perf_kind( $i, $mixed ) ? 1 : 0;
		}

		return $n;
	}

	/**
	 * Delete every catalog episode (posts and their meta), with SQL.
	 *
	 * @return int Episodes deleted.
	 */
	function epm_perf_catalog_reset(): int {
		global $wpdb;

		$deleted = 0;
		do {
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'podcast_episode' AND post_name LIKE %s LIMIT 2000", $wpdb->esc_like( 'perf-cat-' ) . '%' ) ) );
			if ( empty( $ids ) ) {
				break;
			}
			$in = implode( ',', $ids );
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
			$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- integers.
			foreach ( $ids as $id ) {
				clean_post_cache( $id );
			}
			$deleted += count( $ids );
		} while ( true );

		wp_cache_flush();
		\EPM\Feed::flush_cache();

		return $deleted;
	}

	/**
	 * Create a catalog of $count published episodes (after removing an
	 * earlier one), one hour apart, oldest first.
	 *
	 * @param int  $count         Episodes.
	 * @param int  $transcript_kb Transcript size per episode (0 = none).
	 * @param bool $mixed         Every 13th episode WAV, every 17th without audio.
	 * @param int  $notes_bytes   Show notes size per episode.
	 * @return int Episodes created.
	 */
	function epm_perf_catalog( int $count, int $transcript_kb = 0, bool $mixed = true, int $notes_bytes = 600 ): int {
		global $wpdb;

		epm_perf_catalog_reset();

		$notes      = '<p>' . substr( str_repeat( 'Show notes with a link to https://example.com/ and some words. ', (int) ceil( $notes_bytes / 60 ) ), 0, max( 10, $notes_bytes - 7 ) ) . '</p>';
		$transcript = $transcript_kb > 0 ? '<p>' . str_repeat( 'Transcript text. ', (int) ( $transcript_kb * 1024 / 17 ) ) . '</p>' : '';
		$base       = 1500000000;
		$home       = home_url( '/' );

		for ( $start = 1; $start <= $count; $start += 250 ) {
			$end  = min( $count, $start + 249 );
			$rows = [];
			for ( $i = $start; $i <= $end; $i++ ) {
				$date   = gmdate( 'Y-m-d H:i:s', $base + $i * HOUR_IN_SECONDS );
				$rows[] = $wpdb->prepare(
					'(1, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %d, %s, %d, %s, %s, %d)',
					$date,
					$date,
					'<p>Episode ' . $i . ' of the synthetic catalog.</p>',
					'Perf episode ' . $i,
					'',
					'publish',
					'closed',
					'closed',
					'',
					'perf-cat-' . $i,
					'',
					'',
					$date,
					$date,
					'',
					0,
					$home . '?perf-cat=' . $i,
					0,
					'podcast_episode',
					'',
					0
				);
			}
			$wpdb->query( "INSERT INTO {$wpdb->posts} (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- rows prepared above.

			$ids   = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = 'podcast_episode' AND post_name LIKE %s", $wpdb->esc_like( 'perf-cat-' ) . '%' ), OBJECT_K );
			$meta  = [];
			$bytes = 0;
			foreach ( $ids as $row ) {
				$i = (int) substr( (string) $row->post_name, 9 );
				if ( $i < $start || $i > $end ) {
					continue;
				}
				$kind   = epm_perf_kind( $i, $mixed );
				$values = [
					'_epm_guid'             => 'perf-cat-' . $i,
					'_epm_duration'         => '10:00',
					'_epm_duration_seconds' => '600',
					'_epm_episode_number'   => (string) $i,
					'_epm_source'           => 'import',
					'_epm_show_notes'       => $notes,
				];
				if ( 'mp3' === $kind ) {
					$values['_epm_audio_url']    = 'https://feeds.example.test/media/perf-cat-' . $i . '.mp3';
					$values['_epm_audio_type']   = 'audio/mpeg';
					$values['_epm_audio_length'] = (string) ( 1000000 + $i );
				} elseif ( 'wav' === $kind ) {
					$values['_epm_audio_url']    = 'https://feeds.example.test/media/perf-cat-' . $i . '.wav';
					$values['_epm_audio_type']   = 'audio/wav';
					$values['_epm_audio_length'] = (string) ( 5000000 + $i );
				}
				if ( '' !== $transcript ) {
					$values['_epm_transcript'] = $transcript;
				}
				foreach ( $values as $key => $value ) {
					$meta[] = $wpdb->prepare( '(%d, %s, %s)', (int) $row->ID, $key, $value );
					$bytes += strlen( $value );
				}
				// Statements stay far below max_allowed_packet.
				if ( count( $meta ) >= 200 || $bytes >= 2 * MB_IN_BYTES ) {
					$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $meta ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- rows prepared above.
					$meta  = [];
					$bytes = 0;
				}
			}
			if ( ! empty( $meta ) ) {
				$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ',', $meta ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- rows prepared above.
			}
			unset( $ids, $rows, $meta );
			$wpdb->flush();
		}

		wp_cache_flush();
		\EPM\Feed::flush_cache();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'podcast_episode' AND post_name LIKE %s", $wpdb->esc_like( 'perf-cat-' ) . '%' ) );
	}
}
