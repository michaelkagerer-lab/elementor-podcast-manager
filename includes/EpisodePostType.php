<?php
/**
 * Registers the podcast_episode custom post type.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EpisodePostType {

	public const CPT = 'podcast_episode';

	/**
	 * Register the post type. Safe to call on activation and on init.
	 *
	 * @return void
	 */
	public static function register(): void {
		$labels = [
			'name'                  => _x( 'Episodes', 'Post type general name', 'elementor-podcast-manager' ),
			'singular_name'         => _x( 'Episode', 'Post type singular name', 'elementor-podcast-manager' ),
			'menu_name'             => _x( 'Podcast', 'Admin menu', 'elementor-podcast-manager' ),
			'name_admin_bar'        => _x( 'Episode', 'Add New on toolbar', 'elementor-podcast-manager' ),
			'add_new'               => _x( 'Add Episode', 'podcast_episode', 'elementor-podcast-manager' ),
			'add_new_item'          => __( 'Add New Episode', 'elementor-podcast-manager' ),
			'new_item'              => __( 'New Episode', 'elementor-podcast-manager' ),
			'edit_item'             => __( 'Edit Episode', 'elementor-podcast-manager' ),
			'view_item'             => __( 'View Episode', 'elementor-podcast-manager' ),
			'all_items'             => __( 'Episodes', 'elementor-podcast-manager' ),
			'search_items'          => __( 'Search Episodes', 'elementor-podcast-manager' ),
			'not_found'             => __( 'No episodes found.', 'elementor-podcast-manager' ),
			'not_found_in_trash'    => __( 'No episodes found in Trash.', 'elementor-podcast-manager' ),
			'archives'              => _x( 'Episode archives', 'The post type archive label', 'elementor-podcast-manager' ),
		];

		// Capability policy: the epm_cap_manage_episodes filter governs every
		// episode operation (menus, list, editor, meta saves, uploads, REST).
		// Defaults to edit_posts so administrators and editors keep working.
		$manage = Capabilities::manage_episodes();
		$caps   = [];
		foreach (
			[
				'edit_post', 'read_post', 'delete_post',
				'edit_posts', 'edit_others_posts', 'edit_private_posts', 'edit_published_posts',
				'publish_posts', 'read_private_posts',
				'delete_posts', 'delete_others_posts', 'delete_private_posts', 'delete_published_posts',
				'create_posts',
			] as $cap
		) {
			$caps[ $cap ] = $manage;
		}

		$args = [
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => false, // Added manually under the Podcast menu.
			'show_in_rest'       => true,
			'query_var'          => true,
			'rewrite'            => [
				'slug'       => 'podcast',
				'with_front' => false,
			],
			'capabilities'       => $caps,
			'map_meta_cap'       => true,
			'has_archive'        => 'podcast',
			'hierarchical'       => false,
			'menu_position'      => 20,
			'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'author' ],
			'show_in_nav_menus'  => true,
		];

		register_post_type( self::CPT, $args );
	}
}
