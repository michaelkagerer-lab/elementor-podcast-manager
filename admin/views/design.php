<?php
/**
 * Design screen (Global Podcast Styles).
 *
 * Left: collapsible presets, upgrade suggestions, one appearance/details
 * form with named disclosures, what differs from the preset,
 * and export/import. Right: a sticky live preview that renders the real
 * components exactly as the site does — the Podcast Player widget, the
 * automatic episode page player and the Episode List widget with their
 * defaults (EPM\Details), and subscribe links; admin/js/epm-design.js
 * updates its --epm-* variables and layouts as fields or preset tiles
 * change, using the table from EPM\Admin::design_token_map().
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use EPM\Admin;
use EPM\DesignSettings;
use EPM\Details;
use EPM\EpisodeTemplate;

$epm_design    = epm()->design->all();
$epm_presets   = epm()->presets->all();
$epm_active_id = (string) ( $epm_design['preset'] ?? 'neutral' );
$epm_active    = epm()->presets->get( $epm_active_id );
$epm_baseline  = $epm_active ? Admin::preset_values( $epm_active ) : Admin::preset_values( [ 'tokens' => [] ] );

// Result of a redirect (preset applied, import).
$epm_notice = isset( $_GET['epm_design'] ) ? sanitize_key( wp_unslash( (string) $_GET['epm_design'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

$epm_choice_labels = [
	'button_shape' => [
		'rounded' => __( 'Rounded', 'elementor-podcast-manager' ),
		'pill'    => __( 'Pill', 'elementor-podcast-manager' ),
		'square'  => __( 'Square', 'elementor-podcast-manager' ),
	],
	'shadow'       => [
		'none'   => __( 'None', 'elementor-podcast-manager' ),
		'soft'   => __( 'Soft', 'elementor-podcast-manager' ),
		'lifted' => __( 'Lifted', 'elementor-podcast-manager' ),
	],
	'font_family'  => [
		'inherit' => __( 'Theme font', 'elementor-podcast-manager' ),
		'system'  => __( 'System', 'elementor-podcast-manager' ),
		'serif'   => __( 'Serif', 'elementor-podcast-manager' ),
		'rounded' => __( 'Rounded', 'elementor-podcast-manager' ),
		'mono'    => __( 'Monospace', 'elementor-podcast-manager' ),
	],
];

$epm_player_layouts = [
	'minimal'   => __( 'Minimal', 'elementor-podcast-manager' ),
	'compact'   => __( 'Compact', 'elementor-podcast-manager' ),
	'editorial' => __( 'Editorial', 'elementor-podcast-manager' ),
	'artwork'   => __( 'Artwork', 'elementor-podcast-manager' ),
	'full'      => __( 'Full', 'elementor-podcast-manager' ),
];

$epm_episode_layouts = [
	'list'           => __( 'List', 'elementor-podcast-manager' ),
	'editorial-rows' => __( 'Editorial rows', 'elementor-podcast-manager' ),
	'cards'          => __( 'Cards', 'elementor-podcast-manager' ),
	'grid'           => __( 'Grid', 'elementor-podcast-manager' ),
	'minimal'        => __( 'Minimal list', 'elementor-podcast-manager' ),
];

$epm_sections = [
	'colors' => [
		'title'  => __( 'Colors', 'elementor-podcast-manager' ),
		'lede'   => __( 'Start with your site’s background, then pick text and accent colors that read well on it. The contrast check below updates as you go.', 'elementor-podcast-manager' ),
		'fields' => [
			'background'   => [ 'type' => 'color', 'label' => __( 'Background', 'elementor-podcast-manager' ), 'help' => __( 'Players and the area around podcast components. Usually your site’s background color.', 'elementor-podcast-manager' ) ],
			'surface'      => [ 'type' => 'color', 'label' => __( 'Surface', 'elementor-podcast-manager' ), 'help' => __( 'Episode cards, the sticky player and error messages.', 'elementor-podcast-manager' ) ],
			'text'         => [ 'type' => 'color', 'label' => __( 'Text', 'elementor-podcast-manager' ), 'help' => __( 'Titles and body text.', 'elementor-podcast-manager' ) ],
			'muted'        => [ 'type' => 'color', 'label' => __( 'Muted text', 'elementor-podcast-manager' ), 'help' => __( 'Dates, durations and other secondary details.', 'elementor-podcast-manager' ) ],
			'accent'       => [ 'type' => 'color', 'label' => __( 'Accent', 'elementor-podcast-manager' ), 'help' => __( 'Play buttons, links and the played part of timelines.', 'elementor-podcast-manager' ) ],
			'on_accent'    => [ 'type' => 'color', 'label' => __( 'Text on accent', 'elementor-podcast-manager' ), 'help' => __( 'Icons and text on accent-colored buttons.', 'elementor-podcast-manager' ) ],
			'border_color' => [ 'type' => 'color', 'label' => __( 'Borders', 'elementor-podcast-manager' ), 'help' => __( 'Dividers and outlines.', 'elementor-podcast-manager' ) ],
			'track_color'  => [ 'type' => 'track', 'label' => __( 'Timeline track', 'elementor-podcast-manager' ), 'help' => __( 'The unplayed part of timelines. Leave it empty (Automatic) to use the muted text color.', 'elementor-podcast-manager' ) ],
		],
	],
	'shape'  => [
		'title'  => __( 'Shape and depth', 'elementor-podcast-manager' ),
		'lede'   => __( 'Corners, button shapes and shadows. Softer shapes feel friendly, square ones precise.', 'elementor-podcast-manager' ),
		'fields' => [
			'border_radius'  => [ 'type' => 'px', 'label' => __( 'Corner radius', 'elementor-podcast-manager' ), 'help' => __( 'Rounds players, cards and the sticky player.', 'elementor-podcast-manager' ) ],
			'artwork_radius' => [ 'type' => 'px', 'label' => __( 'Artwork corner radius', 'elementor-podcast-manager' ), 'help' => __( 'Rounds episode and podcast artwork.', 'elementor-podcast-manager' ) ],
			'button_shape'   => [ 'type' => 'choice', 'label' => __( 'Button shape', 'elementor-podcast-manager' ), 'help' => __( 'Text buttons such as Play, playback speed and subscribe links. The round play button keeps its shape.', 'elementor-podcast-manager' ) ],
			'shadow'         => [ 'type' => 'choice', 'label' => __( 'Shadow', 'elementor-podcast-manager' ), 'help' => __( 'Depth for players, cards and the sticky player.', 'elementor-podcast-manager' ) ],
		],
	],
	'type'   => [
		'title'  => __( 'Typography', 'elementor-podcast-manager' ),
		'lede'   => __( 'Theme font keeps the fonts of your theme and Elementor. Pick another font only when podcast components should stand apart.', 'elementor-podcast-manager' ),
		'fields' => [
			'font_family'     => [ 'type' => 'choice', 'label' => __( 'Font', 'elementor-podcast-manager' ), 'help' => __( 'Uses fonts already on your visitors’ devices, so nothing extra loads.', 'elementor-podcast-manager' ) ],
			'title_font_size' => [ 'type' => 'px', 'label' => __( 'Player title size', 'elementor-podcast-manager' ), 'help' => __( 'Episode titles in players.', 'elementor-podcast-manager' ) ],
			'meta_font_size'  => [ 'type' => 'px', 'label' => __( 'Details size', 'elementor-podcast-manager' ), 'help' => __( 'Dates, durations and guest names.', 'elementor-podcast-manager' ) ],
		],
	],
	'layout' => [
		'title'  => __( 'Layout defaults', 'elementor-podcast-manager' ),
		'lede'   => __( 'Widgets and shortcodes use these unless you choose another layout for them.', 'elementor-podcast-manager' ),
		'fields' => [
			'spacing'                => [ 'type' => 'px', 'label' => __( 'Spacing', 'elementor-podcast-manager' ), 'help' => __( 'Space between the parts of a component and between list items.', 'elementor-podcast-manager' ) ],
			'default_player_layout'  => [ 'type' => 'select', 'label' => __( 'Player layout', 'elementor-podcast-manager' ), 'help' => __( 'Minimal shows the essentials; Artwork and Full add large artwork and more controls.', 'elementor-podcast-manager' ), 'options' => $epm_player_layouts ],
			'default_episode_layout' => [ 'type' => 'select', 'label' => __( 'Episode list layout', 'elementor-podcast-manager' ), 'help' => __( 'Rows suit long archives; cards and grids suit shows with strong artwork.', 'elementor-podcast-manager' ), 'options' => $epm_episode_layouts ],
		],
	],
];

// What differs from the active preset (for the summary).
$epm_labels     = [];
$epm_customized = [];
foreach ( $epm_sections as $epm_section ) {
	foreach ( $epm_section['fields'] as $epm_key => $epm_field ) {
		$epm_labels[ $epm_key ] = $epm_field['label'];
		if ( (string) ( $epm_design[ $epm_key ] ?? '' ) !== (string) ( $epm_baseline[ $epm_key ] ?? '' ) ) {
			$epm_customized[ $epm_key ] = $epm_field['label'];
		}
	}
}

$epm_site_details = Details::sanitize_map( $epm_design['details'] ?? [] );
$epm_suggested    = Details::suggested_changes();
$epm_details_set  = 0;
foreach ( $epm_site_details as $epm_flags ) {
	$epm_details_set += count( $epm_flags );
}

$epm_preview       = Admin::design_preview_episodes();
$epm_renderer      = epm()->renderer;
$epm_player_layout = (string) $epm_design['default_player_layout'];
$epm_list_layout   = (string) $epm_design['default_episode_layout'];
$epm_is_cards      = in_array( $epm_list_layout, [ 'cards', 'grid' ], true );

/**
 * Inline style attribute from CSS custom properties.
 *
 * @param array<string, string> $vars Property => value.
 * @return string Escaped attribute value.
 */
