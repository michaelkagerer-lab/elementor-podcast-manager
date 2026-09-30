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
	 * Topics taxonomy (non-hierarchical, like tags).
	 */
	public const TOPIC = 'podcast_topic';

	/**
	 * Register the post type and the topics taxonomy. Safe to call on
	 * activation and on init.
	 *
	 * @return void
	 */
	public static function register(): void {
		self::register_post_type();
		self::register_topics();
	}

	/**
	 * Register the Topics taxonomy: tags for episodes (themes, guests'
	 * fields, series), with an archive at /podcast-topic/<slug>/, a column
	 * and Quick Edit field in the episode list, and REST support for the
	 * block editor.
	 *
	 * Like core tags: everyone who can edit episodes can assign topics
	 * (and add new ones while tagging), but renaming, re-slugging and
	 * deleting topics, which changes published episodes, needs
	 * manage_categories (editors and administrators). A site with a custom
	 * episode capability uses that capability for all four; the
	 * epm_cap_manage_topics filter changes the one for managing topics.
	 *
	 * @return void
	 */
	public static function register_topics(): void {
		$manage = self::primitive_cap( Capabilities::manage_episodes() );

		/**
		 * Filters the capability needed to manage, edit and delete topics.
		 *
		 * @since 1.3.0
		 *
		 * @param string $capability Default: manage_categories, or the
		 *                           filtered episode capability.
		 */
		$terms_cap = (string) apply_filters( 'epm_cap_manage_topics', 'edit_posts' === $manage ? 'manage_categories' : $manage );
		// Meta capabilities are resolved per object and cannot stand in here.
		if ( '' === $terms_cap || $terms_cap !== self::primitive_cap( $terms_cap ) || in_array( $terms_cap, [ 'edit_term', 'delete_term', 'assign_term' ], true ) ) {
			$terms_cap = 'manage_categories';
		}

		$labels = [
			'name'                       => _x( 'Topics', 'Taxonomy general name', 'elementor-podcast-manager' ),
			'singular_name'              => _x( 'Topic', 'Taxonomy singular name', 'elementor-podcast-manager' ),
			'menu_name'                  => __( 'Topics', 'elementor-podcast-manager' ),
			'all_items'                  => __( 'All topics', 'elementor-podcast-manager' ),
			'edit_item'                  => __( 'Edit topic', 'elementor-podcast-manager' ),
			'view_item'                  => __( 'View topic', 'elementor-podcast-manager' ),
			'update_item'                => __( 'Update topic', 'elementor-podcast-manager' ),
			'add_new_item'               => __( 'Add topic', 'elementor-podcast-manager' ),
			'new_item_name'              => __( 'New topic name', 'elementor-podcast-manager' ),
			'search_items'               => __( 'Search topics', 'elementor-podcast-manager' ),
			'popular_items'              => __( 'Popular topics', 'elementor-podcast-manager' ),
			'separate_items_with_commas' => __( 'Separate topics with commas', 'elementor-podcast-manager' ),
			'add_or_remove_items'        => __( 'Add or remove topics', 'elementor-podcast-manager' ),
			'choose_from_most_used'      => __( 'Choose from the most used topics', 'elementor-podcast-manager' ),
			'not_found'                  => __( 'No topics found.', 'elementor-podcast-manager' ),
			'no_terms'                   => __( 'No topics', 'elementor-podcast-manager' ),
			'items_list_navigation'      => __( 'Topics list navigation', 'elementor-podcast-manager' ),
			'items_list'                 => __( 'Topics list', 'elementor-podcast-manager' ),
			'back_to_items'              => __( '&larr; Go to topics', 'elementor-podcast-manager' ),
			'item_link'                  => __( 'Topic link', 'elementor-podcast-manager' ),
			'item_link_description'      => __( 'A link to a topic.', 'elementor-podcast-manager' ),
		];

		register_taxonomy(
			self::TOPIC,
			[ self::CPT ],
			[
				'labels'             => $labels,
				'description'        => __( 'Topics group episodes by theme, so listeners can find every episode about one subject.', 'elementor-podcast-manager' ),
				'public'             => true,
				'hierarchical'       => false,
				'show_ui'            => true,
				'show_in_menu'       => false, // Linked from the Podcast menu (Admin::register_menu()).
				'show_in_nav_menus'  => true,
				'show_in_rest'       => true,
				'show_admin_column'  => true,
				'show_in_quick_edit' => true,
				'show_tagcloud'      => true,
				'query_var'          => true,
				'rewrite'            => [
					'slug'       => 'podcast-topic',
					'with_front' => false,
				],
				'capabilities'       => [
					'manage_terms' => $terms_cap,
					'edit_terms'   => $terms_cap,
					'delete_terms' => $terms_cap,
					'assign_terms' => $manage,
				],
			]
		);
	}

	/**
	 * Register the episode post type.
	 *
	 * @return void
	 */
	private static function register_post_type(): void {
		$labels = [
			'name'                  => _x( 'Episodes', 'Post type general name', 'elementor-podcast-manager' ),
			'singular_name'         => _x( 'Episode', 'Post type singular name', 'elementor-podcast-manager' ),
			'menu_name'             => _x( 'Podcast', 'Admin menu', 'elementor-podcast-manager' ),
			'name_admin_bar'        => _x( 'Episode', 'Add New on toolbar', 'elementor-podcast-manager' ),
			'add_new'               => _x( 'Add episode', 'podcast_episode', 'elementor-podcast-manager' ),
			'add_new_item'          => __( 'Add episode', 'elementor-podcast-manager' ),
			'new_item'              => __( 'New episode', 'elementor-podcast-manager' ),
			'edit_item'             => __( 'Edit episode', 'elementor-podcast-manager' ),
			'view_item'             => __( 'View episode', 'elementor-podcast-manager' ),
			'all_items'             => __( 'Episodes', 'elementor-podcast-manager' ),
			'search_items'          => __( 'Search episodes', 'elementor-podcast-manager' ),
			'not_found'             => __( 'No episodes found.', 'elementor-podcast-manager' ),
			'not_found_in_trash'    => __( 'No episodes found in the trash.', 'elementor-podcast-manager' ),
			'archives'              => _x( 'Episode archives', 'The post type archive label', 'elementor-podcast-manager' ),
		];

		// Capability policy: the epm_cap_manage_episodes filter governs every
		// episode operation (menus, list, editor, meta saves, uploads, REST).
		//
		// With the default ("edit_posts") episodes behave exactly like core
		// posts: contributors draft, authors publish their own, editors manage
		// all. A site that filters in a custom capability grants the whole
		// episode workflow with that one capability.
		//
		// Only PRIMITIVE capabilities are ever mapped. The meta capabilities
		// (edit_post, read_post, delete_post) keep their core names: with
		// map_meta_cap, WordPress registers whatever they are mapped to as a
		// site-wide meta capability — mapping them to "edit_posts" made every
		// edit_posts check (posts, Elementor templates, episodes) fail for
		// every user, administrators included, and hid the episode menu.
		$manage = self::primitive_cap( Capabilities::manage_episodes() );
		$caps   = [];
		if ( 'edit_posts' !== $manage ) {
			foreach (
				[
					'edit_posts', 'edit_others_posts', 'edit_private_posts', 'edit_published_posts',
					'publish_posts', 'read_private_posts',
					'delete_posts', 'delete_others_posts', 'delete_private_posts', 'delete_published_posts',
					'create_posts',
				] as $cap
			) {
				$caps[ $cap ] = $manage;
			}
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
			'capability_type'    => 'post',
			'capabilities'       => $caps,
			'map_meta_cap'       => true,
			'has_archive'        => 'podcast',
			'hierarchical'       => false,
			'menu_position'      => 20,
			// custom-fields: required for the registered episode meta to
			// appear in the REST API (block editor, headless, dynamic tags).
			'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'revisions', 'author', 'custom-fields' ],
			'show_in_nav_menus'  => true,
		];

		register_post_type( self::CPT, $args );
	}

	/**
	 * Guard against a filtered capability that is a meta capability.
	 * Meta capabilities are resolved per object and cannot stand in for the
	 * primitive capabilities of a post type.
	 *
	 * @param string $cap Capability from the epm_cap_manage_episodes filter.
	 * @return string
	 */
	private static function primitive_cap( string $cap ): string {
		$meta_caps = [ 'edit_post', 'read_post', 'delete_post', 'edit_page', 'read_page', 'delete_page', 'publish_post' ];

		if ( '' === $cap || in_array( $cap, $meta_caps, true ) ) {
			return 'edit_posts';
		}

		return $cap;
	}
}
