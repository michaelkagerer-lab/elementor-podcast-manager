<?php
/**
 * Podcast dashboard view.
 *
 * Built from the shared app components (admin/css/epm-app.css): the show,
 * where it is hosted, how far distribution got, the latest episode and the
 * readiness report with problems first.
 *
 * @package EPM
 *
 * @var EPM\PodcastSettings    $settings
 * @var \WP_Post|null          $latest
 * @var array|null             $latest_data
 * @var int                    $count
 * @var int                    $drafts
 * @var int                    $scheduled
 * @var string                 $artwork
 * @var bool                   $can_manage
 * @var bool                   $needs_setup
 * @var string                 $page_url
 * @var array<string, mixed>   $hosting
 * @var array<string, mixed>   $distribution
 * @var array<string, mixed>   $readiness
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$epm_new_episode = admin_url( 'post-new.php?post_type=' . \EPM\EpisodePostType::CPT );
$epm_title       = trim( (string) $settings->get( 'title' ) );
$epm_state       = (array) $hosting['state'];
$epm_new_tab     = '<span class="screen-reader-text">' . esc_html__( '(opens in a new tab)', 'elementor-podcast-manager' ) . '</span>';

/**
 * Relative time with the absolute date for a timestamp.
 *
 * @param int $timestamp Unix timestamp.
 * @return string Escaped HTML.
 */
$epm_when = static function ( int $timestamp ): string {
	if ( $timestamp <= 0 ) {
		return esc_html__( 'Never', 'elementor-podcast-manager' );
	}

	return sprintf(
		'<time datetime="%1$s">%2$s</time> <span class="epm-muted">· %3$s</span>',
		esc_attr( gmdate( 'c', $timestamp ) ),
		/* translators: %s: human time difference, e.g. "5 mins" */
		esc_html( sprintf( __( '%s ago', 'elementor-podcast-manager' ), human_time_diff( $timestamp ) ) ),
		esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) )
	);
};

