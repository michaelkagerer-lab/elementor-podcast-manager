<?php
/**
 * Distribution: where to submit the feed, and progress per platform.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The address shown here is what gets submitted: remember it, so a later
// change (the permalink setting) is reported.
\EPM\Feed::remember_address();
$epm_feed       = \EPM\Hosting::public_feed_url();
$epm_moved      = \EPM\Feed::address_change();
$epm_external   = \EPM\Hosting::is_external();
$epm_dirs       = \EPM\Directories::all();
$epm_progress   = \EPM\Directories::progress();
$epm_report     = \EPM\Readiness::report();
$epm_problems   = array_values(
	array_filter(
		$epm_report['checks'],
		static function ( $check ) {
			return 'error' === $check['status'];
		}
	)
);
$epm_essential  = array_filter(
	$epm_dirs,
	static function ( $dir ) {
		return 'essential' === $dir['priority'] && '' === $dir['via'];
	}
);
$epm_done_count = 0;
$epm_next       = ''; // The one platform to submit to next (primary button).
foreach ( array_keys( $epm_essential ) as $epm_id ) {
	if ( ! empty( $epm_progress[ $epm_id ]['status'] ) ) {
		++$epm_done_count;
	} elseif ( '' === $epm_next ) {
		$epm_next = (string) $epm_id;
	}
}

$epm_groups = [
	'essential'   => [
		'title' => __( 'Start here', 'elementor-podcast-manager' ),
		'lede'  => __( 'Together these reach almost every listener. Submit your feed once; new episodes follow automatically.', 'elementor-podcast-manager' ),
	],
	'recommended' => [
		'title' => __( 'Recommended', 'elementor-podcast-manager' ),
		'lede'  => __( 'Popular apps with their own directories.', 'elementor-podcast-manager' ),
	],
	'optional'    => [
		'title' => __( 'More platforms', 'elementor-podcast-manager' ),
		'lede'  => __( 'Regional and niche directories.', 'elementor-podcast-manager' ),
	],
	'automatic'   => [
		'title' => __( 'Listed automatically', 'elementor-podcast-manager' ),
		'lede'  => __( 'These apps pick up your show from Apple Podcasts or Podcast Index. Claim the listing if you want to manage it.', 'elementor-podcast-manager' ),
	],
];

$epm_grouped = [];
foreach ( $epm_dirs as $epm_id => $epm_dir ) {
	$epm_group                   = '' !== $epm_dir['via'] ? 'automatic' : $epm_dir['priority'];
	$epm_grouped[ $epm_group ][] = [ $epm_id, $epm_dir ];
}

$epm_status_label = [
	''          => __( 'Not submitted', 'elementor-podcast-manager' ),
	'submitted' => __( 'Submitted', 'elementor-podcast-manager' ),
	'listed'    => __( 'Listed', 'elementor-podcast-manager' ),
];

$epm_icon = static function ( array $dir ): string {
	$svg = '' !== $dir['icon'] ? \EPM\BrandIcons::svg( (string) $dir['icon'] ) : '';
	if ( '' !== $svg ) {
		return $svg;
	}
	$name = (string) $dir['name'];

	return '<span aria-hidden="true">' . esc_html( function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 ) ) . '</span>';
};
?>
<div class="wrap epm-app" data-epm-distribution>
	<header class="epm-app__header">
		<div>
			<h1 class="epm-app__title"><?php esc_html_e( 'Distribution', 'elementor-podcast-manager' ); ?></h1>
			<p class="epm-app__lede"><?php esc_html_e( 'Get your podcast into Apple Podcasts, Spotify, YouTube and every other app. Each platform needs your feed address once.', 'elementor-podcast-manager' ); ?></p>
		</div>
		<p class="epm-distribution__score">
			<strong class="epm-tabular" data-dist-score><?php echo esc_html( sprintf( /* translators: 1: platforms done, 2: essential platforms */ __( '%1$s of %2$s', 'elementor-podcast-manager' ), number_format_i18n( $epm_done_count ), number_format_i18n( count( $epm_essential ) ) ) ); ?></strong>
			<span><?php esc_html_e( 'essential platforms submitted', 'elementor-podcast-manager' ); ?></span>
		</p>
	</header>

	<p class="epm-sr-only" role="status" aria-live="polite" data-epm-announce></p>

	<section class="epm-card" aria-labelledby="epm-dist-feed-title">
		<h2 class="epm-card__title" id="epm-dist-feed-title"><?php esc_html_e( 'Your feed address', 'elementor-podcast-manager' ); ?></h2>
		<p class="epm-card__lede">
			<?php
			if ( $epm_external && '' !== \EPM\Hosting::source_feed_url() ) {
				printf(
					/* translators: %s: podcast host name */
					esc_html__( '%s publishes your feed. Submit this address, or use the distribution tools in your host’s dashboard.', 'elementor-podcast-manager' ),
					esc_html( \EPM\Hosting::provider_name() )
				);
			} else {
				esc_html_e( 'This website publishes your feed. Its address stays the same when you change themes; changing the permalink setting to “Plain” changes it, and this screen tells you if that happens.', 'elementor-podcast-manager' );
			}
			?>
		</p>
		<?php if ( null !== $epm_moved ) : ?>
			<div class="epm-callout epm-callout--error">
				<p><strong><?php esc_html_e( 'Your feed has a new address.', 'elementor-podcast-manager' ); ?></strong>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: feed address submitted to directories, 2: new feed address */
						__( 'Directories and apps load %1$s, which this site no longer uses for the feed. The feed is now at %2$s. Change the permalink setting back, or submit the new address to every directory you listed the show in.', 'elementor-podcast-manager' ),
						$epm_moved['shown'],
						$epm_moved['now']
					)
				);
				?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="epm_feed_address" />
					<?php wp_nonce_field( 'epm_feed_address' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'I submitted the new address', 'elementor-podcast-manager' ); ?></button>
				</form>
			</div>
		<?php endif; ?>
		<div class="epm-copy">
			<code class="epm-copy__value"><?php echo esc_html( $epm_feed ); ?></code>
			<button type="button" class="button button-primary" data-epm-copy="<?php echo esc_attr( $epm_feed ); ?>"><?php esc_html_e( 'Copy feed address', 'elementor-podcast-manager' ); ?></button>
			<a class="button" href="<?php echo esc_url( $epm_feed ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View feed', 'elementor-podcast-manager' ); ?></a>
		</div>
		<p class="epm-field__help">
			<?php
			printf(
				/* translators: 1: link to Podbase validator, 2: link to Cast Feed Validator */
				esc_html__( 'Check it with an independent validator: %1$s or %2$s.', 'elementor-podcast-manager' ),
				'<a href="' . esc_url( 'https://podba.se/validate/?url=' . rawurlencode( $epm_feed ) ) . '" target="_blank" rel="noopener">Podbase</a>',
				'<a href="' . esc_url( 'https://www.castfeedvalidator.com/?url=' . rawurlencode( $epm_feed ) ) . '" target="_blank" rel="noopener">Cast Feed Validator</a>'
			);
			?>
		</p>

		<div class="epm-stack--tight epm-dist-check" id="epm-dist-check">
			<div>
				<button type="button" class="button" data-action="server-check"><?php esc_html_e( 'Test feed and audio delivery', 'elementor-podcast-manager' ); ?></button>
				<span class="epm-field__help"><?php esc_html_e( 'Checks what directories check: the feed, HTTPS, and whether your server answers audio requests the way apps need.', 'elementor-podcast-manager' ); ?></span>
			</div>
			<ul class="epm-checklist" data-server-checks hidden></ul>
		</div>

		<?php if ( ! $epm_external && ! empty( $epm_problems ) ) : ?>
			<div class="epm-callout epm-callout--error">
				<p><strong><?php esc_html_e( 'Fix these before submitting: directories reject feeds with these problems.', 'elementor-podcast-manager' ); ?></strong></p>
				<ul class="epm-dist-problems">
					<?php foreach ( array_slice( $epm_problems, 0, 6 ) as $epm_check ) : ?>
						<li><strong><?php echo esc_html( $epm_check['label'] ); ?>:</strong> <?php echo esc_html( $epm_check['message'] ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=epm-settings' ) ); ?>"><?php esc_html_e( 'Open podcast settings', 'elementor-podcast-manager' ); ?></a></p>
			</div>
		<?php endif; ?>
	</section>

	<?php foreach ( $epm_groups as $epm_group => $epm_meta ) : ?>
		<?php if ( empty( $epm_grouped[ $epm_group ] ) ) { continue; } ?>
		<section class="epm-card" aria-labelledby="epm-dist-<?php echo esc_attr( $epm_group ); ?>">
			<h2 class="epm-card__title" id="epm-dist-<?php echo esc_attr( $epm_group ); ?>"><?php echo esc_html( $epm_meta['title'] ); ?></h2>
			<p class="epm-card__lede"><?php echo esc_html( $epm_meta['lede'] ); ?></p>

			<div>
				<?php foreach ( $epm_grouped[ $epm_group ] as [ $epm_id, $epm_dir ] ) : ?>
					<?php
					$epm_entry  = $epm_progress[ $epm_id ] ?? [];
					$epm_status = (string) ( $epm_entry['status'] ?? '' );
					$epm_url    = (string) ( $epm_entry['url'] ?? '' );
					$epm_badge  = 'listed' === $epm_status ? 'ok' : ( 'submitted' === $epm_status ? 'info' : '' );
					$epm_auto   = '' !== $epm_dir['via'];
					?>
					<article class="epm-platform" data-directory="<?php echo esc_attr( $epm_id ); ?>" aria-labelledby="epm-dir-<?php echo esc_attr( $epm_id ); ?>"<?php echo isset( $epm_essential[ $epm_id ] ) ? ' data-essential' : ''; ?>>
						<span class="epm-platform__icon"><?php echo $epm_icon( $epm_dir ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped SVG/markup. ?></span>
						<h3 class="epm-platform__name" id="epm-dir-<?php echo esc_attr( $epm_id ); ?>">
							<?php echo esc_html( (string) $epm_dir['name'] ); ?>
							<?php if ( ! $epm_auto || '' !== $epm_status ) : ?>
								<span class="epm-badge<?php echo '' !== $epm_badge ? ' epm-badge--' . esc_attr( $epm_badge ) : ''; ?>" data-status-badge data-status="<?php echo esc_attr( $epm_status ); ?>"><?php echo esc_html( $epm_status_label[ $epm_status ] ?? '' ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $epm_dir['region'] ) : ?>
								<span class="epm-badge"><?php echo esc_html( (string) $epm_dir['region'] ); ?></span>
							<?php endif; ?>
						</h3>
						<div class="epm-platform__actions">
							<?php if ( '' !== $epm_dir['submit_url'] ) : ?>
								<a class="button<?php echo $epm_id === $epm_next ? ' button-primary' : ''; ?>" href="<?php echo esc_url( (string) $epm_dir['submit_url'] ); ?>" target="_blank" rel="noopener" data-submit-link>
									<?php
									/* translators: %s: platform name */
									echo esc_html( sprintf( $epm_auto ? __( 'Open %s', 'elementor-podcast-manager' ) : __( 'Submit to %s', 'elementor-podcast-manager' ), (string) $epm_dir['name'] ) );
									?>
									<span class="epm-sr-only"><?php esc_html_e( '(opens in a new tab)', 'elementor-podcast-manager' ); ?></span>
								</a>
							<?php endif; ?>
						</div>
						<p class="epm-platform__text"><?php echo esc_html( (string) $epm_dir['steps'] ); ?></p>
						<details class="epm-details epm-platform__details">
							<summary>
								<?php
								echo esc_html(
									$epm_auto
										? __( 'Add the listing link', 'elementor-podcast-manager' )
										/* translators: %s: platform name */
										: sprintf( __( 'Track %s', 'elementor-podcast-manager' ), (string) $epm_dir['name'] )
								);
								?>
							</summary>
							<div class="epm-details__body epm-stack--tight">
								<p class="epm-field__help"><?php echo esc_html( (string) $epm_dir['needs'] ); ?></p>
								<form class="epm-dist-form" data-directory-form="<?php echo esc_attr( $epm_id ); ?>" novalidate>
									<?php if ( ! $epm_auto ) : ?>
										<label class="epm-check">
											<input type="checkbox" name="submitted" value="1" <?php checked( '' !== $epm_status ); ?> />
											<?php esc_html_e( 'I submitted the feed', 'elementor-podcast-manager' ); ?>
										</label>
									<?php endif; ?>
									<div class="epm-field">
										<label class="epm-field__label" for="epm-dir-url-<?php echo esc_attr( $epm_id ); ?>">
											<?php
											/* translators: %s: platform name */
											echo esc_html( sprintf( __( 'Your show on %s', 'elementor-podcast-manager' ), (string) $epm_dir['name'] ) );
											?>
											<span class="epm-field__optional"><?php esc_html_e( '(once listed)', 'elementor-podcast-manager' ); ?></span>
										</label>
										<div class="epm-inline-form">
											<input type="url" id="epm-dir-url-<?php echo esc_attr( $epm_id ); ?>" name="url" value="<?php echo esc_attr( $epm_url ); ?>" inputmode="url" spellcheck="false" placeholder="https://" aria-describedby="epm-dir-help-<?php echo esc_attr( $epm_id ); ?>" />
											<button type="submit" class="button"><?php esc_html_e( 'Save', 'elementor-podcast-manager' ); ?></button>
										</div>
										<p class="epm-field__help" id="epm-dir-help-<?php echo esc_attr( $epm_id ); ?>"><?php esc_html_e( 'The listing link is added to your subscribe buttons automatically.', 'elementor-podcast-manager' ); ?></p>
										<p class="epm-field__error" id="epm-dir-error-<?php echo esc_attr( $epm_id ); ?>" data-error hidden></p>
									</div>
								</form>
							</div>
						</details>
					</article>
				<?php endforeach; ?>
			</div>
		</section>
	<?php endforeach; ?>
</div>
