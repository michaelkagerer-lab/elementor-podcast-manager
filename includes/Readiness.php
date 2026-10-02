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
	 * Problems with single episodes listed one by one; more are counted in
	 * one check.
	 */
	private const EPISODE_CHECKS = 50;

	/**
	 * Apple Podcasts top-level categories (validation reference).
	 *
	 * @return string[]
	 */
	public static function apple_categories(): array {
		return array_keys( Categories::all() );
	}

	/**
	 * Link to a podcast admin screen, only for users who can open it.
	 *
	 * @param string $page   Admin page slug.
	 * @param string $anchor Optional element id to jump to.
	 * @return string URL, or '' when the current user cannot open the screen.
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
	 * Every check has status, label and message. url / fix_label name the
	 * screen where the problem is fixed: '' when there is none or the
	 * current user cannot open it. A check about several episodes lists
	 * them in `items` (title, url of the editor; at most ten) and how many
	 * more there are in `more`.
	 *
	 * @return array{ready: bool, errors: int, warnings: int, checks: array<int, array{status: string, label: string, message: string, url: string, fix_label: string, items: array<int, array{title: string, url: string}>, more: int}>}
	 */
	public static function report(): array {
		$checks = [];

		$add = function ( string $status, string $label, string $message, string $url = '', string $fix_label = '', array $items = [], int $more = 0 ) use ( &$checks ) {
			$checks[] = [
				'status'    => $status,
				'label'     => $label,
				'message'   => $message,
				'url'       => $url,
				'fix_label' => '' !== $url ? $fix_label : '',
				'items'     => $items,
				'more'      => $more,
			];
		};

		$settings = epm()->settings;
		$fix_here = __( 'Fix in podcast settings', 'elementor-podcast-manager' );
		$settings_url = static function ( string $anchor ): string {
			return self::admin_page_url( 'epm-settings', $anchor );
		};

		// Another host publishes the feed: directory requirements are the
		// host's job; what matters here is that the site stays in sync.
		if ( Hosting::is_external() ) {
			return self::finish( self::external_checks( $add, $checks ) );
		}

		// --- The address directories know. ---
		$address = Feed::address_change();
		if ( null !== $address ) {
			$add(
				'error',
				__( 'Feed address', 'elementor-podcast-manager' ),
				sprintf(
					/* translators: 1: feed address submitted to directories, 2: new feed address */
					__( 'The feed address changed from %1$s to %2$s. Directories and apps keep loading the old address. Change the permalink setting back, or submit the new address to every directory and confirm it on the Distribution screen.', 'elementor-podcast-manager' ),
					$address['shown'],
					$address['now']
				),
				self::admin_page_url( 'epm-distribution' ),
				__( 'Open Distribution', 'elementor-podcast-manager' )
			);
		}

		// --- Required podcast metadata. ---
		$title = trim( (string) $settings->get( 'title' ) );
		if ( '' === $title ) {
			$add( 'error', __( 'Podcast title', 'elementor-podcast-manager' ), __( 'Add a podcast title. Directories show it as the name of your show.', 'elementor-podcast-manager' ), $settings_url( 'epm-s-title' ), $fix_here );
		} else {
			$add( 'ok', __( 'Podcast title', 'elementor-podcast-manager' ), $title );
		}

		$description = trim( (string) wp_strip_all_tags( (string) $settings->get( 'description' ) ) );
		if ( '' === $description ) {
			$add( 'error', __( 'Podcast description', 'elementor-podcast-manager' ), __( 'Add a podcast description. Directories show it on your show’s page.', 'elementor-podcast-manager' ), $settings_url( 'epm-s-desc' ), $fix_here );
		} else {
			$add( 'ok', __( 'Podcast description', 'elementor-podcast-manager' ), __( 'Present.', 'elementor-podcast-manager' ) );
		}

		$author = trim( (string) $settings->get( 'author' ) );
		if ( '' === $author ) {
			$add( 'warning', __( 'Podcast author', 'elementor-podcast-manager' ), __( 'Recommended: set the author shown in directories.', 'elementor-podcast-manager' ), $settings_url( 'epm-s-author' ), $fix_here );
		} else {
			$add( 'ok', __( 'Podcast author', 'elementor-podcast-manager' ), $author );
		}

		$owner_email = trim( (string) $settings->get( 'owner_email' ) );
		if ( '' === $owner_email || ! is_email( $owner_email ) ) {
			$add( 'error', __( 'Owner email', 'elementor-podcast-manager' ), __( 'Add a valid owner email address. Directories send the verification code to it.', 'elementor-podcast-manager' ), $settings_url( 'epm-s-owner-email' ), $fix_here );
		} else {
			$add( 'ok', __( 'Owner email', 'elementor-podcast-manager' ), $owner_email );
		}

		$owner_name = trim( (string) $settings->get( 'owner_name' ) );
		if ( '' === $owner_name ) {
			$add( 'warning', __( 'Owner name', 'elementor-podcast-manager' ), __( 'Recommended: set the podcast owner name.', 'elementor-podcast-manager' ), $settings_url( 'epm-s-owner-name' ), $fix_here );
		} else {
			$add( 'ok', __( 'Owner name', 'elementor-podcast-manager' ), $owner_name );
		}

		// --- Category & language. ---
		$category = trim( (string) $settings->get( 'category' ) );
		if ( '' === $category ) {
			$add( 'warning', __( 'Category', 'elementor-podcast-manager' ), __( 'Choose a category. Directories use it to help listeners find your show.', 'elementor-podcast-manager' ), $settings_url( 'epm-s-category' ), $fix_here );
		} elseif ( ! Categories::is_valid( $category ) ) {
			$add(
				'warning',
				__( 'Category', 'elementor-podcast-manager' ),
				sprintf(
					/* translators: %s: category name */
					__( '“%s” is not a top-level Apple Podcasts category. Choose one from the list.', 'elementor-podcast-manager' ),
					$category
				),
				$settings_url( 'epm-s-category' ),
				$fix_here
			);
		} else {
			$subcategory = (string) $settings->get( 'subcategory' );
			$add( 'ok', __( 'Category', 'elementor-podcast-manager' ), '' !== $subcategory ? $category . ' › ' . $subcategory : $category );
		}

		$language = Feed::rss_language( (string) $settings->get( 'language' ) );
		$add( 'ok', __( 'Language', 'elementor-podcast-manager' ), $language );

		// --- Artwork. ---
		$artwork_id  = (int) $settings->get( 'artwork_id' );
		$artwork_fix = $settings_url( 'epm-media-artwork_id' );
		if ( $artwork_id <= 0 ) {
			$add( 'error', __( 'Podcast artwork', 'elementor-podcast-manager' ), __( 'Add square podcast artwork (1400–3000 px, JPEG or PNG). Directories require it.', 'elementor-podcast-manager' ), $artwork_fix, $fix_here );
		} else {
			$mime = (string) get_post_mime_type( $artwork_id );
			if ( '' !== $mime && ! in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
				$add( 'error', __( 'Podcast artwork format', 'elementor-podcast-manager' ), __( 'Directories only accept JPEG or PNG artwork. Replace the image with a JPEG or PNG file.', 'elementor-podcast-manager' ), $artwork_fix, $fix_here );
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
						$artwork_fix,
						$fix_here
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
						$artwork_fix,
						$fix_here
					);
				} elseif ( $width !== $height ) {
					$add( 'warning', __( 'Podcast artwork', 'elementor-podcast-manager' ), __( 'Artwork should be exactly square.', 'elementor-podcast-manager' ), $artwork_fix, $fix_here );
				} else {
					/* translators: %1$d: width, %2$d: height */
					$add( 'ok', __( 'Podcast artwork', 'elementor-podcast-manager' ), sprintf( __( '%1$d×%2$d px', 'elementor-podcast-manager' ), $width, $height ) );
				}
			} else {
				$add( 'ok', __( 'Podcast artwork', 'elementor-podcast-manager' ), __( 'Present.', 'elementor-podcast-manager' ) );
			}
		}

		// --- Episodes (a page at a time: a large catalog never sits in memory). ---
		$distributable = 0;
		$seen_urls     = [];
		// After a move (or one that is not finished): what still loads
		// from the old host, per kind.
		$old_host = ! empty( epm()->settings->get( 'moved_in' ) ) || ImportJob::move_unfinished() ? self::old_host( epm()->episodes->each_public() ) : [];
		// YouTube (when it is tracked on the Distribution screen) rejects
		// "<" and ">" in titles and descriptions.
		$youtube = '' !== (string) ( Directories::progress()['youtube']['status'] ?? '' );
		if ( $youtube && self::has_angle_brackets( Feed::plain_text( (string) $settings->get( 'title' ) ) . Feed::plain_text( (string) $settings->get( 'description' ) ) ) ) {
			$add( 'warning', __( 'Podcast title and description', 'elementor-podcast-manager' ), __( 'YouTube does not accept “<” or “>” in titles and descriptions. Remove them from the podcast title and description.', 'elementor-podcast-manager' ), $settings_url( 'epm-s-title' ), $fix_here );
		}
		$first_episode = count( $checks );

		foreach ( epm()->episodes->each_public() as $post ) {
			$data = epm()->episodes->get_public_data( $post );
			if ( ! $data ) {
				continue;
			}

			$audio_id  = (int) $data['audio_id'];
			$external  = $audio_id <= 0 && 'external' === ( $data['audio_source'] ?? '' );
			$edit_url  = (string) get_edit_post_link( $post->ID, 'raw' );
			$edit_text = __( 'Edit episode', 'elementor-podcast-manager' );

			if ( $audio_id <= 0 && ! $external ) {
				$add(
					'warning',
					/* translators: %s: episode title */
					sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ),
					__( 'No audio yet, so it is not in the feed. Attach an MP3 or M4A file or an audio URL.', 'elementor-podcast-manager' ),
					$edit_url,
					$edit_text
				);
				continue;
			}

			if ( $external && 0 !== stripos( (string) $data['audio_url'], 'https://' ) ) {
				$add(
					'warning',
					sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ),
					__( 'The audio URL does not use HTTPS. Some apps refuse insecure audio; use an https:// address.', 'elementor-podcast-manager' ),
					$edit_url,
					$edit_text
				);
			}

			if ( ! $external && ! AudioMetadata::is_valid_audio_attachment( $audio_id ) ) {
				$add(
					'error',
					sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ),
					__( 'The audio file is missing or not a supported audio file. Attach the MP3 or M4A file again.', 'elementor-podcast-manager' ),
					$edit_url,
					$edit_text
				);
				continue;
			}

			$enclosure = AudioMetadata::enclosure( $data );

			if ( ! $enclosure['distributable'] ) {
				$add(
					'warning',
					sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ),
					__( 'This audio format is kept for storage only, so the episode is not in the feed. Replace it with an MP3 or M4A file.', 'elementor-podcast-manager' ),
					$edit_url,
					$edit_text
				);
				continue;
			}

			if ( ! $enclosure['size_known'] ) {
				$add(
					'warning',
					sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ),
					__( 'The file size is unknown, so the feed reports 0 bytes. Attach the file again or check the audio URL.', 'elementor-podcast-manager' ),
					$edit_url,
					$edit_text
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
					$edit_url,
					$edit_text
				);
				continue;
			}
			$seen_urls[ $enclosure['url'] ] = $data['title'];

			if ( $youtube && self::has_angle_brackets( Feed::plain_text( (string) $data['title'] ) . Feed::episode_summary( $post, $data ) ) ) {
				$add( 'warning', sprintf( __( 'Episode: %s', 'elementor-podcast-manager' ), $data['title'] ), __( 'YouTube does not accept “<” or “>” in titles and descriptions. Remove them from the title and the description.', 'elementor-podcast-manager' ), $edit_url, $edit_text );
			}

			$distributable++;
		}

		self::fold_episode_checks( $checks, $first_episode );

		self::old_host_checks( $add, $old_host );

		if ( 0 === $distributable ) {
			$add( 'error', __( 'Distributable episodes', 'elementor-podcast-manager' ), __( 'Publish at least one episode with MP3 or M4A audio. Directories reject empty feeds.', 'elementor-podcast-manager' ), admin_url( 'post-new.php?post_type=' . EpisodePostType::CPT ), __( 'Add an episode', 'elementor-podcast-manager' ) );
		} else {
			$add(
				'ok',
				__( 'Distributable episodes', 'elementor-podcast-manager' ),
				sprintf(
					/* translators: %s: number of episodes */
					_n( '%s episode with distribution-ready audio.', '%s episodes with distribution-ready audio.', $distributable, 'elementor-podcast-manager' ),
					number_format_i18n( $distributable )
				)
			);
		}

		return self::finish( $checks );
	}

	/**
	 * Checks for a show another host publishes.
	 *
	 * @param callable                                                     $add    Adds a check.
	 * @param array<int, array<string, string>> $checks Checks (by reference through $add).
	 * @return array<int, array<string, string>>
	 */
	private static function external_checks( callable $add, array &$checks ): array {
		$feed        = (string) Hosting::get( 'feed_url' );
		$state       = Hosting::state();
		$hosting_url = self::admin_page_url( 'epm-hosting' );
		$hosting_fix = __( 'Open hosting settings', 'elementor-podcast-manager' );

		$add( 'ok', __( 'Hosting', 'elementor-podcast-manager' ), Hosting::provider_name() );

		if ( '' === $feed ) {
			$add( 'error', __( 'Host feed', 'elementor-podcast-manager' ), __( 'Enter your host’s RSS feed address so episodes can sync.', 'elementor-podcast-manager' ), $hosting_url, $hosting_fix );
			return $checks;
		}

		$add( 'ok', __( 'Host feed', 'elementor-podcast-manager' ), Hosting::has_url_secret( $feed ) ? __( 'A private feed address is configured and is not shown here.', 'elementor-podcast-manager' ) : $feed );

		if ( ! Hosting::get( 'sync' ) ) {
			$add( 'warning', __( 'Automatic sync', 'elementor-podcast-manager' ), __( 'Off. New episodes from your host only appear here after “Sync now”.', 'elementor-podcast-manager' ), $hosting_url, $hosting_fix );
		}

		if ( in_array( $state['status'], [ 'error', 'partial' ], true ) ) {
			$add( 'error' === $state['status'] ? 'error' : 'warning', __( 'Last sync', 'elementor-podcast-manager' ), (string) $state['message'], $hosting_url, __( 'Check hosting settings', 'elementor-podcast-manager' ) );
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
			$job = ImportJob::get();
			$mirrored = 'done' === ( $job['status'] ?? '' ) && 'mirror' === ( $job['options']['purpose'] ?? '' );
			$add( 'warning', __( 'Last sync', 'elementor-podcast-manager' ), $mirrored ? __( 'Connected. The first automatic sync runs within the hour.', 'elementor-podcast-manager' ) : __( 'Not synced yet. Run “Sync now” or import the show.', 'elementor-podcast-manager' ), $hosting_url, $hosting_fix );
		}

		if ( ! Hosting::get( 'redirect' ) ) {
			$add( 'warning', __( 'Site feed', 'elementor-podcast-manager' ), __( 'This site still publishes its own feed. If apps find both, listeners may see the show twice; turn on the redirect to your host’s feed.', 'elementor-podcast-manager' ), $hosting_url, $hosting_fix );
		} else {
			$add( 'ok', __( 'Site feed', 'elementor-podcast-manager' ), __( 'Redirects permanently to your host’s feed.', 'elementor-podcast-manager' ) );
		}

		$count = epm()->episodes->count_published();
		if ( 0 === $count ) {
			$add( 'warning', __( 'Episodes', 'elementor-podcast-manager' ), __( 'No episodes on this site yet. Import them from your host’s feed.', 'elementor-podcast-manager' ), $hosting_url, __( 'Import episodes', 'elementor-podcast-manager' ) );
		} else {
			/* translators: %s: number of episodes */
			$add( 'ok', __( 'Episodes', 'elementor-podcast-manager' ), sprintf( _n( '%s published episode', '%s published episodes', $count, 'elementor-podcast-manager' ), number_format_i18n( $count ) ) );
		}

		// A move into this site that is not finished yet.
		if ( ImportJob::move_unfinished() ) {
			self::old_host_checks( $add, self::old_host( Episodes::each_public() ) );
		}
		if ( Hosting::sync_enabled() ) {
			if ( Hosting::sync_is_overdue() ) {
				$add( 'warning', __( 'Automatic sync', 'elementor-podcast-manager' ), __( 'The scheduled sync is overdue. Check WP-Cron or set up a server cron to request wp-cron.php regularly.', 'elementor-podcast-manager' ), $hosting_url, $hosting_fix );
			}
		}
		$state = ImportJob::get();
		if ( 'move' === ( $state['options']['purpose'] ?? '' ) && in_array( $state['status'] ?? '', [ 'cancelled', 'failed', 'running', 'waiting' ], true ) ) {
			$add( 'error', __( 'Move not finished', 'elementor-podcast-manager' ), sprintf( __( '%1$d of %2$d episodes have been imported. Resume the move before changing the feed redirect.', 'elementor-podcast-manager' ), (int) ( $state['position'] ?? 0 ), (int) ( $state['total'] ?? 0 ) ), $hosting_url, __( 'Resume the move', 'elementor-podcast-manager' ) );
		}

		return $checks;
	}

	/**
	 * Episodes with addresses that still point to the host they were
	 * imported from, per kind (see Importer::old_host_references()).
	 *
	 * @param iterable<\WP_Post> $episodes Episodes.
	 * @return array<string, array{count: int, items: array<int, array{title: string, url: string}>}>
	 */
	private static function old_host( iterable $episodes ): array {
		$out = [];
		foreach ( $episodes as $post ) {
			foreach ( Importer::old_host_references( (int) $post->ID ) as $kind => $url ) {
				$out[ $kind ]['count'] = (int) ( $out[ $kind ]['count'] ?? 0 ) + 1;
				if ( count( (array) ( $out[ $kind ]['items'] ?? [] ) ) < 10 ) {
					$out[ $kind ]['items'][] = [
						'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
						'url'   => (string) get_edit_post_link( $post->ID, 'raw' ),
					];
				}
			}
		}

		return $out;
	}

	/**
	 * One warning per kind of file that still loads from the old host,
	 * listing the episodes; and, while a move is not finished, a warning
	 * that says so.
	 *
	 * @param callable                                                                                $add      Adds a check.
	 * @param array<string, array{count: int, items: array<int, array{title: string, url: string}>}> $old_host From old_host().
	 * @return void
	 */
	private static function old_host_checks( callable $add, array $old_host ): void {
		$hosting = self::admin_page_url( 'epm-hosting' );

		if ( ImportJob::move_unfinished() ) {
			$add(
				'warning',
				__( 'Move to this website', 'elementor-podcast-manager' ),
				__( 'The move is not finished: files listed here still load from the old host. Copy them again, or finish the move knowing they stay there.', 'elementor-podcast-manager' ),
				$hosting,
				__( 'Open Hosting & import', 'elementor-podcast-manager' )
			);
		}

		foreach ( [ 'audio', 'image', 'transcript_file', 'transcript_link' ] as $kind ) {
			$count = (int) ( $old_host[ $kind ]['count'] ?? 0 );
			if ( $count <= 0 ) {
				continue;
			}
			$number = number_format_i18n( $count );
			$items  = (array) ( $old_host[ $kind ]['items'] ?? [] );
			$more   = max( 0, $count - count( $items ) );
			$copy   = __( 'Import with “Copy audio”', 'elementor-podcast-manager' );

			switch ( $kind ) {
				case 'audio':
					$label   = __( 'Audio at the old host', 'elementor-podcast-manager' );
					/* translators: %s: number of episodes */
					$message = sprintf( _n( 'The audio of %s episode still loads from the old host. Copy it before closing that account.', 'The audio of %s episodes still loads from the old host. Copy it before closing that account.', $count, 'elementor-podcast-manager' ), $number );
					break;
				case 'image':
					$label   = __( 'Episode images at the old host', 'elementor-podcast-manager' );
					/* translators: %s: number of episodes */
					$message = sprintf( _n( 'The image of %s episode still loads from the old host. Copy it before closing that account.', 'The images of %s episodes still load from the old host. Copy them before closing that account.', $count, 'elementor-podcast-manager' ), $number );
					break;
				case 'transcript_file':
					$label   = __( 'Transcript files at the old host', 'elementor-podcast-manager' );
					/* translators: %s: number of episodes */
					$message = sprintf( _n( 'The WebVTT/SRT transcript file of %s episode is still linked at the old host; podcast apps lose its captions when that account is closed. Copy it before closing that account.', 'The WebVTT/SRT transcript files of %s episodes are still linked at the old host; podcast apps lose their captions when that account is closed. Copy them before closing that account.', $count, 'elementor-podcast-manager' ), $number );
					break;
				default:
					$label   = __( 'Transcripts linked at the old host', 'elementor-podcast-manager' );
					$copy    = '';
					/* translators: %s: number of episodes */
					$message = sprintf( _n( 'The transcript of %s episode is linked at the old host in a format that is not copied (such as JSON). Its text is on this site; replace or remove the link before closing that account.', 'The transcripts of %s episodes are linked at the old host in a format that is not copied (such as JSON). Their text is on this site; replace or remove the links before closing that account.', $count, 'elementor-podcast-manager' ), $number );
			}

			$add( 'warning', $label, $message, '' !== $copy ? $hosting : '', $copy, $items, $more );
		}
	}

	/**
	 * Whether text contains "<" or ">".
	 *
	 * @param string $text Plain text.
	 * @return bool
	 */
	private static function has_angle_brackets( string $text ): bool {
		return false !== strpbrk( $text, '<>' );
	}

	/**
	 * Keep the report readable for large catalogs: after the first
	 * EPISODE_CHECKS problems with single episodes, the rest become one
	 * check that counts them and links to the episode list. The counts of
	 * errors and warnings still include every one of them.
	 *
	 * @param array<int, array<string, mixed>> $checks Checks (changed in place).
	 * @param int                              $first  Index of the first episode check.
	 * @return void
	 */
	private static function fold_episode_checks( array &$checks, int $first ): void {
		$episode_checks = array_slice( $checks, $first );
		if ( count( $episode_checks ) <= self::EPISODE_CHECKS ) {
			return;
		}

		$rest   = array_slice( $episode_checks, self::EPISODE_CHECKS );
		$errors = count(
			array_filter(
				$rest,
				static function ( $check ) {
					return 'error' === $check['status'];
				}
			)
		);
		$warnings = count( $rest ) - $errors;
		$url      = admin_url( 'edit.php?post_type=' . EpisodePostType::CPT );

		$checks   = array_slice( $checks, 0, $first + self::EPISODE_CHECKS );
		$checks[] = [
			'status'    => $errors > 0 ? 'error' : 'warning',
			'label'     => __( 'More episodes', 'elementor-podcast-manager' ),
			'message'   => sprintf(
				/* translators: 1: number of further episode problems, 2: of them errors, 3: of them warnings */
				_n( '%1$s more episode problem (%2$s errors, %3$s warnings) is not listed here.', '%1$s more episode problems (%2$s errors, %3$s warnings) are not listed here.', count( $rest ), 'elementor-podcast-manager' ),
				number_format_i18n( count( $rest ) ),
				number_format_i18n( $errors ),
				number_format_i18n( $warnings )
			),
			'url'       => $url,
			'fix_label' => __( 'Open the episode list', 'elementor-podcast-manager' ),
			'folded'    => [
				'error'   => $errors,
				'warning' => $warnings,
			],
		];
	}

	/**
	 * Sort checks and count problems.
	 *
	 * @param array<int, array<string, string>> $checks Checks.
	 * @return array{ready: bool, errors: int, warnings: int, checks: array<int, array<string, string>>}
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
			if ( isset( $check['folded'] ) ) {
				// Problems folded into one check count one by one.
				$errors   += (int) $check['folded']['error'];
				$warnings += (int) $check['folded']['warning'];
			} elseif ( 'error' === $check['status'] ) {
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
	 * Render the report as HTML: a summary, the problems (errors, then
	 * warnings) with a link to where each one is fixed, and the passed
	 * checks folded into a disclosure. Status is spelled out for screen
	 * readers; the icons are decorative.
	 *
	 * @param array<string, mixed>|null $report Report from report(); built when omitted.
	 * @return string
	 */
	public static function render_html( ?array $report = null ): string {
		$report   = $report ?? self::report();
		$problems = [];
		$passed   = [];

		foreach ( $report['checks'] as $check ) {
			if ( 'ok' === $check['status'] ) {
				$passed[] = $check;
			} else {
				$problems[] = $check;
			}
		}

		$out  = '<div class="epm-readiness">';
		$out .= '<p class="epm-readiness__summary">';
		if ( $report['ready'] ) {
			$sync_state = Hosting::is_external() ? Hosting::state() : [];
			$badge = Hosting::is_external() ? ( empty( $sync_state['last_success'] ) ? __( 'Connected', 'elementor-podcast-manager' ) : __( 'In sync', 'elementor-podcast-manager' ) ) : __( 'Ready for distribution', 'elementor-podcast-manager' );
			$class = 'epm-badge--ok';
			if ( Hosting::sync_is_overdue() ) {
				$badge = __( 'Sync overdue', 'elementor-podcast-manager' );
				$class = 'epm-badge--warn';
			}
			$out .= '<span class="epm-badge ' . $class . '"><span class="epm-badge__dot" aria-hidden="true"></span>' . esc_html( $badge ) . '</span>';
		} else {
			$out .= '<span class="epm-badge epm-badge--error"><span class="epm-badge__dot" aria-hidden="true"></span>' . esc_html__( 'Not ready', 'elementor-podcast-manager' ) . '</span>';
		}

		$counts = [];
		if ( $report['errors'] > 0 ) {
			/* translators: %s: number of problems */
			$counts[] = sprintf( _n( '%s problem', '%s problems', $report['errors'], 'elementor-podcast-manager' ), number_format_i18n( $report['errors'] ) );
		}
		if ( $report['warnings'] > 0 ) {
			/* translators: %s: number of warnings */
			$counts[] = sprintf( _n( '%s warning', '%s warnings', $report['warnings'], 'elementor-podcast-manager' ), number_format_i18n( $report['warnings'] ) );
		}
		if ( ! empty( $counts ) ) {
			$out .= ' <span class="epm-readiness__counts">' . esc_html( implode( ', ', $counts ) ) . '</span>';
		}
		$out .= '</p>';

		if ( empty( $problems ) ) {
			$out .= '<p class="epm-readiness__empty">' . esc_html__( 'No problems found. Everything that is checked here is in place.', 'elementor-podcast-manager' ) . '</p>';
		} else {
			$out .= '<ul class="epm-checklist epm-readiness__list">' . self::render_items( $problems ) . '</ul>';
		}

		if ( ! empty( $passed ) ) {
			$out .= '<details class="epm-details epm-readiness__passed">';
			$out .= '<summary>' . esc_html(
				sprintf(
					/* translators: %s: number of passed checks */
					_n( 'Show %s passed check', 'Show %s passed checks', count( $passed ), 'elementor-podcast-manager' ),
					number_format_i18n( count( $passed ) )
				)
			) . '</summary>';
			$out .= '<div class="epm-details__body"><ul class="epm-checklist">' . self::render_items( $passed ) . '</ul></div>';
			$out .= '</details>';
		}

		if ( ! Hosting::is_external() ) {
			// The delivery test lives on the Distribution screen; the link is
			// only there for users who can open it.
			$check_url = self::admin_page_url( 'epm-distribution', 'epm-dist-check' );
			$out      .= '<p class="description epm-readiness__note">' . esc_html__( 'Directories also check that your server delivers the audio correctly.', 'elementor-podcast-manager' );
			if ( '' !== $check_url ) {
				$out .= ' <a href="' . esc_url( $check_url ) . '">' . esc_html__( 'Test feed and audio delivery', 'elementor-podcast-manager' ) . '</a>';
			}
			$out .= '</p>';
		}

		$out .= '</div>';

		return $out;
	}

	/**
	 * The episodes a check is about, linked to their editor.
	 *
	 * @param array<int, array{title: string, url: string}> $items Episodes.
	 * @param int                                           $more  Episodes not listed.
	 * @return string
	 */
	private static function render_episodes( array $items, int $more ): string {
		if ( empty( $items ) ) {
			return '';
		}

		$out = '<ul class="epm-checklist__episodes">';
		foreach ( $items as $item ) {
			$title = '' !== (string) $item['title'] ? (string) $item['title'] : __( '(no title)', 'elementor-podcast-manager' );
			$out  .= '<li>' . ( '' !== (string) $item['url'] ? '<a href="' . esc_url( (string) $item['url'] ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</li>';
		}
		if ( $more > 0 ) {
			/* translators: %s: number of episodes */
			$out .= '<li>' . esc_html( sprintf( _n( 'and %s more', 'and %s more', $more, 'elementor-podcast-manager' ), number_format_i18n( $more ) ) ) . '</li>';
		}

		return $out . '</ul>';
	}

	/**
	 * Checklist items.
	 *
	 * @param array<int, array<string, string>> $checks Checks.
	 * @return string
	 */
	private static function render_items( array $checks ): string {
		$status_text = [
			'error'   => __( 'Problem:', 'elementor-podcast-manager' ),
			'warning' => __( 'Warning:', 'elementor-podcast-manager' ),
			'ok'      => __( 'Passed:', 'elementor-podcast-manager' ),
		];
		$icons       = [
			'error'   => '✕',
			'warning' => '!',
			'ok'      => '✓',
		];

		$out = '';
		foreach ( $checks as $check ) {
			$status = isset( $icons[ $check['status'] ] ) ? $check['status'] : 'warning';
			// Links are only present for users who can open the screen.
			$url    = (string) ( $check['url'] ?? '' );
			$label  = (string) ( $check['fix_label'] ?? '' );

			$out .= '<li class="epm-checklist__item epm-checklist__item--' . esc_attr( $status ) . '">';
			$out .= '<span class="epm-checklist__icon" aria-hidden="true">' . esc_html( $icons[ $status ] ) . '</span>';
			$out .= '<span class="epm-checklist__label"><span class="screen-reader-text">' . esc_html( $status_text[ $status ] ) . ' </span>' . esc_html( (string) $check['label'] ) . '</span>';
			$out .= '<p class="epm-checklist__text">' . esc_html( (string) $check['message'] );
			if ( '' !== $url && '' !== $label ) {
				$out .= ' <a class="epm-readiness__fix" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '<span class="screen-reader-text">: ' . esc_html( (string) $check['label'] ) . '</span></a>';
			}
			$out .= '</p>';
			$out .= self::render_episodes( (array) ( $check['items'] ?? [] ), (int) ( $check['more'] ?? 0 ) );
			$out .= '</li>';
		}

		return $out;
	}
}
