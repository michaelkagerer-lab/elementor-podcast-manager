<?php
/**
 * Distribution readiness report (Layer 1).
 *
 * A nonempty title is not enough: this checks the metadata, artwork,
 * episodes and media that podcast directories actually require, and
 * surfaces actionable errors and warnings. Optional chapters, guests
 * and transcripts never block publishing.
 *
 * @package EPM
 */

namespace EPM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Readiness {

	/**
	 * Apple Podcasts top-level categories (validation reference).
	 *
	 * @return string[]
	 */
	public static function apple_categories(): array {
		return array_keys( Categories::all() );
	}

	/**
	 * Create a settings deep link only for users who can open that screen.
	 *
	 * @param string $field Settings field id.
	 * @return string
	 */
	private static function settings_field_url( string $field ): string {
		return self::admin_page_url( 'epm-settings', 'epm-s-' . $field );
	}

	/**
	 * Link to a podcast admin screen, only for users who can open it.
	 *
	 * @param string $page   Admin page slug.
	 * @param string $anchor Optional element id to jump to.
	 * @return string
	 */
	private static function admin_page_url( string $page, string $anchor = '' ): string {
		if ( ! Capabilities::can_manage_podcast() ) {
			return '';
		}

		return admin_url( 'admin.php?page=' . rawurlencode( $page ) ) . ( '' !== $anchor ? '#' . rawurlencode( $anchor ) : '' );
	}