$epm_status_label = [
	''          => __( 'Not submitted', 'elementor-podcast-manager' ),
	'submitted' => __( 'Submitted', 'elementor-podcast-manager' ),
	'listed'    => __( 'Listed', 'elementor-podcast-manager' ),
];
?>
<div class="wrap epm-app epm-dashboard epm-studio">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Podcast', 'elementor-podcast-manager' ); ?></h1>
	<hr class="wp-header-end" />

	<?php
	$epm_problem = null;
	foreach ( [ 'error', 'warning' ] as $epm_severity ) {
		foreach ( $readiness['checks'] as $epm_check ) {
			if ( $epm_severity === $epm_check['status'] ) { $epm_problem = $epm_check; break 2; }
		}
	}
	$epm_next_title = __( 'Publish your next episode', 'elementor-podcast-manager' );
	$epm_next_text = __( 'Add audio and show notes, then publish when the episode is ready.', 'elementor-podcast-manager' );
	$epm_next_label = __( 'Add episode', 'elementor-podcast-manager' );
	$epm_next_url = $epm_new_episode;
	if ( $needs_setup ) {
		$epm_next_title = __( 'Finish setting up your podcast', 'elementor-podcast-manager' );
		$epm_next_text = __( 'Choose hosting, add your show details and create your podcast page.', 'elementor-podcast-manager' );
		$epm_next_label = __( 'Continue setup', 'elementor-podcast-manager' );
		$epm_next_url = admin_url( 'admin.php?page=epm-setup' );
	} elseif ( $epm_problem && 'error' === $epm_problem['status'] && ! $hosting['external'] ) {
		$epm_next_title = __( 'Review the episodes and feed before submitting', 'elementor-podcast-manager' );
		$epm_next_text = $epm_problem['message'];
		$epm_next_label = __( 'Review feed checks', 'elementor-podcast-manager' );
		$epm_next_url = '#epm-dash-ready-title';
	} elseif ( ! empty( $work_drafts ) ) {
		$epm_next_title = __( 'Pick up where you left off', 'elementor-podcast-manager' );
		$epm_next_text = get_the_title( $work_drafts[0] ) ?: __( 'Untitled episode', 'elementor-podcast-manager' );
		$epm_next_label = __( 'Continue editing', 'elementor-podcast-manager' );
		$epm_next_url = get_edit_post_link( $work_drafts[0]->ID, 'raw' );
	} elseif ( $epm_problem && ! $hosting['external'] ) {
		$epm_next_title = __( 'Review the episodes and feed before submitting', 'elementor-podcast-manager' );
		$epm_next_text = $epm_problem['message'];
		$epm_next_label = __( 'Review feed checks', 'elementor-podcast-manager' );
		$epm_next_url = '#epm-dash-ready-title';

	}
	?>

	<section class="epm-card epm-dashboard__show" aria-labelledby="epm-dash-show-title">
		<div class="epm-dashboard__art">
			<?php if ( $artwork ) : ?>
				<img src="<?php echo esc_url( $artwork ); ?>" alt="" width="112" height="112" />
			<?php else : ?>
				<span class="dashicons dashicons-microphone" aria-hidden="true"></span>
			<?php endif; ?>
		</div>
		<div>
			<h2 class="epm-dashboard__show-title" id="epm-dash-show-title"><?php echo esc_html( '' !== $epm_title ? $epm_title : __( 'Untitled podcast', 'elementor-podcast-manager' ) ); ?></h2>
			<p class="epm-dashboard__badges">
				<?php if ( $settings->is_configured() ) : ?>
					<span class="epm-badge epm-badge--ok"><span class="epm-badge__dot" aria-hidden="true"></span><?php esc_html_e( 'Configured', 'elementor-podcast-manager' ); ?></span>
				<?php else : ?>
					<span class="epm-badge epm-badge--warn"><span class="epm-badge__dot" aria-hidden="true"></span><?php esc_html_e( 'Not configured yet', 'elementor-podcast-manager' ); ?></span>
				<?php endif; ?>
				<span class="epm-badge epm-badge--info">
					<?php
					echo esc_html(
						$hosting['external']
							/* translators: %s: podcast host name */
							? sprintf( __( 'Hosted on %s', 'elementor-podcast-manager' ), (string) $hosting['provider'] )
							: __( 'Hosted on this website', 'elementor-podcast-manager' )
					);
					?>
				</span>
			</p>
			<ul class="epm-facts">
				<li>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=podcast_episode&post_status=publish' ) ); ?>"><strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
					<span><?php echo esc_html( _n( 'published episode', 'published episodes', $count, 'elementor-podcast-manager' ) ); ?></span></a>
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=podcast_episode&post_status=draft' ) ); ?>"><strong><?php echo esc_html( number_format_i18n( (int) ( $counts->draft ?? 0 ) ) ); ?></strong>
					<span><?php echo esc_html( _n( 'draft', 'drafts', (int) ( $counts->draft ?? 0 ), 'elementor-podcast-manager' ) ); ?></span></a>
				</li>
				<li>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=podcast_episode&post_status=future' ) ); ?>"><strong><?php echo esc_html( number_format_i18n( $scheduled ) ); ?></strong>
					<span><?php esc_html_e( 'scheduled', 'elementor-podcast-manager' ); ?></span></a>
				</li>
				<?php if ( ! empty( $counts->pending ) ) : ?>
				<li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=podcast_episode&post_status=pending' ) ); ?>"><strong><?php echo esc_html( number_format_i18n( $counts->pending ) ); ?></strong><span><?php esc_html_e( 'Pending review', 'elementor-podcast-manager' ); ?></span></a></li>
				<?php endif; ?>
			</ul>
		</div>
		<div class="epm-dashboard__show-actions">
			<a class="button <?php echo ! $needs_setup && ! $epm_problem && empty( $work_drafts ) ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $epm_new_episode ); ?>"><?php esc_html_e( 'Add episode', 'elementor-podcast-manager' ); ?></a>
			<?php if ( $can_manage ) : ?>
				<a class="epm-button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=epm-settings' ) ); ?>"><?php esc_html_e( 'Edit podcast details', 'elementor-podcast-manager' ); ?></a>
				<a class="epm-button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=epm-design' ) ); ?>"><?php esc_html_e( 'Customize design', 'elementor-podcast-manager' ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $page_url ) : ?>
				<a class="epm-button-link" href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'View podcast page', 'elementor-podcast-manager' ); ?></a>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( $needs_setup || $epm_problem || ! empty( $work_drafts ) ) : ?>
	<section class="epm-dashboard__next" data-epm-next-action aria-labelledby="epm-next-title"<?php echo $needs_setup ? ' data-epm-setup-card' : ''; ?>>
		<div><h2 id="epm-next-title"><?php echo esc_html( $epm_next_title ); ?></h2><p><?php echo esc_html( $epm_next_text ); ?></p></div>
		<div class="epm-dashboard__setup-actions"><a class="button button-primary" href="<?php echo esc_url( $epm_next_url ); ?>"><?php echo esc_html( $epm_next_label ); ?></a>
		<?php if ( $needs_setup ) : ?><button type="button" class="epm-button-link epm-button-link--muted" data-epm-setup-dismiss data-nonce="<?php echo esc_attr( wp_create_nonce( 'epm_setup' ) ); ?>"><?php esc_html_e( 'Skip for now', 'elementor-podcast-manager' ); ?></button><?php endif; ?></div>
	</section>

	<?php endif; ?>
	<div class="epm-studio__editorial">
	<?php require EPM_PATH . 'admin/views/partials/editorial-queue.php'; ?>
		<!-- Latest episode -->
		<section class="epm-card epm-studio__latest" aria-labelledby="epm-dash-latest-title">
			<h2 class="epm-card__title" id="epm-dash-latest-title"><?php esc_html_e( 'Latest episode', 'elementor-podcast-manager' ); ?></h2>
			<?php if ( $latest_data ) : ?>
				<?php
				$epm_audio   = \EPM\Admin::audio_status( $latest_data );
				$epm_thumb   = '' !== (string) $latest_data['artwork_url']
					? (string) $latest_data['artwork_url']
					: ( (int) $latest_data['artwork_id'] > 0 ? (string) wp_get_attachment_image_url( (int) $latest_data['artwork_id'], 'thumbnail' ) : '' );
				$epm_thumb = $epm_thumb ?: $artwork;
				$epm_meta    = array_filter(
					[
						(string) $latest_data['date'],
						(string) $latest_data['duration'],
					]
				);
				$epm_edit    = get_edit_post_link( $latest );
				$epm_number  = (string) $latest_data['episode_number'];
				?>
				<div class="epm-dashboard__body">
					<div class="epm-dashboard__episode">
						<?php if ( '' !== $epm_thumb ) : ?>
							<img class="epm-dashboard__episode-art" src="<?php echo esc_url( $epm_thumb ); ?>" alt="" width="64" height="64" />
						<?php else : ?>
							<span class="epm-dashboard__episode-art" aria-hidden="true"></span>
						<?php endif; ?>
						<div>
							<p class="epm-dashboard__episode-title">
								<?php if ( $epm_edit ) : ?>
									<a href="<?php echo esc_url( $epm_edit ); ?>"><?php echo esc_html( (string) $latest_data['title'] ); ?></a>
								<?php else : ?>
									<?php echo esc_html( (string) $latest_data['title'] ); ?>
								<?php endif; ?>
							</p>
							<p class="epm-dashboard__meta">
								<?php
								if ( '' !== $epm_number ) {
									/* translators: %s: episode number */
									echo esc_html( sprintf( __( 'Episode %s', 'elementor-podcast-manager' ), $epm_number ) ) . ' · ';
								}
								echo esc_html( implode( ' · ', $epm_meta ) );
								?>
							</p>
							<span class="epm-status epm-status--<?php echo esc_attr( $epm_audio['key'] ); ?>"><?php echo esc_html( $epm_audio['label'] ); ?></span>
						</div>
					</div>
					<?php if ( ! empty( $latest_data['audio_url'] ) ) : ?><div class="epm-studio__preview" data-epm-preview>
						<button type="button" class="button epm-studio__play" hidden data-epm-preview-play data-loading="<?php esc_attr_e( 'Loading…', 'elementor-podcast-manager' ); ?>" data-retry="<?php esc_attr_e( 'Retry', 'elementor-podcast-manager' ); ?>"><span class="dashicons dashicons-controls-play" aria-hidden="true"></span><?php esc_html_e( 'Play episode', 'elementor-podcast-manager' ); ?></button>
						<p class="epm-field__error" data-epm-preview-error role="alert" hidden><?php esc_html_e( 'This audio could not be loaded. Check your connection and try again.', 'elementor-podcast-manager' ); ?></p>
						<audio class="epm-studio__audio" controls preload="none" aria-label="<?php echo esc_attr( (string) $latest_data['title'] ); ?>" src="<?php echo esc_url( $latest_data['audio_url'] ); ?>"></audio></div><?php endif; ?>
					<?php if ( '' !== $epm_audio['title'] ) : ?>
						<p class="epm-muted"><?php echo esc_html( $epm_audio['title'] ); ?></p>
					<?php endif; ?>
				</div>
				<div class="epm-card__footer">
					<?php if ( $epm_edit ) : ?>
						<a class="button" href="<?php echo esc_url( $epm_edit ); ?>"><?php esc_html_e( 'Edit episode', 'elementor-podcast-manager' ); ?></a>
					<?php endif; ?>
					<a class="epm-button-link" href="<?php echo esc_url( (string) $latest_data['url'] ); ?>"><?php esc_html_e( 'View episode', 'elementor-podcast-manager' ); ?></a>
				</div>
			<?php else : ?>
				<p class="epm-dashboard__empty">
					<?php
					echo esc_html(
						$hosting['external']
							? __( 'No episodes yet. Import your show from your host, or add an episode here.', 'elementor-podcast-manager' )
							: __( 'No episodes yet. Add your first one: upload the audio, give it a title and publish. It appears in your feed right away.', 'elementor-podcast-manager' )
					);
					?>
				</p>
				<div class="epm-card__footer">
					<a class="button" href="<?php echo esc_url( $epm_new_episode ); ?>"><?php esc_html_e( 'Add your first episode', 'elementor-podcast-manager' ); ?></a>
					<?php if ( $can_manage ) : ?>
						<a class="epm-button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=epm-hosting' ) ); ?>"><?php esc_html_e( 'Import episodes from a feed', 'elementor-podcast-manager' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</section>

	</div>
	<div class="epm-grid epm-dashboard__grid">
		<!-- Hosting -->
		<section class="epm-card" aria-labelledby="epm-dash-hosting-title">
			<h2 class="epm-card__title" id="epm-dash-hosting-title"><?php esc_html_e( 'Hosting', 'elementor-podcast-manager' ); ?></h2>
			<div class="epm-dashboard__body">
				<p class="epm-card__lede">
					<?php
					if ( $hosting['external'] && '' !== \EPM\Hosting::source_feed_url() ) {
						printf(
							/* translators: %s: podcast host name */
							esc_html__( '%s publishes your RSS feed. This website mirrors the episodes.', 'elementor-podcast-manager' ),
							'<strong>' . esc_html( (string) $hosting['provider'] ) . '</strong>'
						);
					} else {
						esc_html_e( 'This website publishes your RSS feed and serves the audio.', 'elementor-podcast-manager' );
					}
					?>
				</p>
				<div>
					<span class="epm-dashboard__label"><?php esc_html_e( 'Feed address', 'elementor-podcast-manager' ); ?></span>
					<div class="epm-copy">
						<code class="epm-copy__value"><?php echo esc_html( (string) $hosting['feed'] ); ?></code>
						<?php echo \EPM\Admin::copy_button( (string) $hosting['feed'], __( 'Copy feed address', 'elementor-podcast-manager' ), '', __( 'Feed address copied.', 'elementor-podcast-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in copy_button(). ?>
					</div>
				</div>
				<?php if ( $hosting['external'] ) : ?>
					<dl class="epm-kv">
						<dt><?php esc_html_e( 'Sync', 'elementor-podcast-manager' ); ?></dt>
						<dd>
							<?php if ( 'error' === (string) $epm_state['status'] ) : ?>
								<span class="epm-badge epm-badge--error"><span class="epm-badge__dot" aria-hidden="true"></span><?php esc_html_e( 'Problem', 'elementor-podcast-manager' ); ?></span>
							<?php elseif ( (int) $epm_state['last_success'] > 0 ) : ?>
								<span class="epm-badge epm-badge--ok"><span class="epm-badge__dot" aria-hidden="true"></span><?php esc_html_e( 'Working', 'elementor-podcast-manager' ); ?></span>
							<?php else : ?>
								<span class="epm-badge"><?php esc_html_e( 'Not synced yet', 'elementor-podcast-manager' ); ?></span>
							<?php endif; ?>
							<?php if ( ! $hosting['sync'] ) : ?>
								<span class="epm-muted"><?php esc_html_e( 'Automatic sync is off', 'elementor-podcast-manager' ); ?></span>
							<?php endif; ?>
						</dd>
						<dt><?php esc_html_e( 'Last check', 'elementor-podcast-manager' ); ?></dt>
						<dd class="epm-dashboard__when"><?php echo $epm_when( (int) $epm_state['last_run'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in $epm_when. ?></dd>
					</dl>
					<?php if ( '' !== (string) $epm_state['message'] ) : ?>
						<p class="<?php echo 'error' === (string) $epm_state['status'] ? 'epm-field__error' : 'epm-muted'; ?>"><?php echo esc_html( (string) $epm_state['message'] ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
			<div class="epm-card__footer">
				<?php if ( $can_manage ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=epm-hosting' ) ); ?>"><?php echo esc_html( $hosting['external'] ? __( 'Manage sync and hosting', 'elementor-podcast-manager' ) : __( 'Change hosting', 'elementor-podcast-manager' ) ); ?></a>
				<?php endif; ?>
				<?php if ( '' !== (string) $hosting['feed'] ) : ?>
				<a class="epm-button-link" href="<?php echo esc_url( (string) $hosting['feed'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View feed', 'elementor-podcast-manager' ); ?><?php echo $epm_new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></a>
				<?php endif; ?>
			</div>
		</section>

		<!-- Distribution -->
		<section class="epm-card" aria-labelledby="epm-dash-dist-title">
			<h2 class="epm-card__title" id="epm-dash-dist-title"><?php esc_html_e( 'Distribution', 'elementor-podcast-manager' ); ?></h2>
			<div class="epm-dashboard__body">
				<p class="epm-dashboard__score">
					<strong>
						<?php
						/* translators: 1: submitted platforms, 2: listed platforms */
						echo esc_html( sprintf( __( '%1$s submitted, %2$s listed', 'elementor-podcast-manager' ), number_format_i18n( (int) $distribution['submitted'] ), number_format_i18n( (int) $distribution['listed'] ) ) );
						?>
					</strong>
					<span class="epm-muted"><?php esc_html_e( 'Essential platforms — your records, not verified by this plugin.', 'elementor-podcast-manager' ); ?></span>
				</p>
				<ul class="epm-dashboard__platforms">
					<?php foreach ( $distribution['rows'] as $epm_row ) : ?>
						<?php
						$epm_svg   = '' !== $epm_row['icon'] ? \EPM\BrandIcons::svg( $epm_row['icon'] ) : '';
						$epm_badge = 'listed' === $epm_row['status'] ? 'epm-badge--ok' : ( 'submitted' === $epm_row['status'] ? 'epm-badge--info' : '' );
						?>
						<li>
							<span class="epm-dashboard__platform-icon" aria-hidden="true">
								<?php
								if ( '' !== $epm_svg ) {
									echo $epm_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG from BrandIcons.
								} else {
									echo esc_html( function_exists( 'mb_substr' ) ? mb_substr( $epm_row['name'], 0, 1 ) : substr( $epm_row['name'], 0, 1 ) );
								}
								?>
							</span>
							<span class="epm-dashboard__platform-name"><?php echo esc_html( $epm_row['name'] ); ?></span>
							<span class="epm-badge <?php echo esc_attr( $epm_badge ); ?>"><?php echo esc_html( $epm_status_label[ $epm_row['status'] ] ?? $epm_status_label[''] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php if ( $can_manage ) : ?>
				<div class="epm-card__footer">
					<?php if ( '' !== $distribution['next'] ) : ?>
						<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=epm-distribution' ) . '#epm-dir-' . rawurlencode( (string) $distribution['next'] ) ); ?>">
							<?php
							/* translators: %s: platform name */
							echo esc_html( sprintf( __( 'Submit to %s', 'elementor-podcast-manager' ), (string) $distribution['next_name'] ) );
							?>
						</a>
					<?php endif; ?>
					<a class="epm-button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=epm-distribution' ) ); ?>"><?php echo esc_html( '' !== $distribution['next'] ? __( 'See all platforms', 'elementor-podcast-manager' ) : __( 'Add more platforms', 'elementor-podcast-manager' ) ); ?></a>
				</div>
			<?php endif; ?>
		</section>


	</div>

	<section class="epm-card epm-dashboard__readiness" aria-labelledby="epm-dash-ready-title">
		<div class="epm-readiness__heading">
			<div>
				<h2 class="epm-card__title" id="epm-dash-ready-title"><?php esc_html_e( 'Readiness', 'elementor-podcast-manager' ); ?></h2>
				<p class="epm-card__lede">
					<?php
					if ( $hosting['external'] ) {
						printf(
							/* translators: %s: podcast host name */
							esc_html__( 'What keeps this website in sync with %s.', 'elementor-podcast-manager' ),
							esc_html( (string) $hosting['provider'] )
						);
					} else {
						esc_html_e( 'These checks apply to the feed. Episodes without supported audio stay outside it; review the warnings to include them.', 'elementor-podcast-manager' );
					}
					?>
				</p>
			</div>
		</div>
		<?php echo \EPM\Readiness::render_html( $readiness ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_html(). ?>
	</section>
</div>
