<?php
/** Bounded cleanup used only after explicit opt-in to data deletion. @package EPM */
namespace EPM;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
final class Uninstall {
	/** Delete episodes and revisions without loading their transcript metadata. */
	public static function episodes(): void {
		global $wpdb;
		do {
			$ids = self::ids( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'podcast_episode' ORDER BY ID LIMIT 200" );
			if ( empty( $ids ) ) {
				break;
			}
			$in = implode( ',', $ids );
			// Revisions may be numerous too: delete at most 200 at a time.
			do {
				$revisions = self::ids( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent IN ($in) LIMIT 200" );
				if ( $revisions ) {
					self::posts( $revisions );
				}
			} while ( count( $revisions ) === 200 );
			// Keep attached media and other child posts, removing their stale parent.
			do {
				$children = self::ids( "SELECT ID FROM {$wpdb->posts} WHERE post_parent IN ($in) LIMIT 200" );
				if ( $children ) {
					$child_in = implode( ',', $children );
					self::query( "UPDATE {$wpdb->posts} SET post_parent = 0 WHERE ID IN ($child_in)" );
					foreach ( $children as $child ) {
						wp_cache_delete( $child, 'posts' );
					}
				}
			} while ( count( $children ) === 200 );
			self::posts( $ids );
		} while ( count( $ids ) === 200 );
	}

	/** All IDs come from the database and are cast to integers before interpolation. */
	private static function posts( array $ids ): void {
		global $wpdb;
		$in = implode( ',', array_map( 'intval', $ids ) );
		$terms = $wpdb->get_results( "SELECT DISTINCT tt.term_taxonomy_id, tt.taxonomy FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE tr.object_id IN ($in)" );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( __( 'Podcast relationship lookup failed; settings were retained.', 'elementor-podcast-manager' ) );
		}
		do {
			$comments = self::ids( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ($in) LIMIT 200" );
			if ( $comments ) {
				$comment_in = implode( ',', $comments );
				self::query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ($comment_in)" );
				self::query( "DELETE FROM {$wpdb->comments} WHERE comment_ID IN ($comment_in)" );
				clean_comment_cache( $comments );
				foreach ( $comments as $comment ) {
					wp_cache_delete( $comment, 'comment_meta' );
				}
			}
		} while ( count( $comments ) === 200 );
		self::query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($in)" );
		self::query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)" );
		self::query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($in)" );
		foreach ( $ids as $id ) {
			wp_cache_delete( (int) $id, 'posts' );
			wp_cache_delete( (int) $id, 'post_meta' );
			foreach ( get_taxonomies( [], 'names' ) as $taxonomy ) {
				wp_cache_delete( (int) $id, $taxonomy . '_relationships' );
			}
		}
		$by_taxonomy = [];
		foreach ( $terms as $term ) {
			$by_taxonomy[ $term->taxonomy ][] = (int) $term->term_taxonomy_id;
		}
		foreach ( $by_taxonomy as $taxonomy => $term_ids ) {
			wp_update_term_count_now( $term_ids, $taxonomy );
		}
		wp_cache_set_posts_last_changed();
	}

	private static function ids( string $sql ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $sql );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( __( 'Podcast data lookup failed; settings were retained.', 'elementor-podcast-manager' ) );
		}
		return array_map( 'intval', $ids );
	}

	/** Stop before deleting settings if the database cannot complete cleanup. */
	private static function query( string $sql ): void {
		global $wpdb;
		if ( false === $wpdb->query( $sql ) ) {
			throw new \RuntimeException( __( 'Podcast data cleanup failed; settings were retained. Retry after fixing the database error.', 'elementor-podcast-manager' ) );
		}
	}
}