	/**
	 * Build the full readiness report.
	 *
	 * Each check links (url) to the screen where it is fixed, or '' when
	 * there is none or the user cannot open it.
	 *
	 * @return array{ready: bool, errors: int, warnings: int, checks: array<int, array{status: string, label: string, message: string, url: string}>}
	 */
	public static function report(): array {
		$checks = [];

		$add = function ( string $status, string $label, string $message, string $url = '' ) use ( &$checks ) {
			$checks[] = [
				'status'  => $status,
				'label'   => $label,
				'message' => $message,
				'url'     => $url,
			];
		};

		$settings = epm()->settings;

		// Another host publishes the feed: directory requirements are the
		// host's job; what matters here is that the site stays in sync.
		if ( Hosting::is_external() ) {
			return self::finish( self::external_checks( $add, $checks ) );
		}

		// --- Required podcast metadata. ---
		$title = trim( (string) $settings->get( 'title' ) );
		if ( '' === $title ) {
			$add( 'error', __( 'Podcast title', 'elementor-podcast-manager' ), __( 'Enter a podcast title in Podcast Settings.', 'elementor-podcast-manager' ), self::settings_field_url( 'title' ) );
		} else {
			$add( 'ok', __( 'Podcast title', 'elementor-podcast-manager' ), $title );
		}

		$description = trim( (string) wp_strip_all_tags( (string) $settings->get( 'description' ) ) );
		if ( '' === $description ) {
			$add( 'error', __( 'Podcast description', 'elementor-podcast-manager' ), __( 'Enter a podcast description in Podcast Settings.', 'elementor-podcast-manager' ), self::settings_field_url( 'desc' ) );
		} else {
			$add( 'ok', __( 'Podcast description', 'elementor-podcast-manager' ), __( 'Present.', 'elementor-podcast-manager' ) );
		}

		$author = trim( (string) $settings->get( 'author' ) );
		if ( '' === $author ) {
			$add( 'warning', __( 'Podcast author', 'elementor-podcast-manager' ), __( 'Recommended: set the author shown in directories.', 'elementor-podcast-manager' ), self::settings_field_url( 'author' ) );
		} else {
			$add( 'ok', __( 'Podcast author', 'elementor-podcast-manager' ), $author );
		}

		$owner_email = trim( (string) $settings->get( 'owner_email' ) );
		if ( '' === $owner_email || ! is_email( $owner_email ) ) {
			$add( 'error', __( 'Owner email', 'elementor-podcast-manager' ), __( 'Directories require a valid owner email address.', 'elementor-podcast-manager' ), self::settings_field_url( 'owner-email' ) );
		} else {
			$add( 'ok', __( 'Owner email', 'elementor-podcast-manager' ), $owner_email );
		}

		$owner_name = trim( (string) $settings->get( 'owner_name' ) );
		if ( '' === $owner_name ) {
			$add( 'warning', __( 'Owner name', 'elementor-podcast-manager' ), __( 'Recommended: set the podcast owner name.', 'elementor-podcast-manager' ), self::settings_field_url( 'owner-name' ) );
		} else {
			$add( 'ok', __( 'Owner name', 'elementor-podcast-manager' ), $owner_name );
		}

		// --- Category & language. ---
		$category = trim( (string) $settings->get( 'category' ) );
		if ( '' === $category ) {
			$add( 'warning', __( 'Category', 'elementor-podcast-manager' ), __( 'No category set. Directories use it for discovery.', 'elementor-podcast-manager' ), self::settings_field_url( 'category' ) );
		} elseif ( ! Categories::is_valid( $category ) ) {
			$add(
				'warning',
				__( 'Category', 'elementor-podcast-manager' ),
				sprintf(
					/* translators: %s: category name */
					__( '“%s” is not a top-level Apple Podcasts category. Check the exact spelling.', 'elementor-podcast-manager' ),
					$category
				),
				self::settings_field_url( 'category' )
			);
		} else {
			$subcategory = (string) $settings->get( 'subcategory' );
			$add( 'ok', __( 'Category', 'elementor-podcast-manager' ), '' !== $subcategory ? $category . ' › ' . $subcategory : $category );
		}

		$language = Feed::rss_language( (string) $settings->get( 'language' ) );
		$add( 'ok', __( 'Language', 'elementor-podcast-manager' ), $language );

		// --- Artwork. ---
		$artwork_id = (int) $settings->get( 'artwork_id' );
		if ( $artwork_id <= 0 ) {
			$add( 'error', __( 'Podcast artwork', 'elementor-podcast-manager' ), __( 'Upload podcast artwork in Podcast Settings. Directories require it.', 'elementor-podcast-manager' ), self::settings_field_url( 'artwork_id' ) );
		} else {
			$mime = (string) get_post_mime_type( $artwork_id );
			if ( '' !== $mime && ! in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
				$add( 'error', __( 'Podcast artwork format', 'elementor-podcast-manager' ), __( 'Directories only accept JPEG or PNG artwork.', 'elementor-podcast-manager' ), self::settings_field_url( 'artwork_id' ) );
			}

			$meta   = wp_get_attachment_metadata( $artwork_id );
			$width  = is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0;
			$height = is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0;

			if ( $width > 0 && $height > 0 ) {
				if ( $width > 3000 || $height > 3000 ) {
					$add(
						'warning',
						__( 'Podcast artwork', 'elementor-podcast-manager' ),
						sprintf(
							/* translators: %1$d: width, %2$d: height */
							__( 'Artwork is %1$d×%2$d px. Apple Podcasts accepts at most 3000×3000 px.', 'elementor-podcast-manager' ),
							$width,
							$height
						),
						self::settings_field_url( 'artwork_id' )
					);
				} elseif ( $width < 1400 || $height < 1400 ) {
					$add(
						'warning',
						__( 'Podcast artwork', 'elementor-podcast-manager' ),
						sprintf(
							/* translators: %1$d: width, %2$d: height */
							__( 'Artwork is %1$d×%2$d px. Directories recommend at least 1400×1400 px.', 'elementor-podcast-manager' ),
							$width,
							$height
						),
						self::settings_field_url( 'artwork_id' )
					);
				} elseif ( $width !== $height ) {
					$add( 'warning', __( 'Podcast artwork', 'elementor-podcast-manager' ), __( 'Artwork should be square.', 'elementor-podcast-manager' ), self::settings_field_url( 'artwork_id' ) );
				} else {
					/* translators: %1$d: width, %2$d: height */
					$add( 'ok', __( 'Podcast artwork', 'elementor-podcast-manager' ), sprintf( __( '%1$d×%2$d px', 'elementor-podcast-manager' ), $width, $height ) );
				}
			} else {
				$add( 'ok', __( 'Podcast artwork', 'elementor-podcast-manager' ), __( 'Present.', 'elementor-podcast-manager' ) );
			}
		}

		// --- Episodes. ---
		$episodes    = epm()->episodes->get_episodes( [ 'posts_per_page' => -1 ] );
		$distributable = 0;
		$seen_urls     = [];
		Episodes::prime_attachments( $episodes );

		foreach ( $episodes as $post ) {
			$data = epm()->episodes->get_public_data( $post );
			if ( ! $data ) {
				continue;
			}

			$audio_id = (int) $data['audio_id'];
			$external = $audio_id <= 0 && 'external' === ( $data['audio_source'] ?? '' );
			$edit_url = (string) get_edit_post_link( $post->ID, 'raw' );

			if ( $audio_id <= 0 && ! $external ) {
				$add(
					'warning',
					/* translators: %s: episode title */
					sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ),
					__( 'No audio attached — excluded from the feed.', 'elementor-podcast-manager' ),
					$edit_url
				);
				continue;
			}

			if ( $external && 0 !== stripos( (string) $data['audio_url'], 'https://' ) ) {
				$add(
					'warning',
					sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ),
					__( 'The audio URL does not use HTTPS. Some apps refuse insecure audio; use an https:// address.', 'elementor-podcast-manager' ),
					$edit_url
				);
			}

