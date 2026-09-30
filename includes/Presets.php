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
				'muted'                  => '#6b7280',
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
		$preset['description'] = __( 'Reference implementation for businesstuning.at: dark editorial player with numbered episode rows.', 'elementor-podcast-manager' );
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
}
