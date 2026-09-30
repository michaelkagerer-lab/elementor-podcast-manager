<?php
/**
 * Brand preset system (Layer 2).
 *
 * A preset defines visual defaults ONLY: layout, tokens, visibility,
 * player options. Never content, episode IDs or client data.
 * Selecting a preset initializes styling — it never locks it.
 *
 * Structure is JSON-portable so presets can later be exported/imported.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Presets {

	/**
	 * Get all registered presets.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		$presets = [
			'neutral'        => $this->neutral(),
			'minimal'        => $this->minimal(),
			'editorial'      => $this->editorial(),
			'card'           => $this->card(),
			'business-tuning' => $this->business_tuning(),
			'clean-light'    => $this->clean_light(),
			'soft-voice'     => $this->soft_voice(),
			'warm-paper'     => $this->warm_paper(),
			'ink-mono'       => $this->ink_mono(),
			'night-studio'   => $this->night_studio(),
			'midnight'       => $this->midnight(),
		];

		return apply_filters( 'epm_presets', $presets );
	}

	/**
	 * Get one preset by ID.
	 *
	 * @param string $id Preset ID.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array {
		$all = $this->all();

		return $all[ $id ] ?? null;
	}

	/**
	 * Export a preset as JSON (portability).
	 *
	 * @param string $id Preset ID.
	 * @return string JSON string, empty on failure.
	 */
	public function export( string $id ): string {
		$preset = $this->get( $id );

		if ( ! $preset ) {
			return '';
		}

		$json = wp_json_encode( $preset, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Import a preset from JSON. Validates structure; design values only.
	 *
	 * @param string $json JSON string.
	 * @return array<string, mixed>|null
	 */
	public function import( string $json ): ?array {
		$data = json_decode( $json, true );

		if ( ! is_array( $data ) || empty( $data['name'] ) || ! is_array( $data['tokens'] ?? null ) ) {
			return null;
		}

		return [
			'name'        => sanitize_text_field( $data['name'] ),
			'description' => sanitize_text_field( $data['description'] ?? '' ),
			'layout'      => sanitize_key( $data['layout'] ?? 'minimal' ),
			'tokens'      => $data['tokens'],
			'visibility'  => is_array( $data['visibility'] ?? null ) ? $data['visibility'] : [],
			'player'      => is_array( $data['player'] ?? null ) ? $data['player'] : [],
			'episodeList' => is_array( $data['episodeList'] ?? null ) ? $data['episodeList'] : [],
		];
	}

	/**
	 * Neutral default preset.
	 *
	 * @return array<string, mixed>
	 */
	private function neutral(): array {
		return [
			'name'        => __( 'Neutral', 'elementor-podcast-manager' ),
			'description' => __( 'Clean, brand-independent defaults.', 'elementor-podcast-manager' ),
			'layout'      => 'minimal',
			'tokens'      => [
				'accent'                 => '#1d4ed8',
				'on_accent'              => '#ffffff',
				'text'                   => '#111827',
				'muted'                  => '#6b7280',
				'background'             => '#ffffff',
				'surface'                => '#f9fafb',
				'border_color'           => '#e5e7eb',
				'border_radius'          => '12',
				'artwork_radius'         => '8',
				'spacing'                => '24',
				'default_player_layout'  => 'minimal',
				'default_episode_layout' => 'list',
				'title_font_size'        => '22',
				'meta_font_size'         => '14',
				'button_shape'           => 'rounded',
				'font_family'            => 'inherit',
				'shadow'                 => 'none',
				'track_color'            => '',
			],
			'visibility'  => [
				'show_artwork'       => true,
				'show_episode_label' => true,
				'show_title'         => true,
				'show_episode_number' => true,
				'show_season'        => false,
				'show_guest'         => true,
				'show_description'   => false,
				'show_date'          => true,
				'show_duration'      => true,
			],
			'player'      => [
				'show_playback_speed' => true,
				'show_skip_backward'  => true,
				'show_skip_forward'   => true,
				'show_volume'         => true,
				'show_download'       => false,
			],
			'episodeList' => [],
		];
	}

	/**
	 * Minimal preset: monochrome, compact.
	 *
	 * @return array<string, mixed>
	 */
	private function minimal(): array {
		$preset = $this->neutral();

		$preset['name']        = __( 'Minimal', 'elementor-podcast-manager' );
		$preset['description'] = __( 'Monochrome and compact.', 'elementor-podcast-manager' );
		$preset['layout']      = 'minimal';
		$preset['tokens']      = array_merge(
			$preset['tokens'],
			[
				'accent'                 => '#111111',
				'on_accent'              => '#ffffff',
				'text'                   => '#111111',
				'muted'                  => '#737373',
				'background'             => '#ffffff',
				'surface'                => '#fafafa',
				'border_color'           => '#e5e5e5',
				'border_radius'          => '6',
				'artwork_radius'         => '6',
				'spacing'                => '16',
				'default_player_layout'  => 'minimal',
				'default_episode_layout' => 'minimal',
				'title_font_size'        => '18',
				'meta_font_size'         => '13',
				'button_shape'           => 'pill',
			]
		);
		$preset['visibility']['show_artwork'] = false;

		return $preset;
	}

	/**
	 * Editorial preset: large typography, numbered rows.
	 *
	 * @return array<string, mixed>
	 */
	private function editorial(): array {
		$preset = $this->neutral();

		$preset['name']        = __( 'Editorial', 'elementor-podcast-manager' );
		$preset['description'] = __( 'Large typography, separated metadata, generous whitespace.', 'elementor-podcast-manager' );
		$preset['layout']      = 'editorial';
		$preset['tokens']      = array_merge(
			$preset['tokens'],
			[
				'accent'                 => '#111111',
				'on_accent'              => '#ffffff',
				'text'                   => '#111111',
				'muted'                  => '#6b6b6b',
				'background'             => '#ffffff',
				'surface'                => '#f7f7f5',
				'border_color'           => '#e2e2e2',
				'border_radius'          => '4',
				'artwork_radius'         => '4',
				'spacing'                => '32',
				'default_player_layout'  => 'editorial',
				'default_episode_layout' => 'editorial-rows',
				'title_font_size'        => '32',
				'meta_font_size'         => '14',
				'button_shape'           => 'pill',
			]
		);

		return $preset;
	}

	/**
	 * Card preset: rounded cards, large artwork, light gray backgrounds.
	 *
	 * @return array<string, mixed>
	 */
	private function card(): array {
		$preset = $this->neutral();

		$preset['name']        = __( 'Card', 'elementor-podcast-manager' );
		$preset['description'] = __( 'Rounded cards with large artwork.', 'elementor-podcast-manager' );
		$preset['layout']      = 'artwork';
		$preset['tokens']      = array_merge(
			$preset['tokens'],
			[
				'accent'                 => '#2563eb',
				'on_accent'              => '#ffffff',
				'text'                   => '#1f2937',
				// #6b7280 measured 4.39:1 on the #f3f4f6 surface; this reaches 4.87:1.
				'muted'                  => '#646b78',
				'background'             => '#ffffff',
				'surface'                => '#f3f4f6',
				'border_color'           => '#e5e7eb',
				'border_radius'          => '20',
				'artwork_radius'         => '16',
				'spacing'                => '24',
				'default_player_layout'  => 'artwork',
				'default_episode_layout' => 'cards',
				'title_font_size'        => '20',
				'meta_font_size'         => '14',
				'button_shape'           => 'pill',
			]
		);

		return $preset;
	}

	/**
	 * Business Tuning reference preset.
	 *
	 * FIRST DESIGN PRESET / REFERENCE IMPLEMENTATION — design values only.
	 * Derived from the businesstuning.at design language (dark theme, lime
	 * accent, Barlow Condensed + Inter, numbered editorial sections).
	 * The generic engine never depends on these values.
	 *
	 * @return array<string, mixed>
	 */
	private function business_tuning(): array {
		$preset = $this->editorial();

		$preset['name']        = __( 'Business Tuning', 'elementor-podcast-manager' );
		$preset['description'] = __( 'Dark editorial player with a lime accent and numbered episode rows.', 'elementor-podcast-manager' );
		$preset['layout']      = 'editorial';
		$preset['tokens']      = array_merge(
			$preset['tokens'],
			[
				// Design values observed on businesstuning.at (Elementor Global Colors).
				'accent'                 => '#b9ff22',
				'on_accent'              => '#07090a',
				'text'                   => '#f2f0e9',
				'muted'                  => '#9ba1a4',
				'background'             => '#07090a',
				'surface'                => '#171c1e',
				'border_color'           => '#303638',
				'border_radius'          => '4',
				'artwork_radius'         => '4',
				'spacing'                => '32',
				'default_player_layout'  => 'editorial',
				'default_episode_layout' => 'editorial-rows',
				'title_font_size'        => '34',
				'meta_font_size'         => '14',
				'button_shape'           => 'pill',
			]
		);
		$preset['visibility']  = array_merge(
			$preset['visibility'],
			[
				'show_episode_label'  => true,
				'show_episode_number' => true,
				'show_guest'          => true,
				'show_description'    => true,
				'show_date'           => true,
				'show_duration'       => true,
			]
		);
		$preset['player']      = [
			'show_playback_speed' => true,
			'show_skip_backward'  => true,
			'show_skip_forward'   => true,
			'show_volume'         => false,
			'show_download'       => false,
		];

		return $preset;
	}

	/*
	 * Design-language presets (1.3.0).
	 *
	 * Derived from the public design-language analyses collected in
	 * VoltAgent/awesome-design-md (MIT License). They reuse design VALUES
	 * only: surface ladder, text and muted colors, one accent, radius,
	 * spacing, type scale, button shape, font family and elevation. No
	 * names, logos or copy. Every pair was measured with the WCAG formula:
	 * text >= 7:1 and muted >= 4.5:1 on background and surface,
	 * on_accent >= 4.5:1 on accent, track >= 3:1 on background and surface.
	 */

	/**
	 * Clean light: white canvas, one blue accent, pill buttons, no shadows.
	 *
	 * @return array<string, mixed>
	 */
	private function clean_light(): array {
		$preset = $this->neutral();

		$preset['name']        = __( 'Clean light', 'elementor-podcast-manager' );
		$preset['description'] = __( 'Bright white canvas, one blue accent and pill buttons. Calm, artwork-first and free of shadows.', 'elementor-podcast-manager' );
		$preset['layout']      = 'artwork';
		$preset['tokens']      = array_merge(
			$preset['tokens'],
			[
				'accent'                 => '#0066cc', // White on accent 5.57:1.
				'on_accent'              => '#ffffff',
				'text'                   => '#1d1d1f', // 16.83:1 / 15.46:1.
				'muted'                  => '#6e6e73', // 5.07:1 / 4.66:1.
				'background'             => '#ffffff',
				'surface'                => '#f5f5f7',
				'border_color'           => '#e0e0e0',
				'border_radius'          => '18',
				'artwork_radius'         => '12',
				'spacing'                => '24',
				'default_player_layout'  => 'artwork',
				'default_episode_layout' => 'cards',
				'title_font_size'        => '24',
				'meta_font_size'         => '14',
				'button_shape'           => 'pill',
				'font_family'            => 'system',
				'shadow'                 => 'none',
				'track_color'            => '#86868b', // 3.62:1 / 3.33:1.
			]
		);

		return $preset;
	}

	/**
	 * Soft voice: off-white canvas, white cards, ink buttons, soft shadow.
	 *
	 * @return array<string, mixed>
	 */
	private function soft_voice(): array {
		$preset = $this->neutral();

		$preset['name']        = __( 'Soft voice', 'elementor-podcast-manager' );
		$preset['description'] = __( 'Off-white canvas, white cards with a soft shadow and near-black ink buttons. Quiet and editorial.', 'elementor-podcast-manager' );
		$preset['layout']      = 'full';
		$preset['tokens']      = array_merge(
			$preset['tokens'],
			[
				'accent'                 => '#292524', // White on accent 15.17:1.
				'on_accent'              => '#ffffff',
				'text'                   => '#0c0a09', // 18.12:1 / 19.76:1.
				'muted'                  => '#6b665f', // 5.22:1 / 5.69:1.
				'background'             => '#f5f5f5',
				'surface'                => '#ffffff',
				'border_color'           => '#e7e5e4',
				'border_radius'          => '16',
				'artwork_radius'         => '12',
				'spacing'                => '24',
				'default_player_layout'  => 'full',
				'default_episode_layout' => 'list',
				'title_font_size'        => '22',
				'meta_font_size'         => '14',
				'button_shape'           => 'pill',
				'font_family'            => 'system',
				'shadow'                 => 'soft',
				'track_color'            => '#8c867e', // 3.31:1 / 3.60:1.
			]
		);
		$preset['visibility']['show_description'] = true;

		return $preset;
	}

	/**
	 * Warm paper: cream tones, terracotta accent, serif type.
	 *
	 * @return array<string, mixed>
	 */
	private function warm_paper(): array {
		$preset = $this->editorial();

		$preset['name']        = __( 'Warm paper', 'elementor-podcast-manager' );
		$preset['description'] = __( 'Cream paper tones, a terracotta accent and serif type. Warm, literary and unhurried.', 'elementor-podcast-manager' );
		$preset['layout']      = 'editorial';
		$preset['tokens']      = array_merge(
			$preset['tokens'],
			[
				// White on accent 5.74:1; as text 5.44:1 on the page and 4.83:1
				// on the surface (#b05a3c measured 4.04:1 there).
				'accent'                 => '#a04f33',
				'on_accent'              => '#ffffff',
				'text'                   => '#141413', // 17.50:1 / 15.52:1.
				'muted'                  => '#66645e', // 5.62:1 / 4.98:1.
				'background'             => '#faf9f5',
				'surface'                => '#f0ebe1',
				'border_color'           => '#e6dfd8',
				'border_radius'          => '12',
				'artwork_radius'         => '8',
				'spacing'                => '28',
				'default_player_layout'  => 'editorial',
				'default_episode_layout' => 'editorial-rows',
				'title_font_size'        => '26',
				'meta_font_size'         => '14',
				'button_shape'           => 'rounded',
				'font_family'            => 'serif',
				'shadow'                 => 'soft',
				'track_color'            => '#878175', // 3.67:1 / 3.26:1.
			]
		);
		$preset['visibility']['show_description'] = true;

		return $preset;
	}

	/**
	 * Ink mono: black ink on white, monospaced type, square buttons.
	 *
	 * @return array<string, mixed>
	 */
	private function ink_mono(): array {
		$preset = $this->neutral();

		$preset['name']        = __( 'Ink mono', 'elementor-podcast-manager' );
		$preset['description'] = __( 'Black ink on white, monospaced type and square buttons. Technical, compact and precise.', 'elementor-podcast-manager' );
		$preset['layout']      = 'minimal';
		$preset['tokens']      = array_merge(
			$preset['tokens'],
			[
				'accent'                 => '#171717', // White on accent 17.93:1.
				'on_accent'              => '#ffffff',
				'text'                   => '#171717', // 17.93:1 / 17.18:1.
				'muted'                  => '#5f5f5f', // 6.39:1 / 6.12:1.
				'background'             => '#ffffff',
				'surface'                => '#fafafa',
				'border_color'           => '#ebebeb',
				'border_radius'          => '6',
				'artwork_radius'         => '4',
				'spacing'                => '20',
				'default_player_layout'  => 'minimal',
				'default_episode_layout' => 'minimal',
				'title_font_size'        => '18',
				'meta_font_size'         => '13',
				'button_shape'           => 'square',
				'font_family'            => 'mono',
				'shadow'                 => 'none',
				'track_color'            => '#8f8f8f', // 3.23:1 / 3.10:1.
			]
		);

		return $preset;
	}

	/**
	 * Night studio: near-black surfaces, vivid green accent, lifted cards.
	 *
	 * @return array<string, mixed>
	 */
	private function night_studio(): array {
		$preset = $this->neutral();

		$preset['name']        = __( 'Night studio', 'elementor-podcast-manager' );
		$preset['description'] = __( 'Near-black surfaces, a vivid green accent and pill buttons. The artwork brings the color.', 'elementor-podcast-manager' );
		$preset['layout']      = 'artwork';
		$preset['tokens']      = array_merge(
			$preset['tokens'],
			[
				'accent'                 => '#1ed760', // Black on accent 10.94:1.
				'on_accent'              => '#000000',
				'text'                   => '#ffffff', // 18.73:1 / 17.04:1.
				'muted'                  => '#b3b3b3', // 8.93:1 / 8.13:1.
				'background'             => '#121212',
				'surface'                => '#1c1c1c',
				'border_color'           => '#2a2a2a',
				'border_radius'          => '8',
				'artwork_radius'         => '6',
				'spacing'                => '16',
				'default_player_layout'  => 'artwork',
				'default_episode_layout' => 'grid',
				'title_font_size'        => '20',
				'meta_font_size'         => '14',
				'button_shape'           => 'pill',
				'font_family'            => 'rounded',
				'shadow'                 => 'lifted',
				'track_color'            => '#6f6f6f', // 3.73:1 / 3.39:1.
			]
		);

		return $preset;
	}

	/**
	 * Midnight: blue-black canvas, soft violet accent, dense rows.
	 *
	 * @return array<string, mixed>
	 */
	private function midnight(): array {
		$preset = $this->neutral();

		$preset['name']        = __( 'Midnight', 'elementor-podcast-manager' );
		$preset['description'] = __( 'Blue-black canvas, a soft violet accent and dense rows. Made for long episode lists.', 'elementor-podcast-manager' );
		$preset['layout']      = 'compact';
		$preset['tokens']      = array_merge(
			$preset['tokens'],
			[
				'accent'                 => '#828fff', // Dark on accent 6.95:1; accent text 6.95:1.
				'on_accent'              => '#08090a',
				'text'                   => '#f7f8f8', // 18.73:1 / 17.18:1.
				'muted'                  => '#8a8f98', // 6.13:1 / 5.63:1.
				'background'             => '#08090a',
				'surface'                => '#141516',
				'border_color'           => '#23252a',
				'border_radius'          => '12',
				'artwork_radius'         => '8',
				'spacing'                => '20',
				'default_player_layout'  => 'compact',
				'default_episode_layout' => 'list',
				'title_font_size'        => '20',
				'meta_font_size'         => '13',
				'button_shape'           => 'rounded',
				'font_family'            => 'system',
				'shadow'                 => 'none',
				'track_color'            => '#62666d', // 3.45:1 / 3.17:1.
			]
		);

		return $preset;
	}
}
