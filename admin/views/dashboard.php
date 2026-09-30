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
<div class="wrap epm-app epm-dashboard">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Podcast', 'elementor-podcast-manager' ); ?></h1>
	<a href="<?php echo esc_url( $epm_new_episode ); ?>" class="page-title-action"><?php esc_html_e( 'Add episode', 'elementor-podcast-manager' ); ?></a>
	<hr class="wp-header-end" />

	<?php if ( $needs_setup ) : ?>
		<section class="epm-card epm-dashboard__setup" aria-labelledby="epm-dash-setup-title" data-epm-setup-card>
			<div>
				<h2 class="epm-card__title" id="epm-dash-setup-title"><?php esc_html_e( 'Set up your podcast', 'elementor-podcast-manager' ); ?></h2>
				<p class="epm-card__lede"><?php esc_html_e( 'Choose where your show is hosted, add its details and artwork, and get the feed address for Apple Podcasts and Spotify. It takes about five minutes, and you can change everything later.', 'elementor-podcast-manager' ); ?></p>
			</div>
			<p class="epm-dashboard__setup-actions">
				<a class="button button-primary button-large" href="<?php echo esc_url( admin_url( 'admin.php?page=epm-setup' ) ); ?>"><?php esc_html_e( 'Start setup', 'elementor-podcast-manager' ); ?></a>
				<button type="button" class="epm-button-link epm-button-link--muted" data-epm-setup-dismiss data-nonce="<?php echo esc_attr( wp_create_nonce( 'epm_setup' ) ); ?>"><?php esc_html_e( 'Skip for now', 'elementor-podcast-manager' ); ?></button>
			</p>
		</section>
	<?php endif; ?>

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
					<strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
					<span><?php echo esc_html( _n( 'published episode', 'published episodes', $count, 'elementor-podcast-manager' ) ); ?></span>
				</li>
				<li>
					<strong><?php echo esc_html( number_format_i18n( $drafts ) ); ?></strong>
					<span><?php echo esc_html( _n( 'draft', 'drafts', $drafts, 'elementor-podcast-manager' ) ); ?></span>
				</li>
				<li>
					<strong><?php echo esc_html( number_format_i18n( $scheduled ) ); ?></strong>
					<span><?php esc_html_e( 'scheduled', 'elementor-podcast-manager' ); ?></span>
				</li>
			</ul>
		</div>
		<div class="epm-dashboard__show-actions">
			<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . \EPM\EpisodePostType::CPT ) ); ?>"><?php esc_html_e( 'View all episodes', 'elementor-podcast-manager' ); ?></a>
			<?php if ( $can_manage ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=epm-settings' ) ); ?>"><?php esc_html_e( 'Edit podcast details', 'elementor-podcast-manager' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=epm-design' ) ); ?>"><?php esc_html_e( 'Customize design', 'elementor-podcast-manager' ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $page_url ) : ?>
				<a class="epm-button-link" href="<?php echo esc_url( $page_url ); ?>"><?php esc_html_e( 'View podcast page', 'elementor-podcast-manager' ); ?></a>
			<?php endif; ?>
		</div>
	</section>

	<div class="epm-grid epm-dashboard__grid">
		<!-- Hosting -->
		<section class="epm-card" aria-labelledby="epm-dash-hosting-title">
			<h2 class="epm-card__title" id="epm-dash-hosting-title"><?php esc_html_e( 'Hosting', 'elementor-podcast-manager' ); ?></h2>
			<div class="epm-dashboard__body">
				<p class="epm-card__lede">
					<?php
					if ( $hosting['external'] ) {
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
						<?php echo \EPM\Admin::copy_button( (string) $hosting['feed'], __( 'Copy feed URL', 'elementor-podcast-manager' ), '', __( 'Feed URL copied.', 'elementor-podcast-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in copy_button(). ?>
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
				<a class="epm-button-link" href="<?php echo esc_url( (string) $hosting['feed'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View feed', 'elementor-podcast-manager' ); ?><?php echo $epm_new_tab; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></a>
			</div>
		</section>

		<!-- Distribution -->
		<section class="epm-card" aria-labelledby="epm-dash-dist-title">
			<h2 class="epm-card__title" id="epm-dash-dist-title"><?php esc_html_e( 'Distribution', 'elementor-podcast-manager' ); ?></h2>
			<div class="epm-dashboard__body">
				<p class="epm-dashboard__score">
					<strong>
						<?php
						/* translators: 1: platforms done, 2: essential platforms */
						echo esc_html( sprintf( __( '%1$s of %2$s', 'elementor-podcast-manager' ), number_format_i18n( (int) $distribution['done'] ), number_format_i18n( (int) $distribution['total'] ) ) );
						?>
					</strong>
					<span class="epm-muted"><?php esc_html_e( 'essential platforms submitted', 'elementor-podcast-manager' ); ?></span>
				</p>
				<div class="epm-dashboard__meter" aria-hidden="true"><span style="--epm-progress: <?php echo esc_attr( (string) ( $distribution['total'] > 0 ? round( $distribution['done'] / $distribution['total'], 3 ) : 0 ) ); ?>"></span></div>
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
						<a class="button<?php echo $count > 0 ? ' button-primary' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=epm-distribution' ) . '#epm-dir-' . rawurlencode( (string) $distribution['next'] ) ); ?>">
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

		<!-- Latest episode -->
		<section class="epm-card" aria-labelledby="epm-dash-latest-title">
			<h2 class="epm-card__title" id="epm-dash-latest-title"><?php esc_html_e( 'Latest episode', 'elementor-podcast-manager' ); ?></h2>
			<?php if ( $latest_data ) : ?>
				<?php
				$epm_audio   = \EPM\Admin::audio_status( $latest_data );
				$epm_thumb   = '' !== (string) $latest_data['artwork_url']
					? (string) $latest_data['artwork_url']
					: ( (int) $latest_data['artwork_id'] > 0 ? (string) wp_get_attachment_image_url( (int) $latest_data['artwork_id'], 'thumbnail' ) : '' );
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
							<img class="epm-dashboard__episode-art" src="<?php echo esc_url( $epm_thumb ); ?>" alt="" width="64" height="64" loading="lazy" />
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
					<a class="button button-primary" href="<?php echo esc_url( $epm_new_episode ); ?>"><?php esc_html_e( 'Add your first episode', 'elementor-podcast-manager' ); ?></a>
					<?php if ( $can_manage ) : ?>
						<a class="epm-button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=epm-hosting' ) ); ?>"><?php esc_html_e( 'Import episodes from a feed', 'elementor-podcast-manager' ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</section>
	</div>

	<section class="epm-card epm-dashboard__readiness" aria-labelledby="epm-dash-ready-title">
		<?php
		$epm_checks_ok    = count( array_filter( $readiness['checks'], static fn( $check ) => 'ok' === $check['status'] ) );
		$epm_checks_total = count( $readiness['checks'] );
		?>
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
						esc_html_e( 'What Apple Podcasts, Spotify and other directories check in your feed.', 'elementor-podcast-manager' );
					}
					?>
				</p>
			</div>
			<?php if ( $epm_checks_total > 0 ) : ?>
				<span class="epm-readiness__count">
					<span aria-hidden="true"><strong><?php echo esc_html( number_format_i18n( $epm_checks_ok ) ); ?></strong><span>/<?php echo esc_html( number_format_i18n( $epm_checks_total ) ); ?></span></span>
					<span class="screen-reader-text">
						<?php
						/* translators: 1: passed checks, 2: all checks */
						echo esc_html( sprintf( __( '%1$s of %2$s checks complete', 'elementor-podcast-manager' ), number_format_i18n( $epm_checks_ok ), number_format_i18n( $epm_checks_total ) ) );
						?>
					</span>
				</span>
			<?php endif; ?>
		</div>
		<?php echo \EPM\Readiness::render_html( $readiness ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_html(). ?>
	</section>
</div>
