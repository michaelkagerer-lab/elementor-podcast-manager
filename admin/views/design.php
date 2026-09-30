<?php
/**
 * Design (Global Podcast Styles) view.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$design  = epm()->design->all();
$presets = epm()->presets->all();

$active_preset_id = (string) ( $design['preset'] ?? 'neutral' );
$active_preset    = epm()->presets->get( $active_preset_id );

// Handle preset application.
if ( isset( $_POST['epm_apply_preset'] ) && check_admin_referer( 'epm_design' ) ) {
	if ( \EPM\Capabilities::can_manage_podcast() ) {
		$preset_id = sanitize_key( $_POST['epm_preset'] ?? '' );
		if ( epm()->design->apply_preset( $preset_id ) ) {
			$design           = epm()->design->all();
			$active_preset_id = (string) ( $design['preset'] ?? 'neutral' );
			$active_preset    = epm()->presets->get( $active_preset_id );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Preset applied. You can still adjust every value below.', 'elementor-podcast-manager' ) . '</p></div>';
		}
	}
}

// Import result notices.
$design_notice = sanitize_key( $_GET['epm_design'] ?? '' );
if ( 'imported' === $design_notice ) {
	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Design imported successfully.', 'elementor-podcast-manager' ) . '</p></div>';
	$design           = epm()->design->all();
	$active_preset_id = (string) ( $design['preset'] ?? 'neutral' );
	$active_preset    = epm()->presets->get( $active_preset_id );
} elseif ( 'import-error' === $design_notice ) {
	$import_error = get_transient( 'epm_design_import_error_' . get_current_user_id() );
	if ( is_string( $import_error ) && '' !== $import_error ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $import_error ) . '</p></div>';
		delete_transient( 'epm_design_import_error_' . get_current_user_id() );
	}
}

// Effective design: which values are customized vs inherited from the preset.
$token_labels = [
	'accent'               => __( 'Accent color', 'elementor-podcast-manager' ),
	'on_accent'            => __( 'On-accent color', 'elementor-podcast-manager' ),
	'text'                 => __( 'Text color', 'elementor-podcast-manager' ),
	'muted'                => __( 'Muted text', 'elementor-podcast-manager' ),
	'background'           => __( 'Background', 'elementor-podcast-manager' ),
	'surface'              => __( 'Surface', 'elementor-podcast-manager' ),
	'border_color'         => __( 'Border color', 'elementor-podcast-manager' ),
	'border_radius'        => __( 'Border radius', 'elementor-podcast-manager' ),
	'artwork_radius'       => __( 'Artwork radius', 'elementor-podcast-manager' ),
	'spacing'              => __( 'Spacing', 'elementor-podcast-manager' ),
	'title_font_size'      => __( 'Title size', 'elementor-podcast-manager' ),
	'meta_font_size'       => __( 'Metadata size', 'elementor-podcast-manager' ),
	'default_player_layout'  => __( 'Default player layout', 'elementor-podcast-manager' ),
	'default_episode_layout' => __( 'Default episode layout', 'elementor-podcast-manager' ),
];

$preset_tokens = ( $active_preset && is_array( $active_preset['tokens'] ?? null ) )
	? $active_preset['tokens']
	: [];

$customized = [];
$inherited  = [];
foreach ( $token_labels as $key => $label ) {
	$baseline = isset( $preset_tokens[ $key ] )
		? (string) $preset_tokens[ $key ]
		: (string) \EPM\DesignSettings::defaults()[ $key ];

	if ( (string) ( $design[ $key ] ?? '' ) !== $baseline ) {
		$customized[ $key ] = $label;
	} else {
		$inherited[ $key ] = $label;
	}
}

$preset_extras = [
	'visibility'  => [
		'label'  => __( 'Visibility defaults', 'elementor-podcast-manager' ),
		'values' => (array) epm()->design->get( 'preset_visibility' ),
	],
	'player'      => [
		'label'  => __( 'Player defaults', 'elementor-podcast-manager' ),
		'values' => (array) epm()->design->get( 'preset_player' ),
	],
	'episodeList' => [
		'label'  => __( 'Episode list defaults', 'elementor-podcast-manager' ),
		'values' => (array) epm()->design->get( 'preset_episode_list' ),
	],
];
?>
<div class="wrap epm-design">
	<h1><?php esc_html_e( 'Podcast Design', 'elementor-podcast-manager' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Global Podcast Styles: default appearance for all podcast components. Elementor widgets can inherit these values or override them individually.', 'elementor-podcast-manager' ); ?>
	</p>

	<div class="epm-card epm-design-summary">
		<h2><?php esc_html_e( 'Effective design', 'elementor-podcast-manager' ); ?></h2>
		<p>
			<?php esc_html_e( 'Active preset:', 'elementor-podcast-manager' ); ?>
			<strong><?php echo $active_preset ? esc_html( $active_preset['name'] ) : esc_html( $active_preset_id ); ?></strong>
		</p>
		<?php if ( ! empty( $customized ) ) : ?>
			<p>
				<span class="epm-badge epm-badge--custom"><?php echo esc_html( (string) count( $customized ) ); ?></span>
				<?php esc_html_e( 'customized:', 'elementor-podcast-manager' ); ?>
				<?php echo esc_html( implode( ', ', $customized ) ); ?>
			</p>
		<?php else : ?>
			<p><?php esc_html_e( 'Nothing customized — every value matches the preset.', 'elementor-podcast-manager' ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $inherited ) ) : ?>
			<p class="description">
				<?php esc_html_e( 'Inherited from preset:', 'elementor-podcast-manager' ); ?>
				<?php echo esc_html( implode( ', ', $inherited ) ); ?>
			</p>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Preset behavior defaults in effect', 'elementor-podcast-manager' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Installed by the active preset. Widgets use these as their “inherit global styles” defaults.', 'elementor-podcast-manager' ); ?></p>
		<?php foreach ( $preset_extras as $group ) : ?>
			<h4><?php echo esc_html( $group['label'] ); ?></h4>
			<?php if ( empty( $group['values'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'None defined.', 'elementor-podcast-manager' ); ?></p>
			<?php else : ?>
				<table class="epm-design-flags">
					<tbody>
						<?php foreach ( $group['values'] as $flag => $on ) : ?>
							<tr>
								<th scope="row"><code><?php echo esc_html( (string) $flag ); ?></code></th>
								<td>
									<span class="epm-badge <?php echo $on ? 'epm-badge--on' : 'epm-badge--off'; ?>">
										<?php echo $on ? esc_html__( 'On', 'elementor-podcast-manager' ) : esc_html__( 'Off', 'elementor-podcast-manager' ); ?>
									</span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>

	<div class="epm-card">
		<h2><?php esc_html_e( 'Design Preset', 'elementor-podcast-manager' ); ?></h2>
		<p class="description"><?php esc_html_e( 'A preset initializes the values below. It never locks styling.', 'elementor-podcast-manager' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'epm_design' ); ?>
			<select name="epm_preset">
				<?php foreach ( $presets as $id => $preset ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $active_preset_id, $id ); ?>>
						<?php echo esc_html( $preset['name'] ); ?> — <?php echo esc_html( $preset['description'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Apply Preset', 'elementor-podcast-manager' ), 'secondary', 'epm_apply_preset', false ); ?>
		</form>
	</div>

	<form method="post" action="options.php">
		<?php settings_fields( 'epm_design_settings_group' ); ?>
		<?php /* Preserve preset identity across customized saves. */ ?>
		<input type="hidden" name="epm_design_settings[preset]" value="<?php echo esc_attr( $active_preset_id ); ?>" />

		<h2><?php esc_html_e( 'Colors', 'elementor-podcast-manager' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php foreach ( [ 'accent' => __( 'Accent color', 'elementor-podcast-manager' ), 'on_accent' => __( 'Text on accent', 'elementor-podcast-manager' ), 'text' => __( 'Text color', 'elementor-podcast-manager' ), 'muted' => __( 'Muted text', 'elementor-podcast-manager' ), 'background' => __( 'Background', 'elementor-podcast-manager' ), 'surface' => __( 'Surface', 'elementor-podcast-manager' ), 'border_color' => __( 'Border color', 'elementor-podcast-manager' ) ] as $key => $label ) : ?>
				<tr>
					<th scope="row"><label for="epm-d-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td><input type="color" id="epm-d-<?php echo esc_attr( $key ); ?>" name="epm_design_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $design[ $key ] ); ?>" /></td>
				</tr>
			<?php endforeach; ?>
		</table>

		<h2><?php esc_html_e( 'Shape & Spacing', 'elementor-podcast-manager' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php foreach ( [ 'border_radius' => __( 'Border radius (px)', 'elementor-podcast-manager' ), 'artwork_radius' => __( 'Artwork radius (px)', 'elementor-podcast-manager' ), 'spacing' => __( 'Standard spacing (px)', 'elementor-podcast-manager' ), 'title_font_size' => __( 'Player title size (px)', 'elementor-podcast-manager' ), 'meta_font_size' => __( 'Metadata size (px)', 'elementor-podcast-manager' ) ] as $key => $label ) : ?>
				<tr>
					<th scope="row"><label for="epm-d-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td><input type="number" id="epm-d-<?php echo esc_attr( $key ); ?>" name="epm_design_settings[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $design[ $key ] ); ?>" class="small-text" min="0" max="200" /></td>
				</tr>
			<?php endforeach; ?>
		</table>

		<h2><?php esc_html_e( 'Default Layouts', 'elementor-podcast-manager' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="epm-d-player-layout"><?php esc_html_e( 'Default player layout', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<select id="epm-d-player-layout" name="epm_design_settings[default_player_layout]">
						<?php foreach ( [ 'minimal' => __( 'Minimal', 'elementor-podcast-manager' ), 'compact' => __( 'Compact', 'elementor-podcast-manager' ), 'editorial' => __( 'Editorial', 'elementor-podcast-manager' ), 'artwork' => __( 'Artwork', 'elementor-podcast-manager' ), 'full' => __( 'Full', 'elementor-podcast-manager' ) ] as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $design['default_player_layout'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-d-episode-layout"><?php esc_html_e( 'Default episode layout', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<select id="epm-d-episode-layout" name="epm_design_settings[default_episode_layout]">
						<?php foreach ( [ 'list' => __( 'List', 'elementor-podcast-manager' ), 'editorial-rows' => __( 'Editorial Rows', 'elementor-podcast-manager' ), 'cards' => __( 'Cards', 'elementor-podcast-manager' ), 'grid' => __( 'Grid', 'elementor-podcast-manager' ), 'minimal' => __( 'Minimal List', 'elementor-podcast-manager' ) ] as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $design['default_episode_layout'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save Design', 'elementor-podcast-manager' ) ); ?>
	</form>

	<div class="epm-card">
		<h2><?php esc_html_e( 'Export / Import', 'elementor-podcast-manager' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Move this client design to another site. The export contains visual tokens only — no episodes, media, content or URLs.', 'elementor-podcast-manager' ); ?>
		</p>
		<h3><?php esc_html_e( 'Export design', 'elementor-podcast-manager' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="epm_design_export" />
			<?php wp_nonce_field( 'epm_design_export' ); ?>
			<?php submit_button( __( 'Export Design', 'elementor-podcast-manager' ), 'secondary', 'submit', false ); ?>
		</form>
		<h3><?php esc_html_e( 'Import design', 'elementor-podcast-manager' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="epm_design_import" />
			<?php wp_nonce_field( 'epm_design_import' ); ?>
			<p>
				<input type="file" name="epm_design_file" accept=".json,application/json" required />
			</p>
			<?php submit_button( __( 'Import Design', 'elementor-podcast-manager' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>
</div>
