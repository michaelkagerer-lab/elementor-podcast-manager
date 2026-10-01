<?php
/**
 * Global Podcast Styles (Podcast → Design). Layer 2.
 *
 * Design tokens — colors, sizes, shapes, fonts, layout names — and the
 * site's "Details shown by default". No content, no episode data.
 *
 * Two separate inheritances live in this option:
 *
 * 1. Looks (tokens). Precedence, lowest to highest:
 *    theme / Elementor Site Settings → these tokens (CSS custom properties
 *    on :root) → a widget's own style values (Style Source "Custom").
 *    A widget with "Use Podcast → Design styles" emits nothing and so
 *    inherits. Applying a preset fills the tokens; they stay editable.
 *
 * 2. Which details show (Details): explicit local value (a widget's
 *    Show/Hide, a shortcode attribute) → the site's details for the
 *    context ('details', sparse) → the built-in default of the consumer
 *    (what 1.3.0 showed). See Details::resolve(). Applying a preset
 *    replaces the site's details with the preset's.
 *
 * Option keys besides the tokens:
 *   details            Active site details, [ context => [ detail => bool ] ].
 *   details_suggested  Details a 1.1–1.3 preset stored but never applied
 *                      (or a format-1 design file carried); offered on the
 *                      Design screen until applied or dismissed.
 *   details_version    1 once the 1.1–1.3 maps were moved to suggestions.
 *   preset_visibility, preset_player, preset_episode_list
 *                      1.1–1.3 maps, kept untouched for a rollback; never
 *                      read for rendering.
 *
 * Portability: the option never holds attachment IDs, post IDs, URLs or
 * content, so it is safe to export/import between sites (see
 * EXPORT_VERSION and Admin::design_export_payload()).
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
	 *
	 * 1: tokens and the preset maps (preset_extras, never applied).
	 * 2: tokens and the site's details ('details', applied on import).
	 *    Format-1 maps import as suggestions.
	 */
	public const EXPORT_VERSION = 2;

	/**
	 * Option keys of the 1.1–1.3 preset maps (kept for a rollback only).
	 */
	private const LEGACY_MAPS = [ 'preset_visibility', 'preset_player', 'preset_episode_list' ];

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
			// 1.3.0 tokens (see button_shapes(), font_stacks(), shadows()).
			'button_shape'         => 'rounded',
			'font_family'          => 'inherit',
			'shadow'               => 'none',
			// Unplayed part of timelines; '' derives it from the muted color.
			'track_color'          => '',
			'preset'               => 'neutral',
			// "Details shown by default" (see Details): active and suggested.
			'details'              => [],
			'details_suggested'    => [],
		];
	}

	/**
	 * Button shapes → --epm-button-radius. Applies to text buttons (list
	 * "Play", speed, subscribe links, calls to action, retry); the round
	 * main play button keeps its own shape.
	 *
	 * @return array<string, string>
	 */
	public static function button_shapes(): array {
		return [
			'rounded' => '8px',
			'pill'    => '999px',
			'square'  => '2px',
		];
	}

	/**
	 * Font families → --epm-font. 'inherit' prints nothing, so the theme
	 * and Elementor Global Fonts stay in charge. Family names are left
	 * unquoted on purpose: the token block is escaped with esc_html().
	 *
	 * @return array<string, string>
	 */
	public static function font_stacks(): array {
		return [
			'inherit' => '',
			'system'  => 'system-ui, -apple-system, Segoe UI, Roboto, Helvetica Neue, Arial, sans-serif',
			'serif'   => 'ui-serif, Georgia, Cambria, Times New Roman, Times, serif',
			'rounded' => 'ui-rounded, SF Pro Rounded, system-ui, -apple-system, sans-serif',
			'mono'    => 'ui-monospace, SFMono-Regular, Menlo, Consolas, Liberation Mono, monospace',
		];
	}

	/**
	 * Container shadows → --epm-shadow (player, cards, sticky bar).
	 *
	 * @return array<string, string>
	 */
	public static function shadows(): array {
		return [
			'none'   => 'none',
			'soft'   => '0 1px 2px rgb(0 0 0 / 0.06), 0 4px 12px rgb(0 0 0 / 0.06)',
			'lifted' => '0 2px 6px rgb(0 0 0 / 0.08), 0 12px 32px rgb(0 0 0 / 0.12)',
		];
	}

	/**
	 * Whether a hex color is dark (relative luminance below 0.2).
	 *
	 * Used to pick dark-surface variants of derived tokens such as the
	 * white image outline.
	 *
	 * @param string $hex #rgb or #rrggbb.
	 * @return bool
	 */
	public static function is_dark( string $hex ): bool {
		$hex = ltrim( (string) sanitize_hex_color( $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return false;
		}

		$channel = static function ( string $pair ): float {
			$c = hexdec( $pair ) / 255;
			return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		};

		$luminance = 0.2126 * $channel( substr( $hex, 0, 2 ) )
			+ 0.7152 * $channel( substr( $hex, 2, 2 ) )
			+ 0.0722 * $channel( substr( $hex, 4, 2 ) );

		return $luminance < 0.2;
	}

	/**
	 * Value of a 1.3.0 token in a stored option, with the pre-1.3.0 look
	 * for designs saved before the token existed: their text buttons were
	 * pill-shaped, so they keep 'pill' instead of the new 'rounded' default.
	 *
	 * @param array<string, mixed> $stored Stored option (may be empty).
	 * @param string               $key    Token key.
	 * @return mixed|null Null when the stored option has no opinion.
	 */
	private static function stored_token( array $stored, string $key ) {
		if ( array_key_exists( $key, $stored ) ) {
			return $stored[ $key ];
		}

		if ( 'button_shape' === $key && ! empty( $stored ) ) {
			return 'pill';
		}

		return null;
	}

	/**
	 * Wire settings registration and frontend output.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		// The 1.1–1.3 preset maps become suggestions once (cheap check).
		add_action( 'init', [ $this, 'maybe_migrate' ], 20 );
		// The stylesheet's fallback tokens use :where(:root) (specificity 0),
		// so these :root tokens win regardless of document order. Printed once
		// in the head; the footer hook only covers themes without wp_head.
		add_action( 'wp_head', [ $this, 'output_tokens' ], 20 );
		add_action( 'wp_footer', [ $this, 'output_tokens' ], 1 );
		add_filter( 'body_class', [ $this, 'body_class' ] );
	}

	/**
	 * Flag a chosen podcast font on <body>, so the stylesheet can also
	 * apply it to headings and buttons inside podcast components (which
	 * themes often style directly). With 'inherit' nothing is added and
	 * theme typography stays untouched.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public function body_class( $classes ): array {
		$classes = (array) $classes;

		if ( '' !== ( self::font_stacks()[ (string) $this->get( 'font_family' ) ] ?? '' ) ) {
			$classes[] = 'epm-custom-font';
		}

		return $classes;
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

		// Designs saved before 1.3.0 keep their look (see stored_token()).
		$legacy = self::stored_token( $stored, 'button_shape' );
		if ( null !== $legacy && ! array_key_exists( 'button_shape', $stored ) ) {
			$stored['button_shape'] = $legacy;
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

		$stored_maps = is_array( $stored ) ? $stored : [];

		// Site details: explicit input wins (details form, preset, import);
		// otherwise the stored values stay, so a token save never changes
		// which details show.
		foreach ( [ 'details', 'details_suggested' ] as $map_key ) {
			$out[ $map_key ] = Details::sanitize_map( array_key_exists( $map_key, $input ) ? $input[ $map_key ] : ( $stored_maps[ $map_key ] ?? [] ) );
		}
		$version = $input['details_version'] ?? ( $stored_maps['details_version'] ?? null );
		if ( null !== $version ) {
			$out['details_version'] = absint( $version );
		}

		// The 1.1–1.3 maps are kept exactly as stored (for a rollback); no
		// form, preset or import writes them any more.
		foreach ( self::LEGACY_MAPS as $map_key ) {
			if ( isset( $stored_maps[ $map_key ] ) && is_array( $stored_maps[ $map_key ] ) ) {
				$out[ $map_key ] = $this->sanitize_flag_map( $stored_maps[ $map_key ] );
			}
		}

		// 1.3.0 tokens. Allowlisted values only. A form or import that does
		// not carry a key yet keeps the stored value instead of resetting it.
		$choices = [
			'button_shape' => array_keys( self::button_shapes() ),
			'font_family'  => array_keys( self::font_stacks() ),
			'shadow'       => array_keys( self::shadows() ),
		];
		foreach ( $choices as $key => $allowed ) {
			$previous = self::stored_token( $stored_maps, $key );
			$fallback = in_array( $previous, $allowed, true ) ? $previous : $out[ $key ];
			if ( array_key_exists( $key, $input ) ) {
				$value       = sanitize_key( (string) $input[ $key ] );
				$out[ $key ] = in_array( $value, $allowed, true ) ? $value : $fallback;
			} else {
				$out[ $key ] = $fallback;
			}
		}

		if ( array_key_exists( 'track_color', $input ) ) {
			// '' is a valid choice: derive the track from the muted color.
			$out['track_color'] = (string) sanitize_hex_color( (string) $input['track_color'] );
		} else {
			$previous           = self::stored_token( $stored_maps, 'track_color' );
			$out['track_color'] = (string) sanitize_hex_color( (string) $previous );
		}

		return $out;
	}

	/**
	 * Sanitize a 1.1–1.3 preset map: string keys, boolean values. Used to
	 * keep the stored preset_visibility / preset_player /
	 * preset_episode_list maps intact.
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
	 * Apply a preset: fills the design tokens and replaces the site's
	 * details with the preset's (Presets::details()). Never locks
	 * anything: every value stays editable. Suggestions from a 1.1–1.3
	 * preset are cleared (they belonged to the preset this replaces).
	 *
	 * A preset's 'layout' key (used by presets written for 1.1–1.3) sets
	 * the player layout when its tokens do not name one.
	 *
	 * @param string $preset_id Preset identifier.
	 * @return bool
	 */
	public function apply_preset( string $preset_id ): bool {
		$preset = epm()->presets->get( $preset_id );

		if ( ! $preset ) {
			return false;
		}

		$tokens = (array) ( $preset['tokens'] ?? [] );
		if ( ! isset( $tokens['default_player_layout'] ) && ! empty( $preset['layout'] ) && is_string( $preset['layout'] ) ) {
			$tokens['default_player_layout'] = $preset['layout'];
		}

		$stored = get_option( self::OPTION, [] );
		$values = array_merge( self::defaults(), $tokens );
		foreach ( self::LEGACY_MAPS as $map_key ) {
			unset( $values[ $map_key ] );
		}
		$values['preset']            = $preset_id;
		$values['details']           = Presets::details( $preset );
		$values['details_suggested'] = [];
		$values['details_version']   = max( 1, absint( is_array( $stored ) ? ( $stored['details_version'] ?? 1 ) : 1 ) );

		update_option( self::OPTION, $this->sanitize( $values ) );

		return true;
	}

	/**
	 * Save the site's details.
	 *
	 * @param array<string, array<string, mixed>> $submitted Per-context details.
	 * @param bool                                $form      True for the Design
	 *        screen form: a detail is stored when it was stored before or
	 *        differs from what the site shows now; an unchanged checkbox
	 *        leaves the detail to each consumer's built-in default.
	 * @return void
	 */
	public function update_details( array $submitted, bool $form = false ): void {
		$stored  = Details::sanitize_map( $this->get( 'details' ) );
		$details = $stored;

		foreach ( Details::sanitize_map( $submitted ) as $context => $flags ) {
			$now = Details::effective( $context );
			foreach ( $flags as $flag => $value ) {
				if ( ! $form || array_key_exists( $flag, $stored[ $context ] ?? [] ) || $value !== $now[ $flag ] ) {
					$details[ $context ][ $flag ] = $value;
				}
			}
		}

		$this->update_keys( [ 'details' => $details ] );
	}

	/**
	 * Forget every site detail: each consumer shows its built-in defaults.
	 *
	 * @return void
	 */
	public function reset_details(): void {
		$this->update_keys( [ 'details' => [] ] );
	}

	/**
	 * Apply the suggested details (see Details::suggested_changes()).
	 *
	 * @return void
	 */
	public function apply_suggested_details(): void {
		$details = Details::sanitize_map( $this->get( 'details' ) );
		foreach ( Details::suggested_changes() as $change ) {
			$details[ $change['context'] ][ $change['flag'] ] = $change['suggested'];
		}

		$this->update_keys(
			[
				'details'           => $details,
				'details_suggested' => [],
			]
		);
	}

	/**
	 * Drop the suggested details; nothing changes on the site.
	 *
	 * @return void
	 */
	public function dismiss_suggested_details(): void {
		$this->update_keys( [ 'details_suggested' => [] ] );
	}

	/**
	 * Move the preset maps stored by 1.1–1.3 into the suggestions, once.
	 *
	 * Those maps were never applied to anything, so they must not start
	 * changing the site now: nothing becomes active, the Design screen
	 * offers them instead. The maps stay in the option as they were (a
	 * rollback to 1.3.0 reads them as before).
	 *
	 * @return void
	 */
	public function maybe_migrate(): void {
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) || isset( $stored['details_version'] ) ) {
			return;
		}

		$stored['details_suggested'] = Details::from_legacy_maps(
			$stored['preset_visibility'] ?? [],
			$stored['preset_player'] ?? [],
			$stored['preset_episode_list'] ?? []
		);
		$stored['details']           = Details::sanitize_map( $stored['details'] ?? [] );
		$stored['details_version']   = 1;

		update_option( self::OPTION, $stored );
	}

	/**
	 * Store some keys of the option, keeping every other stored value.
	 *
	 * @param array<string, mixed> $values Keys to change.
	 * @return void
	 */
	private function update_keys( array $values ): void {
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		if ( empty( $stored ) ) {
			// A site without design settings keeps the defaults it had.
			$stored = self::defaults();
		}
		$stored['details_version'] = max( 1, absint( $stored['details_version'] ?? 1 ) );

		update_option( self::OPTION, $this->sanitize( array_merge( $stored, $values ) ) );
	}

	/**
	 * One stored detail of the site.
	 *
	 * @deprecated Never had a caller. Use Details::resolve() (or
	 *             Details::site()) instead.
	 *
	 * @param string $group   visibility|player (player context) or episodeList (lists).
	 * @param string $key     Detail key, e.g. show_artwork.
	 * @param mixed  $default Fallback when the site has not set it.
	 * @return mixed
	 */
	public function get_preset_value( string $group, string $key, $default = null ) {
		$contexts = [
			'visibility'  => 'player',
			'player'      => 'player',
			'episodeList' => 'list',
		];

		if ( ! isset( $contexts[ $group ] ) ) {
			return $default;
		}

		return Details::site( $contexts[ $group ] )[ $key ] ?? $default;
	}

	/**
	 * Extra variables for designs on a dark background.
	 *
	 * A white image outline and a lighter error red stay visible on dark
	 * backgrounds. Sections that paint no surface of their own (show notes,
	 * chapters, transcript, guest, header, row lists, subscribe links,
	 * pagination, hero) get the design background and inner padding, so
	 * their light text stays readable when the theme's page is light. The
	 * values refer to --epm-background and --epm-gap: printed on :root
	 * they resolve to the design's values, and the Design screen preview
	 * (which sets the tokens on its canvas) resolves them the same way.
	 *
	 * The Design screen preview takes the same list
	 * (Admin::design_dark_vars()), so the preview and the site cannot
	 * drift.
	 *
	 * @return array<string, string> Property => value.
	 */
	public static function dark_vars(): array {
		$vars = [
			'--epm-image-outline' => 'oklch(1 0 0 / 0.1)',
			'--epm-danger'        => '#f87171',
		];

		/**
		 * Whether dark designs give standalone sections the design
		 * background and padding. Sites whose pages are already dark can
		 * turn it off, so sections line up with the rest of the content.
		 *
		 * @param bool $enabled Default true.
		 */
		if ( apply_filters( 'epm_dark_section_surface', true ) ) {
			$vars['--epm-section-background'] = 'var(--epm-background, #ffffff)';
			$vars['--epm-section-padding']    = 'var(--epm-gap, 24px)';
		}

		return $vars;
	}

	/**
	 * Output the design tokens as CSS custom properties on :root.
	 *
	 * Printed once per request (wp_head, or wp_footer as a fallback), and
	 * only on pages that use podcast styles. The
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

		// Only where podcast styles are used: enqueued for <head> (Elementor
		// widgets, the episode page, shortcodes in the content), or used by
		// something rendered so far (footer fallback, before the late
		// stylesheet).
		if ( ! Assets::is_used() && ! wp_style_is( 'epm-frontend', 'enqueued' ) && ! wp_style_is( 'epm-frontend', 'done' ) ) {
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
			'--epm-button-radius' => self::button_shapes()[ (string) $t['button_shape'] ] ?? self::button_shapes()['rounded'],
			'--epm-shadow'        => self::shadows()[ (string) $t['shadow'] ] ?? 'none',
			// Empty for 'inherit': the stylesheet then keeps theme fonts.
			'--epm-font'          => self::font_stacks()[ (string) $t['font_family'] ] ?? '',
			// Empty = derived: the stylesheet falls back to the muted color.
			'--epm-track'         => (string) sanitize_hex_color( (string) $t['track_color'] ),
		];

		if ( self::is_dark( (string) $t['background'] ) ) {
			$vars = array_merge( $vars, self::dark_vars() );
		}

		$css = ':root{';
		foreach ( $vars as $name => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			$css .= $name . ':' . $value . ';';
		}
		$css .= '}';

		echo '<style id="epm-design-tokens">' . esc_html( $css ) . '</style>' . "\n";
	}
}