$epm_style = static function ( array $vars ): string {
	$parts = [];
	foreach ( $vars as $name => $value ) {
		$parts[] = $name . ':' . $value;
	}
	return esc_attr( implode( ';', $parts ) );
};
?>
<div class="wrap epm-app epm-design" data-epm-design>
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Design', 'elementor-podcast-manager' ); ?></h1>
	<hr class="wp-header-end" />
	<p class="epm-design__lede"><?php esc_html_e( 'How the player, episode lists and subscribe links look on your site. Elementor widgets use these values unless you style a widget yourself.', 'elementor-podcast-manager' ); ?></p>

	<p class="epm-design__inheritance" data-epm-design-inheritance><?php esc_html_e( 'These are your podcast defaults. Each Elementor widget can override its layout, details or styles. Choose Podcast design in the widget to inherit again. Save design saves appearance and details together; applying a preset replaces both.', 'elementor-podcast-manager' ); ?></p>
	<?php settings_errors(); ?>
	<?php if ( 'preset-applied' === $epm_notice ) : ?>
		<div class="notice notice-success is-dismissible">
			<p>
				<?php
				printf(
					/* translators: %s: preset name */
					esc_html__( 'The %s preset is applied. Adjust any value below and save to make it your own.', 'elementor-podcast-manager' ),
					'<strong>' . esc_html( $epm_active ? (string) $epm_active['name'] : $epm_active_id ) . '</strong>'
				);
				?>
			</p>
		</div>
	<?php elseif ( 'preset-error' === $epm_notice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'That preset is not available. Choose one of the presets below.', 'elementor-podcast-manager' ); ?></p></div>
	<?php elseif ( 'imported' === $epm_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Design imported. The preview shows the imported values.', 'elementor-podcast-manager' ); ?></p></div>
	<?php elseif ( 'details-saved' === $epm_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Details saved. Widgets and shortcodes that leave a detail on Default show it this way now.', 'elementor-podcast-manager' ); ?></p></div>
	<?php elseif ( 'details-applied' === $epm_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The suggested details are applied. You can change each of them below.', 'elementor-podcast-manager' ); ?></p></div>
	<?php elseif ( 'details-dismissed' === $epm_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Your site keeps its details. The suggestion is gone.', 'elementor-podcast-manager' ); ?></p></div>
	<?php elseif ( 'details-reset' === $epm_notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Every widget and shortcode shows its built-in details again.', 'elementor-podcast-manager' ); ?></p></div>
	<?php elseif ( 'import-error' === $epm_notice ) : ?>
		<?php
		$epm_import_error = get_transient( 'epm_design_import_error_' . get_current_user_id() );
		if ( is_string( $epm_import_error ) && '' !== $epm_import_error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $epm_import_error ) . '</p></div>';
			delete_transient( 'epm_design_import_error_' . get_current_user_id() );
		}
		?>
	<?php endif; ?>

	<div class="epm-design__layout">
		<div class="epm-design__main">

			<!-- Presets -->
			<details class="epm-card epm-design__presets epm-fold"><summary><?php esc_html_e( 'Choose a preset', 'elementor-podcast-manager' ); ?> <span class="epm-muted"><?php echo esc_html( $epm_active ? $epm_active['name'] : $epm_active_id ); ?></span></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-epm-preset-form>
					<input type="hidden" name="action" value="epm_design_preset" />
					<?php wp_nonce_field( 'epm_design_preset', '_epm_preset_nonce', true, true ); ?>
					<fieldset class="epm-presets">
						<legend class="epm-presets__legend">
							<h2 class="epm-card__title" id="epm-presets-title"><?php esc_html_e( 'Presets', 'elementor-podcast-manager' ); ?></h2>
						</legend>
						<p class="epm-card__lede" id="epm-presets-lede"><?php esc_html_e( 'A starting point for every value below. Select a preset to see it in the preview, then apply it. Presets never lock anything: you can change every value afterwards.', 'elementor-podcast-manager' ); ?></p>
						<div class="epm-presets__grid">
							<?php foreach ( $epm_presets as $epm_id => $epm_preset ) : ?>
								<?php
								$epm_id     = (string) $epm_id;
								$epm_values = Admin::preset_values( (array) $epm_preset );
								$epm_vars   = Admin::design_css_vars( $epm_values );
								$epm_font   = DesignSettings::font_stacks()[ (string) $epm_values['font_family'] ] ?? '';
								$epm_dom_id = 'epm-preset-' . sanitize_html_class( $epm_id );
								?>
								<label class="epm-preset<?php echo $epm_id === $epm_active_id ? ' is-active' : ''; ?>" for="<?php echo esc_attr( $epm_dom_id ); ?>" data-epm-preset-tile="<?php echo esc_attr( $epm_id ); ?>">
									<span class="epm-preset__swatch<?php echo '' !== $epm_font ? ' has-font' : ''; ?>" style="<?php echo $epm_style( $epm_vars ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $epm_style. ?>" aria-hidden="true">
										<span class="epm-preset__surface">
											<span class="epm-preset__art"></span>
											<span class="epm-preset__lines">
												<span class="epm-preset__title">Aa</span>
												<span class="epm-preset__muted"></span>
												<span class="epm-preset__track"><span></span></span>
											</span>
										</span>
										<span class="epm-preset__button"></span>
									</span>
									<span class="epm-preset__head">
										<input type="radio" class="epm-preset__input" id="<?php echo esc_attr( $epm_dom_id ); ?>" name="epm_preset" value="<?php echo esc_attr( $epm_id ); ?>" <?php checked( $epm_id, $epm_active_id ); ?> aria-describedby="<?php echo esc_attr( $epm_dom_id . '-desc' ); ?>" />
										<span class="epm-preset__name"><?php echo esc_html( (string) $epm_preset['name'] ); ?></span>
										<?php if ( $epm_id === $epm_active_id ) : ?>
											<span class="epm-preset__badge"><?php esc_html_e( 'In use', 'elementor-podcast-manager' ); ?></span>
										<?php endif; ?>
									</span>
									<span class="epm-preset__desc" id="<?php echo esc_attr( $epm_dom_id . '-desc' ); ?>"><?php echo esc_html( (string) ( $epm_preset['description'] ?? '' ) ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>
					<div class="epm-presets__actions">
						<button type="submit" class="button button-primary" name="epm_apply_preset" value="1" data-epm-preset-apply><?php esc_html_e( 'Apply preset', 'elementor-podcast-manager' ); ?></button>
						<p class="epm-presets__note"><?php esc_html_e( 'Applying replaces the values below.', 'elementor-podcast-manager' ); ?></p>
					</div>

					<dialog class="epm-dialog" data-epm-preset-dialog aria-labelledby="epm-preset-dialog-title" aria-describedby="epm-preset-dialog-text">
						<h2 class="epm-dialog__title" id="epm-preset-dialog-title" data-epm-dialog-title><?php esc_html_e( 'Apply this preset?', 'elementor-podcast-manager' ); ?></h2>
						<p class="epm-dialog__text" id="epm-preset-dialog-text"><?php esc_html_e( 'The preset replaces every color, shape, font and layout default on this screen, and the details shown by default. Export your design first if you may want it back.', 'elementor-podcast-manager' ); ?></p>
							<div class="epm-dialog__details" data-epm-dialog-details hidden>
								<p class="epm-dialog__text"><?php esc_html_e( 'Details that change:', 'elementor-podcast-manager' ); ?></p>
								<ul class="epm-dialog__list" data-epm-dialog-details-list></ul>
							</div>
						<p class="epm-dialog__text epm-dialog__warn" data-epm-dialog-dirty hidden><?php esc_html_e( 'Your unsaved changes on this screen are discarded as well.', 'elementor-podcast-manager' ); ?></p>
						<div class="epm-dialog__actions">
							<button type="button" class="button" data-epm-dialog-cancel><?php esc_html_e( 'Cancel', 'elementor-podcast-manager' ); ?></button>
							<button type="button" class="button button-primary" data-epm-dialog-confirm><?php esc_html_e( 'Apply preset', 'elementor-podcast-manager' ); ?></button>
						</div>
					</dialog>
				</form>
			</details>

			<?php if ( ! empty( $epm_suggested ) ) : ?>
				<!-- Details a 1.1–1.3 preset stored but never applied -->
				<section class="epm-card epm-design__suggested" data-epm-details-suggested aria-labelledby="epm-suggested-title">
					<h2 class="epm-card__title" id="epm-suggested-title"><?php esc_html_e( 'Details your preset suggests', 'elementor-podcast-manager' ); ?></h2>
					<p class="epm-card__lede"><?php esc_html_e( 'Your preset also chose which details players and lists show. Earlier versions stored that choice without using it, so your site still shows these details as before. Apply the preset’s choice, or keep your site as it is.', 'elementor-podcast-manager' ); ?></p>
					<div class="epm-suggested__table-wrap">
						<table class="epm-suggested__table">
							<caption class="screen-reader-text"><?php esc_html_e( 'What applying the suggested details changes', 'elementor-podcast-manager' ); ?></caption>
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Detail', 'elementor-podcast-manager' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Where', 'elementor-podcast-manager' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Now', 'elementor-podcast-manager' ); ?></th>
									<th scope="col"><?php esc_html_e( 'After applying', 'elementor-podcast-manager' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $epm_suggested as $epm_change ) : ?>
									<tr>
										<th scope="row"><?php echo esc_html( $epm_change['label'] ); ?></th>
										<td data-label="<?php esc_attr_e( 'Where', 'elementor-podcast-manager' ); ?>"><?php echo esc_html( $epm_change['context_label'] ); ?></td>
										<td data-label="<?php esc_attr_e( 'Now', 'elementor-podcast-manager' ); ?>"><?php echo $epm_change['now'] ? esc_html__( 'Shown', 'elementor-podcast-manager' ) : esc_html__( 'Hidden', 'elementor-podcast-manager' ); ?></td>
										<td data-label="<?php esc_attr_e( 'After applying', 'elementor-podcast-manager' ); ?>"><span class="epm-badge <?php echo $epm_change['suggested'] ? 'epm-badge--ok' : ''; ?>"><?php echo $epm_change['suggested'] ? esc_html__( 'Shown', 'elementor-podcast-manager' ) : esc_html__( 'Hidden', 'elementor-podcast-manager' ); ?></span></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="epm-suggested__actions">
						<input type="hidden" name="action" value="epm_design_details" />
						<?php wp_nonce_field( 'epm_design_details', '_epm_suggestion_nonce', true, true ); ?>
						<button type="submit" class="button button-primary" name="epm_details_action" value="apply" data-epm-suggestion-apply><?php esc_html_e( 'Apply these details', 'elementor-podcast-manager' ); ?></button>
						<button type="submit" class="button" name="epm_details_action" value="dismiss" data-epm-suggestion-dismiss><?php esc_html_e( 'Keep my site as it is', 'elementor-podcast-manager' ); ?></button>
					</form>
				</section>
			<?php endif; ?>

			<!-- Design values -->
			<form method="post" action="options.php" class="epm-design__form" id="epm-design-form" data-epm-design-form>
				<?php settings_fields( 'epm_design_settings_group' ); ?>
				<input type="hidden" name="epm_design_settings[details_form]" value="1" />
				<input type="hidden" name="epm_design_settings[details_reset]" value="0" data-epm-details-reset-value />
				<?php /* Keeps the preset identity when customized values are saved. */ ?>
				<input type="hidden" name="epm_design_settings[preset]" value="<?php echo esc_attr( $epm_active_id ); ?>" />

				<?php foreach ( $epm_sections as $epm_section_id => $epm_section ) : ?>
					<details class="epm-card epm-design__section epm-fold">
						<summary id="epm-section-<?php echo esc_attr( $epm_section_id ); ?>"><?php echo esc_html( $epm_section['title'] ); ?></summary>
						<p class="epm-card__lede"><?php echo esc_html( $epm_section['lede'] ); ?></p>
						<div class="epm-design__fields epm-design__fields--<?php echo esc_attr( $epm_section_id ); ?>">
							<?php foreach ( $epm_section['fields'] as $epm_key => $epm_field ) : ?>
								<?php
								$epm_value    = (string) ( $epm_design[ $epm_key ] ?? '' );
								$epm_field_id = 'epm-d-' . $epm_key;
								$epm_help_id  = $epm_field_id . '-help';
								?>
								<?php if ( 'color' === $epm_field['type'] || 'track' === $epm_field['type'] ) : ?>
									<?php
									$epm_is_track = 'track' === $epm_field['type'];
									$epm_auto     = $epm_is_track && '' === $epm_value;
									$epm_picker   = '' !== $epm_value ? $epm_value : (string) $epm_design['muted'];
									?>
									<div class="epm-design-field epm-color<?php echo $epm_auto ? ' is-auto' : ''; ?>" data-epm-color="<?php echo esc_attr( $epm_key ); ?>">
										<label class="epm-design-field__label" id="<?php echo esc_attr( $epm_field_id ); ?>-label" for="<?php echo esc_attr( $epm_field_id ); ?>"><?php echo esc_html( $epm_field['label'] ); ?></label>
										<div class="epm-color__control">
											<input type="color" class="epm-color__picker" value="<?php echo esc_attr( $epm_picker ); ?>" data-epm-color-picker aria-labelledby="<?php echo esc_attr( $epm_field_id ); ?>-label <?php echo esc_attr( $epm_field_id ); ?>-picker"<?php disabled( $epm_auto ); ?> />
											<span class="screen-reader-text" id="<?php echo esc_attr( $epm_field_id ); ?>-picker"><?php esc_html_e( 'color picker', 'elementor-podcast-manager' ); ?></span>
											<input type="text" class="epm-color__hex code" id="<?php echo esc_attr( $epm_field_id ); ?>" name="epm_design_settings[<?php echo esc_attr( $epm_key ); ?>]" value="<?php echo esc_attr( $epm_value ); ?>" data-epm-token="<?php echo esc_attr( $epm_key ); ?>" pattern="#([0-9a-fA-F]{3}){1,2}" maxlength="7" size="8" spellcheck="false" autocomplete="off" aria-describedby="<?php echo esc_attr( $epm_help_id ); ?>"<?php echo $epm_auto ? ' readonly' : ''; ?><?php echo $epm_is_track ? '' : ' required'; ?> />
											<?php if ( $epm_is_track ) : ?>
												<label class="epm-color__auto" data-epm-track-auto-row hidden>
													<input type="checkbox" data-epm-track-auto<?php checked( $epm_auto ); ?> />
													<?php esc_html_e( 'Automatic', 'elementor-podcast-manager' ); ?>
												</label>
											<?php endif; ?>
										</div>
										<p class="description" id="<?php echo esc_attr( $epm_help_id ); ?>"><?php echo esc_html( $epm_field['help'] ); ?></p>
										<p class="epm-field-error" id="<?php echo esc_attr( $epm_field_id ); ?>-error" data-epm-field-error hidden></p>
									</div>
								<?php elseif ( 'px' === $epm_field['type'] ) : ?>
									<div class="epm-design-field epm-unit-field">
										<label class="epm-design-field__label" for="<?php echo esc_attr( $epm_field_id ); ?>"><?php echo esc_html( $epm_field['label'] ); ?></label>
										<div class="epm-unit">
											<input type="number" class="small-text" id="<?php echo esc_attr( $epm_field_id ); ?>" name="epm_design_settings[<?php echo esc_attr( $epm_key ); ?>]" value="<?php echo esc_attr( $epm_value ); ?>" min="0" max="200" step="1" inputmode="numeric" data-epm-token="<?php echo esc_attr( $epm_key ); ?>" aria-describedby="<?php echo esc_attr( $epm_field_id ); ?>-unit <?php echo esc_attr( $epm_help_id ); ?>" />
											<span class="epm-unit__suffix" id="<?php echo esc_attr( $epm_field_id ); ?>-unit"><?php echo esc_html_x( 'px', 'pixels', 'elementor-podcast-manager' ); ?></span>
										</div>
										<p class="description" id="<?php echo esc_attr( $epm_help_id ); ?>"><?php echo esc_html( $epm_field['help'] ); ?></p>
										<p class="epm-field-error" id="<?php echo esc_attr( $epm_field_id ); ?>-error" data-epm-field-error hidden></p>
									</div>
								<?php elseif ( 'choice' === $epm_field['type'] ) : ?>
									<?php
									$epm_values_map = Admin::design_token_map()[ $epm_key ]['values'] ?? [];
									?>
									<fieldset class="epm-design-field epm-chips epm-chips--<?php echo esc_attr( $epm_key ); ?>" aria-describedby="<?php echo esc_attr( $epm_help_id ); ?>">
										<legend class="epm-design-field__label"><?php echo esc_html( $epm_field['label'] ); ?></legend>
										<div class="epm-chips__row">
											<?php foreach ( (array) $epm_values_map as $epm_choice => $epm_css ) : ?>
												<?php
												$epm_choice = (string) $epm_choice;
												$epm_label  = $epm_choice_labels[ $epm_key ][ $epm_choice ] ?? ucfirst( $epm_choice );
												if ( 'button_shape' === $epm_key ) {
													$epm_sample = '--epm-sample-radius:' . $epm_css;
												} elseif ( 'shadow' === $epm_key ) {
													$epm_sample = '--epm-sample-shadow:' . $epm_css;
												} else {
													$epm_sample = '' !== (string) $epm_css ? '--epm-sample-font:' . $epm_css : '';
												}
												?>
												<label class="epm-chip">
													<input type="radio" name="epm_design_settings[<?php echo esc_attr( $epm_key ); ?>]" value="<?php echo esc_attr( $epm_choice ); ?>" data-epm-token="<?php echo esc_attr( $epm_key ); ?>" <?php checked( $epm_value, $epm_choice ); ?> />
													<span class="epm-chip__sample" style="<?php echo esc_attr( $epm_sample ); ?>" aria-hidden="true"><?php echo 'font_family' === $epm_key ? 'Aa' : ''; ?></span>
													<span class="epm-chip__label"><?php echo esc_html( $epm_label ); ?></span>
												</label>
											<?php endforeach; ?>
										</div>
										<p class="description" id="<?php echo esc_attr( $epm_help_id ); ?>"><?php echo esc_html( $epm_field['help'] ); ?></p>
									</fieldset>
								<?php elseif ( 'select' === $epm_field['type'] ) : ?>
									<div class="epm-design-field">
										<label class="epm-design-field__label" for="<?php echo esc_attr( $epm_field_id ); ?>"><?php echo esc_html( $epm_field['label'] ); ?></label>
										<select id="<?php echo esc_attr( $epm_field_id ); ?>" name="epm_design_settings[<?php echo esc_attr( $epm_key ); ?>]" data-epm-token="<?php echo esc_attr( $epm_key ); ?>" aria-describedby="<?php echo esc_attr( $epm_help_id ); ?>">
											<?php foreach ( $epm_field['options'] as $epm_option => $epm_option_label ) : ?>
												<option value="<?php echo esc_attr( $epm_option ); ?>" <?php selected( $epm_value, $epm_option ); ?>><?php echo esc_html( $epm_option_label ); ?></option>
											<?php endforeach; ?>
										</select>
										<p class="description" id="<?php echo esc_attr( $epm_help_id ); ?>"><?php echo esc_html( $epm_field['help'] ); ?></p>
									</div>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>

						<?php if ( 'colors' === $epm_section_id ) : ?>
							<div class="epm-contrast-check" data-epm-contrast>
								<h3 class="epm-design__subheading" id="epm-contrast-title"><?php esc_html_e( 'Contrast check', 'elementor-podcast-manager' ); ?></h3>
								<p class="description"><?php esc_html_e( 'Text needs a contrast ratio of at least 4.5:1 and the timeline track 3:1 (WCAG AA), on the background and on the surface of episode cards and the sticky player, so everyone can read and use them.', 'elementor-podcast-manager' ); ?></p>
								<ul class="epm-contrast-list" aria-labelledby="epm-contrast-title">
									<?php foreach ( Admin::contrast_pairs() as $epm_pair ) : ?>
										<?php
										$epm_fg    = (string) ( $epm_design[ $epm_pair['fg'] ] ?? '' );
										$epm_fg    = '' !== $epm_fg ? $epm_fg : (string) $epm_design['muted'];
										$epm_bg    = (string) ( $epm_design[ $epm_pair['bg'] ] ?? '' );
										$epm_ratio = floor( Admin::contrast_ratio( $epm_fg, $epm_bg ) * 10 ) / 10;
										$epm_pass  = $epm_ratio >= $epm_pair['min'];
										?>
										<li class="epm-contrast-item <?php echo $epm_pass ? 'is-pass' : 'is-fail'; ?>" data-epm-contrast-pair data-fg="<?php echo esc_attr( $epm_pair['fg'] ); ?>" data-bg="<?php echo esc_attr( $epm_pair['bg'] ); ?>" data-min="<?php echo esc_attr( (string) $epm_pair['min'] ); ?>" data-label="<?php echo esc_attr( $epm_pair['label'] ); ?>">
											<span class="epm-contrast-item__sample" style="<?php echo esc_attr( 'color:' . $epm_fg . ';background:' . $epm_bg ); ?>" aria-hidden="true" data-epm-contrast-sample>Aa</span>
											<span class="epm-contrast-item__label"><?php echo esc_html( $epm_pair['label'] ); ?></span>
											<span class="epm-contrast-item__ratio" data-epm-contrast-ratio><?php echo esc_html( number_format_i18n( $epm_ratio, 1 ) . ':1' ); ?></span>
											<span class="epm-contrast-badge" data-epm-contrast-badge>
												<span class="epm-contrast-badge__icon" aria-hidden="true"></span>
												<span data-epm-contrast-verdict>
													<?php
													echo esc_html(
														$epm_pass
															? __( 'Pass', 'elementor-podcast-manager' )
															/* translators: %s: required contrast ratio, e.g. 4.5 */
															: sprintf( __( 'Too low, needs %s:1', 'elementor-podcast-manager' ), number_format_i18n( $epm_pair['min'], 1 ) )
													);
													?>
												</span>
											</span>
										</li>
									<?php endforeach; ?>
								</ul>
							</div>
						<?php endif; ?>
					</details>
				<?php endforeach; ?>


			<!-- Details shown by default -->
			<details class="epm-card epm-design__details epm-fold" id="epm-details" data-epm-details-form><summary id="epm-details-title"><?php esc_html_e( 'Details shown by default', 'elementor-podcast-manager' ); ?></summary>
					<p class="epm-card__lede"><?php esc_html_e( 'Which parts players and lists show where a widget or shortcode leaves the choice to the site. A widget set to Show or Hide, or a shortcode attribute, always wins. Colors, fonts and the styles above never change these.', 'elementor-podcast-manager' ); ?></p>
					<div class="epm-details">
						<?php foreach ( Details::CONTEXTS as $epm_context ) : ?>
							<?php
							$epm_effective = Details::effective( $epm_context );
							$epm_legend_id = 'epm-details-' . $epm_context;
							?>
							<fieldset class="epm-details__group" data-epm-details-context="<?php echo esc_attr( $epm_context ); ?>" aria-describedby="<?php echo esc_attr( $epm_legend_id ); ?>-help">
								<legend class="epm-details__legend" id="<?php echo esc_attr( $epm_legend_id ); ?>"><?php echo esc_html( Details::context_label( $epm_context ) ); ?></legend>
								<p class="description epm-details__help" id="<?php echo esc_attr( $epm_legend_id ); ?>-help"><?php echo esc_html( Details::context_help( $epm_context ) ); ?></p>
								<ul class="epm-details__list">
									<?php foreach ( Details::flags( $epm_context ) as $epm_flag ) : ?>
										<?php
										if ( 'show_topics' === $epm_flag && ! taxonomy_exists( \EPM\Renderer::TOPIC_TAXONOMY ) ) {
											continue;
										}
										$epm_check_id = $epm_legend_id . '-' . str_replace( '_', '-', $epm_flag );
										?>
										<li>
											<label class="epm-check" for="<?php echo esc_attr( $epm_check_id ); ?>">
												<input type="hidden" name="epm_design_settings[details][<?php echo esc_attr( $epm_context ); ?>][<?php echo esc_attr( $epm_flag ); ?>]" value="0" />
												<input type="checkbox" id="<?php echo esc_attr( $epm_check_id ); ?>" name="epm_design_settings[details][<?php echo esc_attr( $epm_context ); ?>][<?php echo esc_attr( $epm_flag ); ?>]" value="1" <?php checked( ! empty( $epm_effective[ $epm_flag ] ) ); ?> aria-labelledby="<?php echo esc_attr( $epm_check_id ); ?>-label <?php echo esc_attr( $epm_legend_id ); ?>" />
												<span id="<?php echo esc_attr( $epm_check_id ); ?>-label"><?php echo esc_html( Details::label( $epm_flag ) ); ?></span>
											</label>
										</li>
									<?php endforeach; ?>
								</ul>
							</fieldset>
						<?php endforeach; ?>
					</div>
				<button type="button" class="button" data-epm-details-reset><?php esc_html_e( 'Use built-in details', 'elementor-podcast-manager' ); ?></button>
			</details>
				<div class="epm-design__savebar">
					<p class="epm-design__dirty" data-epm-dirty role="status"></p>
					<button type="reset" class="button" data-epm-design-reset hidden><?php esc_html_e( 'Discard changes', 'elementor-podcast-manager' ); ?></button>
					<?php submit_button( __( 'Save design', 'elementor-podcast-manager' ), 'primary', 'submit', false, [ 'id' => 'epm-design-save-submit' ] ); ?>
				</div>

			</form>

			<!-- What differs from the preset -->
			<details class="epm-card epm-design-summary">
				<summary>
					<span class="epm-design-summary__title">
						<?php
						printf(
							/* translators: %s: preset name */
							esc_html__( 'Active preset: %s', 'elementor-podcast-manager' ),
							'<strong>' . esc_html( $epm_active ? (string) $epm_active['name'] : $epm_active_id ) . '</strong>'
						);
						?>
					</span>
					<span class="epm-design-summary__count">
						<?php
						echo esc_html(
							empty( $epm_customized )
								? __( 'No values changed', 'elementor-podcast-manager' )
								/* translators: %s: number of changed values */
								: sprintf( _n( '%s value changed', '%s values changed', count( $epm_customized ), 'elementor-podcast-manager' ), number_format_i18n( count( $epm_customized ) ) )
						);
						?>
					</span>
				</summary>
				<div class="epm-design-summary__body">
					<h3><?php esc_html_e( 'Changed from the preset', 'elementor-podcast-manager' ); ?></h3>
					<?php if ( empty( $epm_customized ) ) : ?>
						<p class="description"><?php esc_html_e( 'Every value matches the preset.', 'elementor-podcast-manager' ); ?></p>
					<?php else : ?>
						<ul class="epm-design-changes">
							<?php foreach ( $epm_customized as $epm_key => $epm_label ) : ?>
								<li><?php echo esc_html( $epm_label ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<h3><?php esc_html_e( 'Details shown by default', 'elementor-podcast-manager' ); ?></h3>
					<p class="description">
						<?php
						if ( 0 === $epm_details_set ) {
							esc_html_e( 'Built-in details: every widget and shortcode shows what it showed before.', 'elementor-podcast-manager' );
						} else {
							echo esc_html(
								sprintf(
									/* translators: %s: number of details */
									_n( '%s detail differs from the built-in details.', '%s details differ from the built-in details.', $epm_details_set, 'elementor-podcast-manager' ),
									number_format_i18n( $epm_details_set )
								)
							);
						}
						?>
						<a href="#epm-details"><?php esc_html_e( 'Review the details', 'elementor-podcast-manager' ); ?></a>
					</p>
				</div>
			</details>

			<!-- Export / import -->
			<details class="epm-card epm-design__io epm-fold"><summary id="epm-io-title"><?php esc_html_e( 'Export or import design', 'elementor-podcast-manager' ); ?></summary>
				<h2 class="epm-card__title"><?php esc_html_e( 'Move this design to another site', 'elementor-podcast-manager' ); ?></h2>
				<p class="epm-card__lede"><?php esc_html_e( 'The file holds design values only: no episodes, media, text or web addresses.', 'elementor-podcast-manager' ); ?></p>
				<div class="epm-design__io-grid">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-epm-design-export>
						<h3><?php esc_html_e( 'Export', 'elementor-podcast-manager' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Downloads the saved design as a .json file.', 'elementor-podcast-manager' ); ?></p>
						<input type="hidden" name="action" value="epm_design_export" />
						<?php wp_nonce_field( 'epm_design_export', '_epm_export_nonce', true, true ); ?>
						<?php submit_button( __( 'Export design file', 'elementor-podcast-manager' ), 'secondary', 'submit', false, [ 'id' => 'epm-design-export-submit' ] ); ?>
					</form>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<h3><?php esc_html_e( 'Import', 'elementor-podcast-manager' ); ?></h3>
						<input type="hidden" name="action" value="epm_design_import" />
						<?php wp_nonce_field( 'epm_design_import', '_epm_import_nonce', true, true ); ?>
						<label for="epm-design-file"><?php esc_html_e( 'Design file (.json)', 'elementor-podcast-manager' ); ?></label>
						<input type="file" id="epm-design-file" name="epm_design_file" accept=".json,application/json" required aria-describedby="epm-design-file-help" />
						<p class="description" id="epm-design-file-help"><?php esc_html_e( 'Replaces the current design. Export it first to keep a copy.', 'elementor-podcast-manager' ); ?></p>
						<?php submit_button( __( 'Import design file', 'elementor-podcast-manager' ), 'secondary', 'submit', false, [ 'id' => 'epm-design-import-submit' ] ); ?>
					</form>
				</div>
			</details>
		</div>

		<!-- Live preview -->
		<aside class="epm-design__preview" aria-labelledby="epm-preview-title">
			<div class="epm-design-preview" data-epm-preview>
				<div class="epm-design-preview__bar">
					<h2 class="epm-design-preview__title" id="epm-preview-title"><?php esc_html_e( 'Preview', 'elementor-podcast-manager' ); ?></h2>
				</div>
				<p class="epm-design-preview__status" data-epm-preview-status role="status"><?php esc_html_e( 'Your design', 'elementor-podcast-manager' ); ?></p>
				<div class="epm-design-preview__preset" data-epm-preview-preset hidden>
					<p data-epm-preview-preset-text></p>
					<button type="button" class="button button-small" data-epm-preview-mine><?php esc_html_e( 'Show my design', 'elementor-podcast-manager' ); ?></button>
				</div>
				<div class="epm-design-preview__viewport" data-epm-preview-viewport tabindex="0" role="region" aria-labelledby="epm-preview-title">
					<div class="epm-design-preview__canvas<?php echo '' !== (string) ( DesignSettings::font_stacks()[ (string) $epm_design['font_family'] ] ?? '' ) ? ' has-custom-font' : ''; ?>" data-epm-preview-canvas style="<?php echo $epm_style( Admin::design_css_vars( $epm_design ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $epm_style. ?>" inert>
						<p class="epm-design-preview__label"><?php esc_html_e( 'Player', 'elementor-podcast-manager' ); ?></p>
						<div data-epm-preview-part="player">
							<?php
							// What a Podcast Player widget with its defaults shows.
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer output is escaped.
							echo $epm_renderer->player(
								$epm_preview['player'],
								array_merge(
									Details::resolve( 'player' ),
									[
										'layout'    => '',
										'sticky'    => false,
										'player_id' => 'epm-design-preview-player',
									]
								)
							);
							?>
						</div>
						<p class="epm-design-preview__label"><?php esc_html_e( 'Episode page', 'elementor-podcast-manager' ); ?></p>
						<div data-epm-preview-part="episode-page">
							<?php
							// The player every episode page gets automatically.
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer output is escaped.
							echo $epm_renderer->player( $epm_preview['player'], array_merge( EpisodeTemplate::player_args( $epm_preview['player'] ), [ 'player_id' => 'epm-design-preview-episode-page' ] ) );
							?>
						</div>
						<p class="epm-design-preview__label"><?php esc_html_e( 'Episode list', 'elementor-podcast-manager' ); ?></p>
						<div data-epm-preview-part="list">
							<?php
							// What an Episode List widget with its defaults shows; the
							// other kind (cards or rows) waits hidden for a layout change.
							$epm_list_args = array_merge( Details::resolve( 'list' ), [ 'excerpt_length' => 20 ] );
							$epm_lists     = [
								'cards' => $epm_is_cards ? $epm_list_layout : 'cards',
								'rows'  => $epm_is_cards ? 'list' : $epm_list_layout,
							];
							if ( ! $epm_is_cards ) {
								$epm_lists = array_reverse( $epm_lists, true );
							}
							foreach ( $epm_lists as $epm_kind => $epm_layout ) :
								$epm_hidden = ( 'cards' === $epm_kind ) !== $epm_is_cards;
								?>
								<div data-epm-preview-list="<?php echo esc_attr( $epm_kind ); ?>"<?php echo $epm_hidden ? ' hidden' : ''; ?>>
									<?php echo $epm_renderer->episode_list_from_data( $epm_preview['list'], array_merge( $epm_list_args, [ 'layout' => $epm_layout ] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer output is escaped. ?>
								</div>
							<?php endforeach; ?>
						</div>
						<p class="epm-design-preview__label"><?php esc_html_e( 'Subscribe links', 'elementor-podcast-manager' ); ?></p>
						<?php echo $epm_renderer->subscribe_links( Admin::design_preview_links(), [ 'display' => 'icon-text' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer output is escaped. ?>
					</div>
				</div>
				<p class="epm-design-preview__foot">
					<?php
					echo esc_html(
						$epm_preview['sample']
							? __( 'Sample episodes are shown until you publish one. The preview updates as you edit; save the design to use it on your site.', 'elementor-podcast-manager' )
							: __( 'Shows your latest episodes. The preview updates as you edit; save the design to use it on your site.', 'elementor-podcast-manager' )
					);
					?>
				</p>
			</div>
		</aside>
	</div>
</div>
