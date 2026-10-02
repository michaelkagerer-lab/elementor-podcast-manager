<?php
/**
 * Hosting & import screen.
 *
 * Where the show is hosted (this site or another host), the host sync,
 * a one-off import from any feed, and step-by-step guides for moving a
 * show to or away from this website.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$epm_hosting  = \EPM\Hosting::all();
$epm_state    = \EPM\Hosting::state();
$epm_external = 'external' === $epm_hosting['mode'];
$epm_provider = \EPM\Providers::get( (string) $epm_hosting['provider'] );
$epm_next     = wp_next_scheduled( \EPM\Hosting::CRON_HOOK );
$epm_job      = \EPM\ImportJob::client_state( \EPM\ImportJob::get() );
$epm_count    = epm()->episodes->count_published();

$epm_when = static function ( int $timestamp ): string {
	if ( $timestamp <= 0 ) {
		return __( 'never', 'elementor-podcast-manager' );
	}

	return $timestamp > time()
		/* translators: %s: human time difference, e.g. "20 mins" */
		? sprintf( __( 'in %s', 'elementor-podcast-manager' ), human_time_diff( $timestamp ) )
		/* translators: %s: human time difference, e.g. "5 mins" */
		: sprintf( __( '%s ago', 'elementor-podcast-manager' ), human_time_diff( $timestamp ) );
};
?>
<div class="wrap epm-app" data-epm-hosting>
	<header class="epm-app__header">
		<div>
			<h1 class="epm-app__title"><?php esc_html_e( 'Hosting & import', 'elementor-podcast-manager' ); ?></h1>
			<p class="epm-app__lede"><?php esc_html_e( 'Choose where your podcast’s audio and RSS feed live. Your website shows every episode either way.', 'elementor-podcast-manager' ); ?></p>
		</div>
	</header>

	<?php settings_errors( 'epm_hosting_group' ); ?>

	<p class="epm-sr-only" role="status" aria-live="polite" data-epm-announce></p>

	<div class="epm-hosting-layout">
		<div class="epm-stack">
			<!-- Hosting mode -->
			<section class="epm-card" aria-labelledby="epm-hosting-mode-title">
				<h2 class="epm-card__title" id="epm-hosting-mode-title"><?php esc_html_e( 'Where your podcast is hosted', 'elementor-podcast-manager' ); ?></h2>
				<p class="epm-card__lede"><?php esc_html_e( 'The host publishes the RSS feed that Apple Podcasts, Spotify and other apps read.', 'elementor-podcast-manager' ); ?></p>

				<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" class="epm-stack" data-hosting-form>
					<?php settings_fields( 'epm_hosting_group' ); ?>

					<fieldset class="epm-choices epm-choices--rows">
						<legend class="epm-sr-only"><?php esc_html_e( 'Hosting', 'elementor-podcast-manager' ); ?></legend>
						<label class="epm-choice">
							<input type="radio" name="epm_hosting[mode]" value="self" <?php checked( ! $epm_external ); ?> />
							<span class="epm-choice__title"><?php esc_html_e( 'This website', 'elementor-podcast-manager' ); ?></span>
							<span class="epm-choice__text"><?php esc_html_e( 'Episodes and audio live here, and this site publishes the feed. Audio can come from the Media Library or any audio URL (for example a storage bucket or CDN).', 'elementor-podcast-manager' ); ?></span>
						</label>
						<label class="epm-choice">
							<input type="radio" name="epm_hosting[mode]" value="external" <?php checked( $epm_external ); ?> />
							<span class="epm-choice__title"><?php esc_html_e( 'Another podcast host', 'elementor-podcast-manager' ); ?></span>
							<span class="epm-choice__text"><?php esc_html_e( 'Spotify for Creators, Buzzsprout, Libsyn or any other host publishes the feed. This site mirrors the episodes and keeps them in sync.', 'elementor-podcast-manager' ); ?></span>
						</label>
					</fieldset>

					<div class="epm-stack" data-external-only <?php echo $epm_external ? '' : 'hidden'; ?>>
						<div class="epm-field-row">
							<div class="epm-field">
								<label class="epm-field__label" for="epm-hosting-provider"><?php esc_html_e( 'Host', 'elementor-podcast-manager' ); ?></label>
								<select id="epm-hosting-provider" name="epm_hosting[provider]">
									<?php foreach ( \EPM\Providers::choices() as $epm_id => $epm_name ) : ?>
										<option value="<?php echo esc_attr( $epm_id ); ?>" <?php selected( (string) $epm_hosting['provider'], $epm_id ); ?>><?php echo esc_html( $epm_name ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="epm-field">
								<label class="epm-field__label" for="epm-hosting-feed"><?php esc_html_e( 'Host’s RSS feed address', 'elementor-podcast-manager' ); ?></label>
						<input type="url" id="epm-hosting-feed" name="epm_hosting[feed_url]" value="<?php echo esc_attr( (string) $epm_hosting['feed_url'] ); ?>" inputmode="url" spellcheck="false" placeholder="https://" aria-describedby="epm-hosting-feed-help" <?php echo $epm_external ? 'required' : ''; ?> />
							</div>
						</div>
						<p class="epm-field__help" id="epm-hosting-feed-help" data-provider-help><?php echo esc_html( null !== $epm_provider ? (string) $epm_provider['feed_help'] : (string) \EPM\Providers::get( 'other' )['feed_help'] ); ?></p>

						<fieldset class="epm-field">
							<legend class="epm-field__label"><?php esc_html_e( 'Keeping in sync', 'elementor-podcast-manager' ); ?></legend>
							<label class="epm-check">
								<input type="checkbox" name="epm_hosting[sync]" value="1" <?php checked( ! empty( $epm_hosting['sync'] ) ); ?> />
								<span>
									<?php esc_html_e( 'Check the host’s feed for new and changed episodes', 'elementor-podcast-manager' ); ?>
									<select name="epm_hosting[interval]" aria-label="<?php esc_attr_e( 'How often', 'elementor-podcast-manager' ); ?>">
										<option value="hourly" <?php selected( $epm_hosting['interval'], 'hourly' ); ?>><?php esc_html_e( 'every hour', 'elementor-podcast-manager' ); ?></option>
										<option value="twicedaily" <?php selected( $epm_hosting['interval'], 'twicedaily' ); ?>><?php esc_html_e( 'twice a day', 'elementor-podcast-manager' ); ?></option>
										<option value="daily" <?php selected( $epm_hosting['interval'], 'daily' ); ?>><?php esc_html_e( 'once a day', 'elementor-podcast-manager' ); ?></option>
									</select>
								</span>
							</label>
							<label class="epm-check">
								<input type="checkbox" name="epm_hosting[new_status]" value="draft" <?php checked( 'draft', $epm_hosting['new_status'] ); ?> />
								<?php esc_html_e( 'Add new episodes as drafts for review instead of publishing them', 'elementor-podcast-manager' ); ?>
							</label>
							<label class="epm-check">
								<input type="checkbox" name="epm_hosting[missing]" value="draft" <?php checked( 'draft', $epm_hosting['missing'] ); ?> />
								<?php esc_html_e( 'Unpublish episodes the host removed (after one day, only within the host’s feed window)', 'elementor-podcast-manager' ); ?>
							</label>
							<label class="epm-check">
								<input type="checkbox" name="epm_hosting[redirect]" value="1" <?php checked( ! empty( $epm_hosting['redirect'] ) ); ?> />
								<?php esc_html_e( 'Redirect this site’s feed address to the host’s feed (recommended: apps never see the show twice)', 'elementor-podcast-manager' ); ?>
							</label>
						</fieldset>
					</div>

					<fieldset class="epm-field" data-self-only <?php echo $epm_external ? 'hidden' : ''; ?>>
						<legend class="epm-field__label"><?php esc_html_e( 'Download statistics', 'elementor-podcast-manager' ); ?></legend>
						<p class="epm-field__help"><?php esc_html_e( 'A measurement service counts downloads by passing the audio links in your feed through its address. Listeners notice nothing, episode IDs stay the same.', 'elementor-podcast-manager' ); ?></p>
						<label class="epm-check">
							<input type="radio" name="epm_hosting[stats]" value="" <?php checked( '', (string) $epm_hosting['stats'] ); ?> />
							<?php esc_html_e( 'No statistics', 'elementor-podcast-manager' ); ?>
						</label>
						<label class="epm-check">
							<input type="radio" name="epm_hosting[stats]" value="op3" <?php checked( 'op3', (string) $epm_hosting['stats'] ); ?> />
							<?php esc_html_e( 'OP3 — free and open; statistics are public at op3.dev', 'elementor-podcast-manager' ); ?>
						</label>
						<label class="epm-check">
							<input type="radio" name="epm_hosting[stats]" value="podtrac" <?php checked( 'podtrac', (string) $epm_hosting['stats'] ); ?> />
							<?php esc_html_e( 'Podtrac — free account at podtrac.com', 'elementor-podcast-manager' ); ?>
						</label>
						<label class="epm-check">
							<input type="radio" name="epm_hosting[stats]" value="custom" <?php checked( 'custom', (string) $epm_hosting['stats'] ); ?> />
							<?php esc_html_e( 'Another service', 'elementor-podcast-manager' ); ?>
						</label>
						<div class="epm-field">
							<label class="epm-field__label" for="epm-hosting-stats-prefix"><?php esc_html_e( 'Prefix address', 'elementor-podcast-manager' ); ?> <span class="epm-field__optional"><?php esc_html_e( '(for another service)', 'elementor-podcast-manager' ); ?></span></label>
							<input type="url" id="epm-hosting-stats-prefix" name="epm_hosting[stats_prefix]" value="<?php echo esc_attr( (string) $epm_hosting['stats_prefix'] ); ?>" inputmode="url" spellcheck="false" placeholder="https://" />
						</div>
						<?php if ( 'op3' === $epm_hosting['stats'] ) : ?>
							<p class="epm-field__help">
								<a href="<?php echo esc_url( 'https://op3.dev/show/' . \EPM\Feed::podcast_guid() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open your OP3 statistics', 'elementor-podcast-manager' ); ?></a>
								<?php esc_html_e( '(numbers appear after the first downloads)', 'elementor-podcast-manager' ); ?>
							</p>
						<?php endif; ?>
					</fieldset>

					<div class="epm-card__footer">
						<?php submit_button( __( 'Save hosting settings', 'elementor-podcast-manager' ), 'primary', 'submit', false ); ?>
					</div>
				</form>
			</section>

			<!-- Sync status -->
			<?php if ( $epm_external ) : ?>
				<section class="epm-card" aria-labelledby="epm-hosting-sync-title" data-sync-card>
					<h2 class="epm-card__title" id="epm-hosting-sync-title"><?php esc_html_e( 'Sync with your host', 'elementor-podcast-manager' ); ?></h2>
					<dl class="epm-kv">
						<dt><?php esc_html_e( 'Status', 'elementor-podcast-manager' ); ?></dt>
						<dd data-sync-status>
							<?php if ( 'error' === $epm_state['status'] ) : ?>
								<span class="epm-badge epm-badge--error"><?php esc_html_e( 'Problem', 'elementor-podcast-manager' ); ?></span>
							<?php elseif ( (int) $epm_state['last_success'] > 0 ) : ?>
								<span class="epm-badge epm-badge--ok"><?php esc_html_e( 'Working', 'elementor-podcast-manager' ); ?></span>
							<?php else : ?>
								<span class="epm-badge"><?php esc_html_e( 'Not synced yet', 'elementor-podcast-manager' ); ?></span>
							<?php endif; ?>
							<span data-sync-message><?php echo esc_html( (string) $epm_state['message'] ); ?></span>
						</dd>
						<dt><?php esc_html_e( 'Last check', 'elementor-podcast-manager' ); ?></dt>
						<dd data-sync-last><?php echo esc_html( $epm_when( (int) $epm_state['last_run'] ) ); ?></dd>
						<dt><?php esc_html_e( 'Next check', 'elementor-podcast-manager' ); ?></dt>
						<dd><?php echo esc_html( $epm_next ? ( (int) $epm_next < time() ? __( 'Overdue', 'elementor-podcast-manager' ) : $epm_when( (int) $epm_next ) ) : __( 'Automatic sync is off', 'elementor-podcast-manager' ) ); ?></dd>
						<dt><?php esc_html_e( 'Episodes on this site', 'elementor-podcast-manager' ); ?></dt>
						<dd class="epm-tabular"><?php echo esc_html( number_format_i18n( $epm_count ) ); ?></dd>
					</dl>
					<div class="epm-card__footer">
						<button type="button" class="button" data-action="sync-now"><?php esc_html_e( 'Sync now', 'elementor-podcast-manager' ); ?></button>
						<?php if ( '' !== (string) $epm_hosting['feed_url'] && ! \EPM\Hosting::has_url_secret( (string) $epm_hosting['feed_url'] ) ) : ?>
							<a class="epm-button-link" href="<?php echo esc_url( (string) $epm_hosting['feed_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open the host’s feed', 'elementor-podcast-manager' ); ?></a>
						<?php endif; ?>
					</div>
				</section>
			<?php endif; ?>

			<!-- One-off import -->
			<section class="epm-card" aria-labelledby="epm-hosting-import-title" data-import-card>
				<h2 class="epm-card__title" id="epm-hosting-import-title"><?php esc_html_e( 'Import episodes from a feed', 'elementor-podcast-manager' ); ?></h2>
				<p class="epm-card__lede"><?php esc_html_e( 'Bring in episodes from any podcast feed you own. Episodes that already exist here are updated, never duplicated, and your edits on this site are kept.', 'elementor-podcast-manager' ); ?></p>

				<form class="epm-stack" data-import-form novalidate>
					<div class="epm-field">
						<label class="epm-field__label" for="epm-import-url"><?php esc_html_e( 'RSS feed, Apple Podcasts link or show page', 'elementor-podcast-manager' ); ?></label>
						<div class="epm-inline-form">
							<input type="url" id="epm-import-url" name="url" inputmode="url" spellcheck="false" placeholder="https://" value="<?php echo esc_attr( $epm_external ? (string) $epm_hosting['feed_url'] : '' ); ?>" aria-describedby="epm-import-error epm-import-progress" />
							<button type="submit" class="button" data-action="check"><?php esc_html_e( 'Check feed', 'elementor-podcast-manager' ); ?></button>
						</div>
						<p class="epm-field__help" id="epm-import-progress" data-preview-progress hidden></p>
						<div class="epm-field__error" id="epm-import-error" data-error hidden></div>
					</div>

					<div class="epm-stack" data-preview hidden>
						<div class="epm-preview">
							<img class="epm-preview__art" alt="" data-preview-art hidden />
							<div>
								<p class="epm-preview__title" data-preview-title></p>
								<p class="epm-preview__meta" data-preview-meta></p>
							</div>
						</div>
						<div class="epm-callout" data-preview-notes hidden><p></p></div>
						<div class="epm-callout epm-callout--warn" data-preview-incomplete hidden>
							<p><strong><?php esc_html_e( 'The feed could not be read completely.', 'elementor-podcast-manager' ); ?></strong> <span data-preview-incomplete-text></span></p>
					<div data-preview-incomplete-details></div>
							<p data-preview-incomplete-mirror><?php esc_html_e( 'You can import the episodes that were found and check the feed again later: episodes that are already here are updated, never duplicated.', 'elementor-podcast-manager' ); ?></p>
							<div class="epm-callout__actions" data-retry-wrap>
								<button type="button" class="button" data-action="retry-feed"><?php esc_html_e( 'Try reading the rest again', 'elementor-podcast-manager' ); ?></button>
							</div>
						</div>
						<fieldset class="epm-field">
							<legend class="epm-field__label"><?php esc_html_e( 'Options', 'elementor-podcast-manager' ); ?></legend>
							<label class="epm-check">
								<input type="checkbox" name="download_media" value="1" />
								<?php esc_html_e( 'Copy audio and episode images to this website (needed before closing the old host account)', 'elementor-podcast-manager' ); ?>
							</label>
							<label class="epm-check">
								<input type="checkbox" name="draft" value="1" />
								<?php esc_html_e( 'Import new episodes as drafts', 'elementor-podcast-manager' ); ?>
							</label>
							<label class="epm-check">
								<input type="checkbox" name="apply_channel" value="1" />
								<?php esc_html_e( 'Fill in empty podcast settings (title, description, artwork …) from this feed', 'elementor-podcast-manager' ); ?>
							</label>
							<label class="epm-check" data-confirm-owner hidden>
								<input type="checkbox" name="confirm_owner" value="1" />
								<?php esc_html_e( 'This feed is locked. I own this podcast and have the right to copy it.', 'elementor-podcast-manager' ); ?>
							</label>
							<label class="epm-check" data-accept-partial hidden>
								<input type="checkbox" name="accept_partial" value="1" aria-describedby="epm-import-partial-error" />
								<span data-accept-partial-label></span>
							</label>
							<p class="epm-field__error" id="epm-import-partial-error" data-error-for="accept_partial" hidden><?php esc_html_e( 'Copying the audio moves your podcast here. Confirm that the missing episodes may stay behind, or try reading the rest of the feed again first.', 'elementor-podcast-manager' ); ?></p>
						</fieldset>
						<div>
							<button type="button" class="button button-primary" data-action="start"><?php esc_html_e( 'Import episodes', 'elementor-podcast-manager' ); ?></button>
						</div>
					</div>
				</form>

				<div class="epm-stack" data-job <?php echo in_array( $epm_job['status'], [ 'running', 'waiting', 'done_with_problems', 'cancelled', 'failed' ], true ) ? '' : 'hidden'; ?>>
					<div class="epm-progress">
						<div class="epm-progress__track" role="progressbar" aria-labelledby="epm-hosting-import-title" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
							<div class="epm-progress__bar"></div>
						</div>
						<div class="epm-progress__label">
							<span data-job-count></span>
							<span data-job-summary></span>
						</div>
					</div>
					<div class="epm-callout epm-callout--error" data-job-error role="alert" hidden><p></p></div>
					<button type="button" class="button" data-action="retry-progress" hidden><?php esc_html_e( 'Retry progress check', 'elementor-podcast-manager' ); ?></button>
					<div class="epm-callout epm-callout--warn" data-job-stopped hidden><p></p></div>
					<div class="epm-callout epm-callout--warn" data-job-incomplete hidden><p></p></div>
					<?php require EPM_PATH . 'admin/views/partials/import-result.php'; ?>
					<details class="epm-details">
						<summary><?php esc_html_e( 'Show the import log', 'elementor-podcast-manager' ); ?></summary>
						<div class="epm-details__body"><ul class="epm-log" data-job-log></ul></div>
					</details>
					<div>
						<button type="button" class="epm-button-link epm-button-link--danger" data-action="cancel" hidden><?php esc_html_e( 'Stop the import', 'elementor-podcast-manager' ); ?></button>
						<a class="button" data-job-episodes href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . \EPM\EpisodePostType::CPT ) ); ?>" hidden><?php esc_html_e( 'View episodes', 'elementor-podcast-manager' ); ?></a>
					</div>
				</div>
			</section>
		</div>

		<aside class="epm-stack" aria-label="<?php esc_attr_e( 'Moving your podcast', 'elementor-podcast-manager' ); ?>">
			<section class="epm-card" aria-labelledby="epm-move-in-title">
				<h2 class="epm-card__title" id="epm-move-in-title"><?php esc_html_e( 'Moving your podcast to this website', 'elementor-podcast-manager' ); ?></h2>
				<ol class="epm-checklist">
					<li class="epm-checklist__item">
						<span class="epm-checklist__icon" aria-hidden="true">1</span>
						<span class="epm-checklist__label"><?php esc_html_e( 'Prepare the old host', 'elementor-podcast-manager' ); ?></span>
						<p class="epm-checklist__text"><?php esc_html_e( 'Make every episode visible in its feed (raise any episode limit), turn off “Lock feed” and download your statistics: they do not move.', 'elementor-podcast-manager' ); ?></p>
					</li>
					<li class="epm-checklist__item">
						<span class="epm-checklist__icon" aria-hidden="true">2</span>
						<span class="epm-checklist__label"><?php esc_html_e( 'Import with media', 'elementor-podcast-manager' ); ?></span>
						<p class="epm-checklist__text"><?php esc_html_e( 'Use the setup assistant (“Move my podcast”) or the import on this page with “Copy audio” on. Episode IDs (GUIDs) are kept, so apps never show duplicates.', 'elementor-podcast-manager' ); ?></p>
					</li>
					<li class="epm-checklist__item">
						<span class="epm-checklist__icon" aria-hidden="true">3</span>
						<span class="epm-checklist__label"><?php esc_html_e( 'Redirect the old feed here', 'elementor-podcast-manager' ); ?></span>
						<div class="epm-checklist__text epm-stack--tight">
							<div class="epm-copy">
								<code class="epm-copy__value"><?php echo esc_html( \EPM\Feed::url() ); ?></code>
								<button type="button" class="button" data-epm-copy="<?php echo esc_attr( \EPM\Feed::url() ); ?>"><?php esc_html_e( 'Copy feed address', 'elementor-podcast-manager' ); ?></button>
							</div>
							<label class="epm-field__label" for="epm-move-host"><?php esc_html_e( 'Instructions for', 'elementor-podcast-manager' ); ?></label>
							<select id="epm-move-host" data-redirect-select>
								<?php foreach ( \EPM\Providers::choices() as $epm_id => $epm_name ) : ?>
									<option value="<?php echo esc_attr( $epm_id ); ?>" <?php selected( (string) $epm_hosting['provider'], $epm_id ); ?>><?php echo esc_html( $epm_name ); ?></option>
								<?php endforeach; ?>
							</select>
							<p data-redirect-help><?php echo esc_html( null !== $epm_provider ? (string) $epm_provider['redirect_help'] : (string) \EPM\Providers::get( 'spotify' )['redirect_help'] ); ?></p>
						</div>
					</li>
					<li class="epm-checklist__item">
						<span class="epm-checklist__icon" aria-hidden="true">4</span>
						<span class="epm-checklist__label"><?php esc_html_e( 'Wait four weeks before closing the old account', 'elementor-podcast-manager' ); ?></span>
						<p class="epm-checklist__text"><?php esc_html_e( 'Apple asks for at least four weeks. Where no redirect is possible, change the feed address in Apple Podcasts Connect and Spotify for Creators yourself.', 'elementor-podcast-manager' ); ?></p>
					</li>
				</ol>
			</section>

			<section class="epm-card" aria-labelledby="epm-move-out-title">
				<h2 class="epm-card__title" id="epm-move-out-title"><?php esc_html_e( 'Moving your podcast to another host', 'elementor-podcast-manager' ); ?></h2>
				<ol class="epm-checklist">
					<li class="epm-checklist__item">
						<span class="epm-checklist__icon" aria-hidden="true">1</span>
						<span class="epm-checklist__label"><?php esc_html_e( 'Let the new host import this feed', 'elementor-podcast-manager' ); ?></span>
						<p class="epm-checklist__text"><?php esc_html_e( 'Give it this site’s feed address. Set “Feed episode limit” to 0 in Podcast settings first so every episode is included, and unlock the feed.', 'elementor-podcast-manager' ); ?></p>
					</li>
					<li class="epm-checklist__item">
						<span class="epm-checklist__icon" aria-hidden="true">2</span>
						<span class="epm-checklist__label"><?php esc_html_e( 'Check the new feed', 'elementor-podcast-manager' ); ?></span>
						<p class="epm-checklist__text"><?php esc_html_e( 'Paste the new host’s feed into “Import episodes from a feed” and choose “Check feed”: it should report that every episode already exists here. That proves the episode IDs were kept.', 'elementor-podcast-manager' ); ?></p>
					</li>
					<li class="epm-checklist__item">
						<span class="epm-checklist__icon" aria-hidden="true">3</span>
						<span class="epm-checklist__label"><?php esc_html_e( 'Switch to “Another podcast host”', 'elementor-podcast-manager' ); ?></span>
						<p class="epm-checklist__text"><?php esc_html_e( 'Enter the new feed address and keep the redirect on. This site then answers its old feed address with a permanent (301) redirect, and apps move over by themselves. Keep it that way; the audio files already here stay online.', 'elementor-podcast-manager' ); ?></p>
					</li>
				</ol>
			</section>
		</aside>
	</div>
</div>
