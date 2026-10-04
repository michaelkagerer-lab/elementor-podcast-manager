<?php
/** Native Elementor multi-select with paginated topic search. @package EPM */
namespace EPM\Elementor\Controls;
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TopicSelectControl extends \Elementor\Control_Select2 {
	public function get_type(): string { return 'epm_topic_select'; }

	public function enqueue(): void {
		wp_enqueue_script( 'epm-topic-select', EPM_URL . 'admin/js/epm-topic-select.js', [ 'elementor-editor' ], EPM_VERSION, true );
		wp_localize_script( 'epm-topic-select', 'epmTopicSelect', [
			'url' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'epm_topic_search' ),
			'error' => __( 'Topics could not be loaded. Search again or reload the editor. Your selection is kept.', 'elementor-podcast-manager' ),
		] );
	}

	/** Saved values remain topic slugs; lookup never changes the selection. */
	public static function search( string $term, int $page, array $include ): array {
		$args = [ 'taxonomy' => \EPM\Renderer::TOPIC_TAXONOMY, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC', 'number' => 31, 'offset' => ( max( 1, $page ) - 1 ) * 30, 'search' => sanitize_text_field( $term ) ];
		if ( $include ) {
			$args['slug'] = array_map( 'sanitize_title', array_slice( array_filter( $include, 'is_string' ), 0, 100 ) );
			$args['number'] = 100;
			$args['offset'] = 0;
			unset( $args['search'] );
		}
		$terms = get_terms( $args );
		if ( is_wp_error( $terms ) ) { throw new \RuntimeException( 'Topic lookup failed.' ); }
		$more = ! $include && count( $terms ) > 30;
		return [ 'results' => array_map( static fn( $topic ) => [ 'id' => $topic->slug, 'text' => $topic->name ], array_slice( $terms, 0, $include ? 100 : 30 ) ), 'pagination' => [ 'more' => $more ] ];
	}

	public static function ajax_search(): void {
		check_ajax_referer( 'epm_topic_search' );
		if ( ! \EPM\Capabilities::can_manage_episodes() ) { wp_send_json_error( [], 403 ); }
		$term = isset( $_POST['s'] ) && is_string( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : '';
		$page = isset( $_POST['page'] ) && is_scalar( $_POST['page'] ) ? min( 100000, max( 1, absint( $_POST['page'] ) ) ) : 1;
		$include = isset( $_POST['include'] ) && is_array( $_POST['include'] ) ? wp_unslash( $_POST['include'] ) : [];
		try { wp_send_json_success( self::search( $term, $page, $include ) ); }
		catch ( \RuntimeException $e ) { wp_send_json_error( [], 503 ); }
	}
}
