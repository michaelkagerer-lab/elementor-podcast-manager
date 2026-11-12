<?php
/** Editorial shortcuts, using existing WordPress edit and search routes. @package EPM */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<section class="epm-card epm-workbench" aria-labelledby="epm-workbench-title">
	<div class="epm-workbench__heading">
		<h2 class="epm-card__title" id="epm-workbench-title"><?php esc_html_e( 'Your episodes', 'elementor-podcast-manager' ); ?></h2>
		<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="epm-workbench__search" role="search">
			<input type="hidden" name="post_type" value="podcast_episode" />
			<label class="screen-reader-text" for="epm-episode-search"><?php esc_html_e( 'Search episodes', 'elementor-podcast-manager' ); ?></label>
			<input type="search" id="epm-episode-search" name="s" placeholder="<?php esc_attr_e( 'Search episodes', 'elementor-podcast-manager' ); ?>" />
			<button class="button" type="submit"><?php esc_html_e( 'Search', 'elementor-podcast-manager' ); ?></button>
		</form>
	</div>
	<?php foreach ( [ 'draft' => $work_drafts, 'future' => $work_scheduled ] as $epm_queue_status => $epm_queue ) : ?>
		<?php if ( empty( $epm_queue ) ) { continue; } ?>
		<div class="epm-workbench__group">
			<div class="epm-workbench__heading">
				<h3><?php echo esc_html( 'draft' === $epm_queue_status ? __( 'Recently edited drafts', 'elementor-podcast-manager' ) : __( 'Coming up next', 'elementor-podcast-manager' ) ); ?></h3>
				<a href="<?php echo esc_url( add_query_arg( [ 'post_type' => 'podcast_episode', 'post_status' => $epm_queue_status ], admin_url( 'edit.php' ) ) ); ?>"><?php echo esc_html( 'draft' === $epm_queue_status ? __( 'All drafts', 'elementor-podcast-manager' ) : __( 'All scheduled episodes', 'elementor-podcast-manager' ) ); ?></a>
			</div>
			<ul class="epm-workbench__list">
			<?php foreach ( $epm_queue as $epm_work ) : ?>
				<li>
					<div class="epm-workbench__identity">
						<span class="dashicons <?php echo 'future' === $epm_queue_status ? 'dashicons-clock' : 'dashicons-edit'; ?>" aria-hidden="true"></span>
						<div><a class="epm-workbench__title" href="<?php echo esc_url( get_edit_post_link( $epm_work->ID ) ); ?>"><?php echo esc_html( get_the_title( $epm_work ) ?: __( 'Untitled episode', 'elementor-podcast-manager' ) ); ?></a>
						<p class="epm-muted"><?php
							if ( 'future' === $epm_queue_status ) {
								/* translators: 1: scheduled date and time, 2: site timezone. */
								echo esc_html( sprintf( __( 'Scheduled for %1$s (%2$s)', 'elementor-podcast-manager' ), get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $epm_work ), wp_timezone_string() ) );
							} else {
								/* translators: %s: last edited date and time. */
								echo esc_html( sprintf( __( 'Edited %s', 'elementor-podcast-manager' ), get_the_modified_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $epm_work ) ) );
							}
						?></p></div>
					</div>
					<a class="button" href="<?php echo esc_url( get_edit_post_link( $epm_work->ID ) ); ?>"><?php esc_html_e( 'Edit episode', 'elementor-podcast-manager' ); ?><span class="screen-reader-text">: <?php echo esc_html( get_the_title( $epm_work ) ?: __( 'Untitled episode', 'elementor-podcast-manager' ) ); ?></span></a>
				</li>
			<?php endforeach; ?>
			</ul>
		</div>
	<?php endforeach; ?>
	<?php if ( empty( $work_drafts ) && empty( $work_scheduled ) ) : ?>
		<p class="epm-workbench__empty"><?php esc_html_e( 'No drafts or scheduled episodes to edit. Start your next episode when you are ready.', 'elementor-podcast-manager' ); ?></p>
	<?php endif; ?>
</section>
