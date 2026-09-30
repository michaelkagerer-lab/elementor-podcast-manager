<?php
/**
 * Podcast dashboard view.
 *
 * @package EPM
 *
 * @var EPM\PodcastSettings $settings
 * @var \WP_Post|null       $latest
 * @var array|null          $latest_data
 * @var int                 $count
 * @var string              $artwork
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap epm-dashboard">
	<h1><?php esc_html_e( 'Podcast Dashboard', 'elementor-podcast-manager' ); ?></h1>

	<div class="epm-dashboard__grid">
		<div class="epm-card epm-dashboard__podcast">
			<?php if ( $artwork ) : ?>
				<img src="<?php echo esc_url( $artwork ); ?>" alt="" class="epm-dashboard__artwork" />
			<?php endif; ?>
			<h2><?php echo esc_html( (string) $settings->get( 'title' ) ?: __( 'Untitled Podcast', 'elementor-podcast-manager' ) ); ?></h2>
			<p class="epm-dashboard__status">
				<?php if ( $settings->is_configured() ) : ?>
					<span class="epm-status epm-status--ready"><?php esc_html_e( 'Configured', 'elementor-podcast-manager' ); ?></span>
				<?php else : ?>
					<span class="epm-status epm-status--missing"><?php esc_html_e( 'Not configured yet', 'elementor-podcast-manager' ); ?></span>
				<?php endif; ?>
			</p>
			<?php if ( $artwork ) : ?>
				<p class="epm-dashboard__artwork-note"><?php esc_html_e( 'Podcast artwork', 'elementor-podcast-manager' ); ?></p>
			<?php endif; ?>
			<p>
				<?php
				/* translators: %d: number of published episodes */
				printf( esc_html( _n( '%d published episode', '%d published episodes', $count, 'elementor-podcast-manager' ) ), (int) $count );
				?>
			</p>
		</div>

		<div class="epm-card">
			<h2><?php esc_html_e( 'Latest Episode', 'elementor-podcast-manager' ); ?></h2>
			<?php if ( $latest_data ) : ?>
				<p><strong><?php echo esc_html( (string) $latest_data['title'] ); ?></strong></p>
				<p class="epm-dashboard__meta">
					<?php echo esc_html( (string) $latest_data['date'] ); ?>
					<?php if ( '' !== (string) $latest_data['duration'] ) : ?>
						· <?php echo esc_html( (string) $latest_data['duration'] ); ?>
					<?php endif; ?>
				</p>
				<p>
					<a href="<?php echo esc_url( get_edit_post_link( $latest ) ); ?>" class="button">
						<?php esc_html_e( 'Edit Episode', 'elementor-podcast-manager' ); ?>
					</a>
				</p>
			<?php else : ?>
				<div class="epm-empty-state">
					<span class="epm-empty-state__icon dashicons dashicons-microphone" aria-hidden="true"></span>
					<p><?php esc_html_e( 'Your first episode is a few steps away.', 'elementor-podcast-manager' ); ?></p>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . \EPM\EpisodePostType::CPT ) ); ?>" class="button button-primary"><?php esc_html_e( 'Create your first episode', 'elementor-podcast-manager' ); ?></a>
				</div>
			<?php endif; ?>
		</div>

		<div class="epm-card">
			<h2><?php esc_html_e( 'Distribution', 'elementor-podcast-manager' ); ?></h2>
			<p><?php esc_html_e( 'Use this URL when submitting your podcast to podcast platforms.', 'elementor-podcast-manager' ); ?></p>
			<p class="epm-feed-row">
				<code><?php echo esc_html( \EPM\Feed::url() ); ?></code>
			</p>
			<p>
				<button type="button" class="button" data-epm-copy="<?php echo esc_attr( \EPM\Feed::url() ); ?>">
					<?php esc_html_e( 'Copy', 'elementor-podcast-manager' ); ?>
				</button>
				<a href="<?php echo esc_url( \EPM\Feed::url() ); ?>" target="_blank" rel="noopener" class="button">
					<?php esc_html_e( 'Open Feed', 'elementor-podcast-manager' ); ?>
				</a>
			</p>
		</div>

		<div class="epm-card epm-dashboard__readiness">
			<div class="epm-readiness__heading">
				<div>
					<h2><?php esc_html_e( 'Distribution readiness', 'elementor-podcast-manager' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Track the details podcast directories need before you submit your show.', 'elementor-podcast-manager' ); ?></p>
				</div>
				<?php
				$epm_checks_ok    = count( array_filter( $readiness['checks'], static fn( $check ) => 'ok' === $check['status'] ) );
				$epm_checks_total = count( $readiness['checks'] );
				?>
				<span class="epm-readiness__count">
					<span aria-hidden="true"><strong><?php echo esc_html( number_format_i18n( $epm_checks_ok ) ); ?></strong><span>/<?php echo esc_html( number_format_i18n( $epm_checks_total ) ); ?></span></span>
					<span class="screen-reader-text">
						<?php
						/* translators: 1: passed checks, 2: all checks */
						echo esc_html( sprintf( __( '%1$s of %2$s checks complete', 'elementor-podcast-manager' ), number_format_i18n( $epm_checks_ok ), number_format_i18n( $epm_checks_total ) ) );
						?>
					</span>
				</span>
			</div>
			<?php echo \EPM\Readiness::render_html( $readiness ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<div class="epm-card">
			<h2><?php esc_html_e( 'Quick actions', 'elementor-podcast-manager' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Keep your show moving with the tools you use most.', 'elementor-podcast-manager' ); ?></p>
			<div class="epm-dashboard__actions">
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . \EPM\EpisodePostType::CPT ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Add Episode', 'elementor-podcast-manager' ); ?>
				</a>
			<?php if ( \EPM\Capabilities::can_manage_podcast() ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=epm-settings' ) ); ?>" class="button">
						<?php esc_html_e( 'Podcast Settings', 'elementor-podcast-manager' ); ?>
					</a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=epm-design' ) ); ?>" class="button">
						<?php esc_html_e( 'Design', 'elementor-podcast-manager' ); ?>
					</a>
			<?php endif; ?>
			</div>
		</div>
	</div>
</div>
