<?php
/**
 * Global Podcast Styles (Podcast → Design). Layer 2.
 *
 * Design tokens only — no content, no episode data. Widgets may inherit
 * these values ("Use Global Podcast Styles") or override them per widget.
 *
 * Precedence (lowest wins → highest wins):
 *   1. Theme / Elementor Site Settings (inherited by the frontend CSS)
 *   2. Global Podcast Styles — this option (CSS custom properties on :root)
 *   3. Selected preset — initializes the option values below on "Apply Preset";
 *      values stay editable afterwards and customizations are preserved.
 *   4. Individual widget overrides ("Custom" style source in Elementor).
 *
 * Consumer API for widgets (inheritance defaults):
 *   epm()->design->get( 'default_player_layout' )        — token / default layout
 *   epm()->design->get_preset_value( 'visibility', 'show_artwork', true )
 *   epm()->design->get_preset_value( 'player', 'show_volume', true )
 *   epm()->design->get_preset_value( 'episodeList', '<key>', $default )
 * These return the values the active preset installed (or the site's
 * customizations of them); widgets use them as their "inherit" state.
 *
 * Portability: the option holds visual tokens ONLY — colors, sizes, layout
 * names, preset id, and the preset's visibility/player/episodeList maps.
 * It never holds attachment IDs, post IDs, URLs or content, so the whole
 * option is safe to export/import between sites (see EXPORT_VERSION and
 * Admin::handle_design_export()/handle_design_import()).
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DesignSettings {

	public const OPTION = 'epm_design_settings';

	/**
	 * Version of the design export/import format (epm-design).
	 * Bump when the payload structure changes; the importer rejects
	 * payloads with a higher (unknown future) version.
	 */
	public const EXPORT_VERSION = 1;

	/**
	 * Whether the token block was already printed on this request.
	 *
	 * @var bool
	 */
	private bool $tokens_printed = false;

	/**
	 * Default design tokens. Neutral on purpose — presets provide character.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return [
			'accent'               => '#1d4ed8',
			'on_accent'            => '#ffffff',
			'text'                 => '#111827',
			'muted'                => '#6b7280',
			'background'           => '#ffffff',
			'surface'              => '#f9fafb',
			'border_color'         => '#e5e7eb',
			'border_radius'        => '12',
			'artwork_radius'       => '8',
			'spacing'              => '24',
			'default_player_layout' => 'minimal',
			'default_episode_layout' => 'list',
			'title_font_size'      => '22',
			'meta_font_size'       => '14',
			'preset'               => 'neutral',
			// Preset-installed behavior maps (visibility/player/episodeList).
			// Written by apply_preset(); remain editable as plain option values.
			'preset_visibility'    => [],
			'preset_player'        => [],
			'preset_episode_list'  => [],
		];
	}

	/**
	 * Wire settings registration and frontend output.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		// The stylesheet's fallback tokens use :where(:root) (specificity 0),
		// so these :root tokens win regardless of document order. Printed once
		// in the head; the footer hook only covers themes without wp_head.
		add_action( 'wp_head', [ $this, 'output_tokens' ], 20 );
		add_action( 'wp_footer', [ $this, 'output_tokens' ], 1 );
	}

	/**
	 * Register the option.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'epm_design_settings_group',
			self::OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => self::defaults(),
				'auth_callback'     => [ Capabilities::class, 'can_manage_podcast' ],
			]
		);

		// options.php otherwise demands manage_options; align it with the filter.
		add_filter( 'option_page_capability_epm_design_settings_group', [ Capabilities::class, 'manage_podcast' ] );
	}

	/**
	 * Get a token value.
	 *
	 * @param string $key Token key.
	 * @return mixed
	 */
	public function get( string $key ) {
		$all      = $this->all();
		$defaults = self::defaults();

		return $all[ $key ] ?? $defaults[ $key ] ?? null;
	}

	/**
	 * Get all tokens merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION, [] );

		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * Sanitize tokens.
	 *
	 * Unknown keys are dropped. The stored preset identity is preserved
	 * unless the input explicitly names a different, registered preset —
	 * saving customized values must not silently reset the preset to
	 * 'neutral' (the design form carries the current id in a hidden field).
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return self::defaults();
		}

		$out = self::defaults();

		foreach ( [ 'accent', 'on_accent', 'text', 'muted', 'background', 'surface', 'border_color' ] as $key ) {
			$out[ $key ] = sanitize_hex_color( $input[ $key ] ?? '' ) ?: $out[ $key ];
		}

		foreach ( [ 'border_radius', 'artwork_radius', 'spacing', 'title_font_size', 'meta_font_size' ] as $key ) {
			$out[ $key ] = (string) max( 0, min( 200, absint( $input[ $key ] ?? $out[ $key ] ) ) );
		}

		$layouts = [ 'minimal', 'compact', 'editorial', 'artwork', 'full' ];
		$out['default_player_layout'] = in_array( $input['default_player_layout'] ?? '', $layouts, true )
			? $input['default_player_layout']
			: $out['default_player_layout'];

		$episode_layouts = [ 'list', 'editorial-rows', 'cards', 'grid', 'minimal' ];
		$out['default_episode_layout'] = in_array( $input['default_episode_layout'] ?? '', $episode_layouts, true )
			? $input['default_episode_layout']
			: $out['default_episode_layout'];

		// Preset identity: keep the stored id unless the input explicitly
		// names another registered preset.
		$stored        = get_option( self::OPTION, [] );
		$stored_preset = ( is_array( $stored ) && isset( $stored['preset'] ) )
			? sanitize_key( (string) $stored['preset'] )
			: 'neutral';

		if ( array_key_exists( 'preset', $input ) && '' !== (string) $input['preset'] ) {
			$candidate     = sanitize_key( (string) $input['preset'] );
			$out['preset'] = epm()->presets->get( $candidate ) ? $candidate : $stored_preset;
		} else {
			$out['preset'] = $stored_preset;
		}

		// Preset-installed behavior maps: arrays of boolean flags.
		// Explicit input wins (apply preset, import); otherwise the stored
		// maps are preserved so a normal token save cannot wipe them.
		$stored_maps = is_array( $stored ) ? $stored : [];
		foreach ( [ 'preset_visibility', 'preset_player', 'preset_episode_list' ] as $map_key ) {
			if ( array_key_exists( $map_key, $input ) ) {
				$out[ $map_key ] = $this->sanitize_flag_map( $input[ $map_key ] );
			} elseif ( isset( $stored_maps[ $map_key ] ) && is_array( $stored_maps[ $map_key ] ) ) {
				$out[ $map_key ] = $this->sanitize_flag_map( $stored_maps[ $map_key ] );
			} else {
				$out[ $map_key ] = [];
			}
		}

		return $out;
	}

	/**
	 * Sanitize a preset behavior map: string keys, boolean values.
	 * Used for the preset_visibility / preset_player / preset_episode_list
	 * option keys and for validating imported payloads.
	 *
	 * @param mixed $value Raw input.
	 * @return array<string, bool>
	 */
	public function sanitize_flag_map( $value ): array {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$out = [];
		foreach ( $value as $key => $flag ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ] = $this->to_bool( $flag );
		}

		return $out;
	}

	/**
	 * Coerce a mixed value to boolean.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	private function to_bool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 != $value;
		}

		$value = strtolower( trim( (string) $value ) );

		return in_array( $value, [ '1', 'true', 'yes', 'on' ], true );
	}

	/**
	 * Apply a preset: fills the design tokens AND the preset's behavior
	 * maps (visibility, player, episodeList) with the preset's values.
	 * Never locks styling — values remain editable afterwards as plain
	 * option values.
	 *
	 * @param string $preset_id Preset identifier.
	 * @return bool
	 */
	public function apply_preset( string $preset_id ): bool {
		$preset = epm()->presets->get( $preset_id );

		if ( ! $preset ) {
			return false;
		}

		$values = array_merge( self::defaults(), $preset['tokens'] ?? [] );
		$values['preset']             = $preset_id;
		$values['preset_visibility']   = $preset['visibility'] ?? [];
		$values['preset_player']       = $preset['player'] ?? [];
		$values['preset_episode_list'] = $preset['episodeList'] ?? [];

		update_option( self::OPTION, $this->sanitize( $values ) );

		return true;
	}

	/**
	 * Get one value from a preset-installed behavior map.
	 *
	 * Consumer API for the "inherit global styles" state of widgets, e.g.:
	 *   epm()->design->get_preset_value( 'visibility', 'show_artwork', true )
	 *   epm()->design->get_preset_value( 'player', 'show_volume', true )
	 *   epm()->design->get_preset_value( 'episodeList', 'show_guest', true )
	 *
	 * @param string $group   visibility|player|episodeList.
	 * @param string $key     Behavior key, e.g. show_artwork.
	 * @param mixed  $default Fallback when the group/key is unknown.
	 * @return mixed
	 */
	public function get_preset_value( string $group, string $key, $default = null ) {
		$map = [
			'visibility'  => 'preset_visibility',
			'player'      => 'preset_player',
			'episodeList' => 'preset_episode_list',
		];

		if ( ! isset( $map[ $group ] ) ) {
			return $default;
		}

		$values = $this->get( $map[ $group ] );

		if ( ! is_array( $values ) ) {
			return $default;
		}

		return $values[ $key ] ?? $default;
	}

	/**
	 * Output the design tokens as CSS custom properties on :root.
	 *
	 * Printed once per request (wp_head, or wp_footer as a fallback). The
	 * static fallback in epm-frontend.css is :where(:root) with specificity
	 * 0, so these tokens win regardless of document order. Specificity
	 * stays low (:root); widget overrides on {{WRAPPER}} win by specificity.
	 *
	 * @return void
	 */
	public function output_tokens(): void {
		if ( $this->tokens_printed ) {
			return;
		}
		$this->tokens_printed = true;

		$t = $this->all();

		$vars = [
			'--epm-accent'        => $t['accent'],
			'--epm-on-accent'     => $t['on_accent'],
			'--epm-text'          => $t['text'],
			'--epm-text-muted'    => $t['muted'],
			'--epm-background'    => $t['background'],
			'--epm-surface'       => $t['surface'],
			'--epm-border'        => $t['border_color'],
			'--epm-radius'        => $t['border_radius'] . 'px',
			'--epm-artwork-radius' => $t['artwork_radius'] . 'px',
			'--epm-gap'           => $t['spacing'] . 'px',
			'--epm-title-size'    => $t['title_font_size'] . 'px',
			'--epm-meta-size'     => $t['meta_font_size'] . 'px',
		];

		$css = ':root{';
		foreach ( $vars as $name => $value ) {
			$css .= $name . ':' . $value . ';';
		}
		$css .= '}';

		echo '<style id="epm-design-tokens">' . esc_html( $css ) . '</style>' . "\n";
	}
}