			if ( ! $external && ! AudioMetadata::is_valid_audio_attachment( $audio_id ) ) {
				$add(
					'error',
					sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ),
					__( 'The audio attachment is missing or not a supported audio file.', 'elementor-podcast-manager' ),
					$edit_url
				);
				continue;
			}

			$enclosure = AudioMetadata::enclosure( $data );

			if ( ! $enclosure['distributable'] ) {
				$add(
					'warning',
					sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ),
					__( 'Audio format is for internal storage only (not MP3/M4A) — excluded from the feed.', 'elementor-podcast-manager' ),
					$edit_url
				);
				continue;
			}

			if ( ! $enclosure['size_known'] ) {
				$add(
					'warning',
					sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ),
					__( 'File size could not be determined; the enclosure reports length 0.', 'elementor-podcast-manager' ),
					$edit_url
				);
			}

			if ( isset( $seen_urls[ $enclosure['url'] ] ) ) {
				$add(
					'error',
					sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ),
					sprintf(
						/* translators: %s: other episode title */
						__( 'Duplicate audio URL — also used by “%s”.', 'elementor-podcast-manager' ),
						$seen_urls[ $enclosure['url'] ]
					),
					$edit_url
				);
				continue;
			}
			$seen_urls[ $enclosure['url'] ] = $data['title'];

			$distributable++;
		}

		if ( 0 === $distributable ) {
			$add( 'error', __( 'Distributable episodes', 'elementor-podcast-manager' ), __( 'No episode with distribution-ready audio (MP3/M4A) is published yet.', 'elementor-podcast-manager' ), admin_url( 'post-new.php?post_type=' . EpisodePostType::CPT ) );
		} else {
			$add(
				'ok',
				__( 'Distributable episodes', 'elementor-podcast-manager' ),
				sprintf(
					/* translators: %d: number of episodes */
					__( '%d episode(s) with distribution-ready audio.', 'elementor-podcast-manager' ),
					$distributable
				)
			);
		}

		return self::finish( $checks );
	}

	/**
	 * Checks for a show another host publishes.
	 *
	 * @param callable                                                     $add    Adds a check.
	 * @param array<int, array{status: string, label: string, message: string, url: string}> $checks Checks (by reference through $add).
	 * @return array<int, array{status: string, label: string, message: string, url: string}>
	 */
	private static function external_checks( callable $add, array &$checks ): array {
		$feed    = (string) Hosting::get( 'feed_url' );
		$state   = Hosting::state();
		$hosting = self::admin_page_url( 'epm-hosting' );

		$add( 'ok', __( 'Hosting', 'elementor-podcast-manager' ), Hosting::provider_name() );

		if ( '' === $feed ) {
			$add( 'error', __( 'Host feed', 'elementor-podcast-manager' ), __( 'Enter your host’s RSS feed address under Podcast → Hosting so episodes can sync.', 'elementor-podcast-manager' ), $hosting );
			return $checks;
		}

		$add( 'ok', __( 'Host feed', 'elementor-podcast-manager' ), $feed );

		if ( ! Hosting::get( 'sync' ) ) {
			$add( 'warning', __( 'Automatic sync', 'elementor-podcast-manager' ), __( 'Off. New episodes from your host only appear here after “Sync now”.', 'elementor-podcast-manager' ), $hosting );
		}

		if ( 'error' === $state['status'] ) {
			$add( 'error', __( 'Last sync', 'elementor-podcast-manager' ), (string) $state['message'], $hosting );
		} elseif ( (int) $state['last_success'] > 0 ) {
			$add(
				'ok',
				__( 'Last sync', 'elementor-podcast-manager' ),
				sprintf(
					/* translators: 1: time difference, 2: sync summary */
					__( '%1$s ago — %2$s', 'elementor-podcast-manager' ),
					human_time_diff( (int) $state['last_success'] ),
					(string) $state['message']
				)
			);
		} else {
			$add( 'warning', __( 'Last sync', 'elementor-podcast-manager' ), __( 'Not synced yet. Run “Sync now” or import the show.', 'elementor-podcast-manager' ), $hosting );
		}

		if ( ! Hosting::get( 'redirect' ) ) {
			$add( 'warning', __( 'Site feed', 'elementor-podcast-manager' ), __( 'This site still publishes its own feed. If apps find both, listeners may see the show twice; turn on the redirect to your host’s feed.', 'elementor-podcast-manager' ), $hosting );
		} else {
			$add( 'ok', __( 'Site feed', 'elementor-podcast-manager' ), __( 'Redirects permanently to your host’s feed.', 'elementor-podcast-manager' ) );
		}

		$count = epm()->episodes->count_published();
		if ( 0 === $count ) {
			$add( 'warning', __( 'Episodes', 'elementor-podcast-manager' ), __( 'No episodes on this site yet.', 'elementor-podcast-manager' ) );
		} else {
			/* translators: %s: number of episodes */
			$add( 'ok', __( 'Episodes', 'elementor-podcast-manager' ), sprintf( _n( '%s published episode', '%s published episodes', $count, 'elementor-podcast-manager' ), number_format_i18n( $count ) ) );
		}

		return $checks;
	}

	/**
	 * Sort checks and count problems.
	 *
	 * @param array<int, array{status: string, label: string, message: string, url: string}> $checks Checks.
	 * @return array{ready: bool, errors: int, warnings: int, checks: array<int, array{status: string, label: string, message: string, url: string}>}
	 */
	private static function finish( array $checks ): array {
		// Problems first: errors, then warnings, then passed checks (stable).
		$rank = [
			'error'   => 0,
			'warning' => 1,
			'ok'      => 2,
		];
		$order = array_keys( $checks );
		usort(
			$order,
			function ( $a, $b ) use ( $checks, $rank ) {
				return [ $rank[ $checks[ $a ]['status'] ] ?? 3, $a ] <=> [ $rank[ $checks[ $b ]['status'] ] ?? 3, $b ];
			}
		);
		$checks = array_map(
			function ( $index ) use ( $checks ) {
				return $checks[ $index ];
			},
			$order
		);

		$errors   = 0;
		$warnings = 0;
		foreach ( $checks as $check ) {
			if ( 'error' === $check['status'] ) {
				$errors++;
			} elseif ( 'warning' === $check['status'] ) {
				$warnings++;
			}
		}

		return [
			'ready'    => 0 === $errors,
			'errors'   => $errors,
			'warnings' => $warnings,
			'checks'   => $checks,
		];
	}

	/**
	 * Render the report as HTML.
	 *
	 * @param array<string, mixed>|null $report Report from report(); built when omitted.
	 * @return string
	 */
	public static function render_html( ?array $report = null ): string {
		$report = $report ?? self::report();

		$out = '<div class="epm-readiness">';
		$out .= '<p class="epm-readiness__summary">';
		if ( $report['ready'] ) {
			$out .= '<span class="epm-status epm-status--ready">' . esc_html__( 'Ready for distribution', 'elementor-podcast-manager' ) . '</span>';
		} else {
			$out .= '<span class="epm-status epm-status--missing">' . esc_html__( 'Not ready', 'elementor-podcast-manager' ) . '</span>';
		}
		if ( $report['warnings'] > 0 ) {
			/* translators: %d: number of warnings */
			$out .= ' <span class="epm-readiness__warnings">' . esc_html( sprintf( _n( '%d warning', '%d warnings', $report['warnings'], 'elementor-podcast-manager' ), $report['warnings'] ) ) . '</span>';
		}
		$out .= '</p>';

		$out .= '<ul class="epm-readiness__list">';
		foreach ( $report['checks'] as $check ) {
			$out .= '<li class="epm-readiness__item epm-readiness__item--' . esc_attr( $check['status'] ) . '">';
			$out .= '<strong>';
			if ( ! empty( $check['url'] ) ) {
				$out .= '<a href="' . esc_url( $check['url'] ) . '">';
			}
			$out .= esc_html( $check['label'] );
			if ( ! empty( $check['url'] ) ) {
				$out .= '</a>';
			}
			$out .= ':</strong> ';
			$out .= esc_html( $check['message'] );
			$out .= '</li>';
		}
		$out .= '</ul>';

		$out .= '<p class="description">' . esc_html__( 'Transport checks (public HTTP access, HEAD and byte-range support) depend on your hosting and must be verified separately against the live feed URL.', 'elementor-podcast-manager' ) . '</p>';
		$out .= '</div>';

		return $out;
	}
}
