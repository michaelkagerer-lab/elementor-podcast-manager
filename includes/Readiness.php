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
	 * Build the full readiness report.
	 *
	 * @return array{ready: bool, errors: int, warnings: int, checks: array<int, array{status: string, label: string, message: string}>}
	 */
	public static function report(): array {
		$checks = [];

		$add = function ( string $status, string $label, string $message ) use ( &$checks ) {
			$checks[] = [
				'status'  => $status,
				'label'   => $label,
				'message' => $message,
			];
		};

		$settings = epm()->settings;

		// --- Required podcast metadata. ---
		$title = trim( (string) $settings->get( 'title' ) );
		if ( '' === $title ) {
			$add( 'error', __( 'Podcast title', 'elementor-podcast-manager' ), __( 'Enter a podcast title in Podcast Settings.', 'elementor-podcast-manager' ) );
		} else {
			$add( 'ok', __( 'Podcast title', 'elementor-podcast-manager' ), $title );
		}

		$description = trim( (string) wp_strip_all_tags( (string) $settings->get( 'description' ) ) );
		if ( '' === $description ) {
			$add( 'error', __( 'Podcast description', 'elementor-podcast-manager' ), __( 'Enter a podcast description in Podcast Settings.', 'elementor-podcast-manager' ) );
		} else {
			$add( 'ok', __( 'Podcast description', 'elementor-podcast-manager' ), __( 'Present.', 'elementor-podcast-manager' ) );
		}

		$author = trim( (string) $settings->get( 'author' ) );
		if ( '' === $author ) {
			$add( 'warning', __( 'Podcast author', 'elementor-podcast-manager' ), __( 'Recommended: set the author shown in directories.', 'elementor-podcast-manager' ) );
		} else {
			$add( 'ok', __( 'Podcast author', 'elementor-podcast-manager' ), $author );
		}

		$owner_email = trim( (string) $settings->get( 'owner_email' ) );
		if ( '' === $owner_email || ! is_email( $owner_email ) ) {
			$add( 'error', __( 'Owner email', 'elementor-podcast-manager' ), __( 'Directories require a valid owner email address.', 'elementor-podcast-manager' ) );
		} else {
			$add( 'ok', __( 'Owner email', 'elementor-podcast-manager' ), $owner_email );
		}

		$owner_name = trim( (string) $settings->get( 'owner_name' ) );
		if ( '' === $owner_name ) {
			$add( 'warning', __( 'Owner name', 'elementor-podcast-manager' ), __( 'Recommended: set the podcast owner name.', 'elementor-podcast-manager' ) );
		} else {
			$add( 'ok', __( 'Owner name', 'elementor-podcast-manager' ), $owner_name );
		}

		// --- Category & language. ---
		$category = trim( (string) $settings->get( 'category' ) );
		if ( '' === $category ) {
			$add( 'warning', __( 'Category', 'elementor-podcast-manager' ), __( 'No category set. Directories use it for discovery.', 'elementor-podcast-manager' ) );
		} elseif ( ! Categories::is_valid( $category ) ) {
			$add(
				'warning',
				__( 'Category', 'elementor-podcast-manager' ),
				sprintf(
					/* translators: %s: category name */
					__( '“%s” is not a top-level Apple Podcasts category. Check the exact spelling.', 'elementor-podcast-manager' ),
					$category
				)
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
			$add( 'error', __( 'Podcast artwork', 'elementor-podcast-manager' ), __( 'Upload podcast artwork in Podcast Settings. Directories require it.', 'elementor-podcast-manager' ) );
		} else {
			$mime = (string) get_post_mime_type( $artwork_id );
			if ( '' !== $mime && ! in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
				$add( 'error', __( 'Podcast artwork format', 'elementor-podcast-manager' ), __( 'Directories only accept JPEG or PNG artwork.', 'elementor-podcast-manager' ) );
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
						)
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
						)
					);
				} elseif ( $width !== $height ) {
					$add( 'warning', __( 'Podcast artwork', 'elementor-podcast-manager' ), __( 'Artwork should be square.', 'elementor-podcast-manager' ) );
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

		foreach ( $episodes as $post ) {
			$data = epm()->episodes->get_public_data( $post );
			if ( ! $data ) {
				continue;
			}

			$audio_id = (int) $data['audio_id'];
			if ( $audio_id <= 0 ) {
				$add(
					'warning',
					/* translators: %s: episode title */
					sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ),
					__( 'No audio attached — excluded from the feed.', 'elementor-podcast-manager' )
				);
				continue;
			}

			if ( ! AudioMetadata::is_valid_audio_attachment( $audio_id ) ) {
				$add(
					'error',
					sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ),
					__( 'The audio attachment is missing or not a supported audio file.', 'elementor-podcast-manager' )
				);
				continue;
			}

			$enclosure = AudioMetadata::enclosure( $data );

			if ( ! $enclosure['distributable'] ) {
				$add(
					'warning',
					sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ),
					__( 'Audio format is for internal storage only (not MP3/M4A) — excluded from the feed.', 'elementor-podcast-manager' )
				);
				continue;
			}

			if ( ! $enclosure['size_known'] ) {
				$add(
					'warning',
					sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ),
					__( 'File size could not be determined; the enclosure reports length 0.', 'elementor-podcast-manager' )
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
					)
				);
				continue;
			}
			$seen_urls[ $enclosure['url'] ] = $data['title'];

			$distributable++;
		}

		if ( 0 === $distributable ) {
			$add( 'error', __( 'Distributable episodes', 'elementor-podcast-manager' ), __( 'No episode with distribution-ready audio (MP3/M4A) is published yet.', 'elementor-podcast-manager' ) );
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
	 * @return string
	 */
	public static function render_html(): string {
		$report = self::report();

		$out = '<div class="epm-readiness">';
		$out .= '<p class="epm-readiness__summary">';
		if ( $report['ready'] ) {
			$out .= '<span class="epm-status epm-status--ready">' . esc_html__( 'Ready for distribution', 'elementor-podcast-manager' ) . '</span>';
		} else {
			$out .= '<span class="epm-status epm-status--missing">' . esc_html__( 'Not ready', 'elementor-podcast-manager' ) . '</span>';
		}
		if ( $report['warnings'] > 0 ) {
			$out .= ' <span class="epm-readiness__warnings">' . esc_html( sprintf( _n( '%d warning', '%d warnings', $report['warnings'], 'elementor-podcast-manager' ), $report['warnings'] ) ) . '</span>';
		}
		$out .= '</p>';

		$out .= '<ul class="epm-readiness__list">';
		foreach ( $report['checks'] as $check ) {
			$out .= '<li class="epm-readiness__item epm-readiness__item--' . esc_attr( $check['status'] ) . '">';
			$out .= '<strong>' . esc_html( $check['label'] ) . ':</strong> ';
			$out .= esc_html( $check['message'] );
			$out .= '</li>';
		}
		$out .= '</ul>';

		$out .= '<p class="description">' . esc_html__( 'Transport checks (public HTTP access, HEAD and byte-range support) depend on your hosting and must be verified separately against the live feed URL.', 'elementor-podcast-manager' ) . '</p>';
		$out .= '</div>';

		return $out;
	}
}
