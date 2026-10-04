<?php
/**
 * Elementor custom control: searchable episode select (Layer 3).
 *
 * Replaces the capped 100-item SELECT with an AJAX-powered search that can
 * reach every episode, no matter how large the catalog. The saved setting
 * remains a plain episode post ID, so existing widget settings keep working.
 *
 * @package EPM
 */

namespace EPM\Elementor\Controls;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EpisodeSelectControl extends \Elementor\Base_Data_Control {

	/**
	 * Control type slug.
	 *
	 * @return string
	 */
	public function get_type(): string {
		return 'epm_episode_select';
	}

	/**
	 * Default control settings.
	 *
	 * @return array<string, mixed>
	 */
	protected function get_default_settings(): array {
		return [
			'label_block' => true,
			'description' => __( 'Search all episodes by title. Newest first.', 'elementor-podcast-manager' ),
		];
	}

	/**
	 * Enqueue the control's editor script.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		wp_enqueue_script(
			'epm-episode-select',
			EPM_URL . 'admin/js/epm-episode-select.js',
			[ 'jquery' ],
			EPM_VERSION,
			true
		);

		wp_enqueue_style( 'epm-episode-select', EPM_URL . 'admin/css/epm-episode-select.css', [], EPM_VERSION );

		wp_localize_script(
			'epm-episode-select',
			'epmEpisodeSelect',
			[
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'epm_episode_search' ),
				'searchLabel' => __( 'Type to search episodes…', 'elementor-podcast-manager' ),
				'noResults'   => __( 'No episodes found.', 'elementor-podcast-manager' ),
				'loading'     => __( 'Loading…', 'elementor-podcast-manager' ),
				'loadMore'    => __( 'Load more…', 'elementor-podcast-manager' ),
				'searchError' => __( 'Episodes could not be loaded. Try again or reload the editor. Your selection is kept.', 'elementor-podcast-manager' ),
				'currentError' => __( 'The selected episode could not be loaded. Try again or reload the editor. Your selection is kept.', 'elementor-podcast-manager' ),
				'unavailable' => __( 'The selected episode is unavailable or you do not have permission to view it. Choose another episode.', 'elementor-podcast-manager' ),
				'noSelection' => __( 'No episode selected. Search and choose an episode.', 'elementor-podcast-manager' ),
			]
		);
	}

	/**
	 * Underscore.js template for the control UI.
	 *
	 * @return void
	 */
	public function content_template(): void {
		?>
		<div class="elementor-control-field epm-episode-select">
			<label class="elementor-control-title epm-episode-select__label">{{{ data.label }}}</label>
			<div class="elementor-control-input-wrapper">
				<input type="text"
					class="epm-episode-select__search"
					placeholder="<?php echo esc_attr__( 'Type to search episodes…', 'elementor-podcast-manager' ); ?>"
					autocomplete="off"
					role="combobox"
					aria-expanded="false"
					aria-autocomplete="list" />
				<ul class="epm-episode-select__results" role="listbox" hidden></ul>
				<p class="epm-episode-select__status" role="status" aria-live="polite"></p>
				<button type="button" class="elementor-button epm-episode-select__retry" hidden><?php esc_html_e( 'Try again', 'elementor-podcast-manager' ); ?></button>
				<button type="button" class="elementor-button epm-episode-select__more" hidden><?php esc_html_e( 'Load more…', 'elementor-podcast-manager' ); ?></button>
				<p class="epm-episode-select__current" role="status" aria-live="polite"></p>
				<button type="button" class="elementor-button epm-episode-select__current-retry" hidden><?php esc_html_e( 'Reload selected episode', 'elementor-podcast-manager' ); ?></button>
				<button type="button" class="elementor-button epm-episode-select__clear" hidden><?php esc_html_e( 'Clear selection', 'elementor-podcast-manager' ); ?></button>
				<p class="elementor-control-field-description epm-episode-select__hint"><?php esc_html_e( 'Use the arrow keys to browse results, Enter to choose and Escape to close.', 'elementor-podcast-manager' ); ?></p>
			</div>
			<# if ( data.description ) { #>
				<div class="elementor-control-field-description">{{{ data.description }}}</div>
			<# } #>
		</div>
		<?php
	}
}
