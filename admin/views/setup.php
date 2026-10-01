<?php
/**
 * Setup assistant.
 *
 * One screen, one step visible at a time (admin/js/epm-setup.js). Every
 * step saves through AJAX (AdminPages::save_step), so leaving halfway
 * keeps what was entered.
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$epm_settings  = epm()->settings->all();
$epm_hosting   = \EPM\Hosting::all();
$epm_state     = \EPM\AdminPages::setup_state();
$epm_path      = '' !== $epm_state['path'] ? $epm_state['path'] : ( \EPM\Hosting::is_external() ? 'external' : '' );
$epm_artwork   = \EPM\AdminPages::artwork_check( (int) $epm_settings['artwork_id'] );
$epm_presets   = epm()->presets->all();
$epm_preset    = (string) epm()->design->get( 'preset' );
$epm_category  = \EPM\Categories::encode( (string) $epm_settings['category'], (string) $epm_settings['subcategory'] );
$epm_featured  = [ 'spotify', 'buzzsprout', 'libsyn', 'podbean', 'transistor', 'captivate', 'rss-com', 'acast', 'podigee', 'simplecast', 'megaphone' ];
$epm_providers = \EPM\Providers::choices();
$epm_provider  = (string) $epm_hosting['provider'];

$epm_icon = static function ( string $provider ): string {
	$map = [
		'spotify'    => 'spotify',
		'soundcloud' => 'soundcloud',
		'substack'   => 'substack',
	];
	if ( isset( $map[ $provider ] ) ) {
		return \EPM\BrandIcons::svg( $map[ $provider ] );
	}
	$name = (string) ( \EPM\Providers::get( $provider )['name'] ?? '?' );

	return '<span aria-hidden="true">' . esc_html( function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 ) ) . '</span>';
};

$epm_steps = [
	'path'    => __( 'Hosting', 'elementor-podcast-manager' ),
	'connect' => __( 'Your host', 'elementor-podcast-manager' ),
	'import'  => __( 'Episodes', 'elementor-podcast-manager' ),
	'show'    => __( 'Show details', 'elementor-podcast-manager' ),
	'look'    => __( 'Look & page', 'elementor-podcast-manager' ),
	'done'    => __( 'Get listed', 'elementor-podcast-manager' ),
];
?>
<div class="wrap epm-app epm-app--narrow" data-epm-setup data-path="<?php echo esc_attr( $epm_path ); ?>">
	<header class="epm-app__header">
		<div>
			<p class="epm-app__eyebrow"><?php esc_html_e( 'Podcast', 'elementor-podcast-manager' ); ?></p>
			<h1 class="epm-app__title"><?php esc_html_e( 'Set up your podcast', 'elementor-podcast-manager' ); ?></h1>
			<p class="epm-app__lede"><?php esc_html_e( 'A few steps to a show that is ready for Apple Podcasts, Spotify and every other app. Each step is saved as you go.', 'elementor-podcast-manager' ); ?></p>
		</div>
		<a class="epm-button-link epm-button-link--muted" href="<?php echo esc_url( admin_url( 'admin.php?page=epm-dashboard' ) ); ?>"><?php esc_html_e( 'Exit setup', 'elementor-podcast-manager' ); ?></a>
	</header>

	<noscript>
		<div class="epm-callout epm-callout--warn"><p><?php esc_html_e( 'The setup assistant needs JavaScript. You can still configure everything under Podcast → Podcast settings and Podcast → Hosting & import.', 'elementor-podcast-manager' ); ?></p></div>
	</noscript>

	<nav aria-label="<?php esc_attr_e( 'Setup progress', 'elementor-podcast-manager' ); ?>">
		<ol class="epm-steps" data-epm-steps>
			<?php foreach ( $epm_steps as $epm_key => $epm_label ) : ?>
				<li class="epm-steps__item" data-step="<?php echo esc_attr( $epm_key ); ?>"><?php echo esc_html( $epm_label ); ?></li>
			<?php endforeach; ?>
		</ol>
	</nav>

	<p class="epm-sr-only" role="status" aria-live="polite" data-epm-announce></p>

	<!-- Step 1: where the show is hosted. -->
	<section class="epm-panel epm-card" data-panel="path" tabindex="-1" aria-labelledby="epm-setup-path-title">
		<form data-step-form="path" novalidate>
			<h2 class="epm-panel__title" id="epm-setup-path-title"><?php esc_html_e( 'Where should your podcast live?', 'elementor-podcast-manager' ); ?></h2>
			<p class="epm-panel__lede"><?php esc_html_e( 'The host is where the audio files and the RSS feed live. Podcast apps read the feed; your website shows the episodes either way.', 'elementor-podcast-manager' ); ?></p>

			<fieldset class="epm-choices epm-choices--rows" aria-describedby="epm-setup-path-error">
				<legend class="epm-sr-only"><?php esc_html_e( 'Hosting', 'elementor-podcast-manager' ); ?></legend>
				<label class="epm-choice">
					<input type="radio" name="path" value="new" <?php checked( $epm_path, 'new' ); ?> required />
					<span class="epm-choice__title"><?php esc_html_e( 'Host it on this website', 'elementor-podcast-manager' ); ?></span>
					<span class="epm-choice__text"><?php esc_html_e( 'Upload episodes here. This site publishes the RSS feed you submit to Apple Podcasts, Spotify and the others. No hosting fees; your server delivers the audio.', 'elementor-podcast-manager' ); ?></span>
				</label>
				<label class="epm-choice">
					<input type="radio" name="path" value="move" <?php checked( $epm_path, 'move' ); ?> />
					<span class="epm-choice__title"><?php esc_html_e( 'Move my podcast to this website', 'elementor-podcast-manager' ); ?></span>
					<span class="epm-choice__text"><?php esc_html_e( 'Import every episode from Spotify for Creators, Buzzsprout, Libsyn or any other host, then redirect the old feed. Subscribers and directory listings follow automatically.', 'elementor-podcast-manager' ); ?></span>
				</label>
				<label class="epm-choice">
					<input type="radio" name="path" value="external" <?php checked( $epm_path, 'external' ); ?> />
					<span class="epm-choice__title"><?php esc_html_e( 'Keep my current host', 'elementor-podcast-manager' ); ?></span>
					<span class="epm-choice__text"><?php esc_html_e( 'Your host keeps publishing the feed. This website shows every episode with its own pages and player, and picks up new episodes automatically.', 'elementor-podcast-manager' ); ?></span>
				</label>
			</fieldset>
			<p class="epm-field__error" id="epm-setup-path-error" data-error hidden></p>

			<div class="epm-card__footer">
				<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Continue', 'elementor-podcast-manager' ); ?></button>
			</div>
		</form>
	</section>

	<!-- Step 2: the current host's feed. -->
	<section class="epm-panel epm-card" data-panel="connect" tabindex="-1" aria-labelledby="epm-setup-connect-title" hidden>
		<form data-step-form="connect" novalidate>
			<h2 class="epm-panel__title" id="epm-setup-connect-title">
				<span data-path-only="move"><?php esc_html_e( 'Which host are you moving from?', 'elementor-podcast-manager' ); ?></span>
				<span data-path-only="external"><?php esc_html_e( 'Which host publishes your podcast?', 'elementor-podcast-manager' ); ?></span>
			</h2>
			<p class="epm-panel__lede"><?php esc_html_e( 'Choose your host, then paste its RSS feed address. An Apple Podcasts link or your show’s web page works too.', 'elementor-podcast-manager' ); ?></p>

			<fieldset class="epm-choices">
				<legend class="epm-sr-only"><?php esc_html_e( 'Podcast host', 'elementor-podcast-manager' ); ?></legend>
				<?php foreach ( $epm_featured as $epm_id ) : ?>
					<?php $epm_p = \EPM\Providers::get( $epm_id ); ?>
					<?php if ( null === $epm_p ) { continue; } ?>
					<label class="epm-choice epm-choice--tile">
						<input type="radio" name="provider" value="<?php echo esc_attr( $epm_id ); ?>" <?php checked( $epm_provider, $epm_id ); ?> />
						<span class="epm-choice__icon"><?php echo $epm_icon( $epm_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped SVG/markup. ?></span>
						<span class="epm-choice__title"><?php echo esc_html( (string) $epm_p['name'] ); ?></span>
					</label>
				<?php endforeach; ?>
				<label class="epm-choice epm-choice--tile">
					<input type="radio" name="provider" value="more" <?php checked( '' !== $epm_provider && ! in_array( $epm_provider, $epm_featured, true ) ); ?> />
					<span class="epm-choice__icon" aria-hidden="true">…</span>
					<span class="epm-choice__title"><?php esc_html_e( 'Another host', 'elementor-podcast-manager' ); ?></span>
				</label>
			</fieldset>

			<div class="epm-field" data-more-hosts hidden>
				<label class="epm-field__label" for="epm-setup-provider-more"><?php esc_html_e( 'Host', 'elementor-podcast-manager' ); ?></label>
				<select id="epm-setup-provider-more" name="provider_more">
					<?php foreach ( $epm_providers as $epm_id => $epm_name ) : ?>
						<?php if ( in_array( $epm_id, $epm_featured, true ) ) { continue; } ?>
						<option value="<?php echo esc_attr( $epm_id ); ?>" <?php selected( $epm_provider, $epm_id ); ?>><?php echo esc_html( $epm_name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="epm-field">
				<label class="epm-field__label" for="epm-setup-feed"><?php esc_html_e( 'RSS feed address', 'elementor-podcast-manager' ); ?></label>
				<div class="epm-inline-form">
					<input type="url" id="epm-setup-feed" name="feed_url" value="<?php echo esc_attr( (string) $epm_hosting['feed_url'] ); ?>" inputmode="url" autocomplete="url" spellcheck="false" placeholder="https://" aria-describedby="epm-setup-feed-help epm-setup-feed-progress epm-setup-feed-error" />
					<button type="button" class="button" data-action="check-feed"><?php esc_html_e( 'Check feed', 'elementor-podcast-manager' ); ?></button>
				</div>
				<p class="epm-field__help" id="epm-setup-feed-help" data-feed-help></p>
				<p class="epm-field__help" id="epm-setup-feed-progress" data-preview-progress hidden></p>
				<p class="epm-field__error" id="epm-setup-feed-error" data-error hidden></p>
			</div>

			<div class="epm-stack" data-preview hidden>
				<div class="epm-preview">
					<img class="epm-preview__art" alt="" data-preview-art hidden />
					<div>
						<p class="epm-preview__title" data-preview-title></p>
						<p class="epm-preview__meta" data-preview-meta></p>
						<ul class="epm-facts">
							<li><strong data-preview-episodes></strong><span><?php esc_html_e( 'episodes', 'elementor-podcast-manager' ); ?></span></li>
							<li><strong data-preview-newest></strong><span><?php esc_html_e( 'latest episode', 'elementor-podcast-manager' ); ?></span></li>
							<li><strong data-preview-oldest></strong><span><?php esc_html_e( 'first episode', 'elementor-podcast-manager' ); ?></span></li>
						</ul>
					</div>
				</div>

				<div class="epm-callout epm-callout--warn" data-preview-locked hidden>
					<p><strong><?php esc_html_e( 'This feed is locked.', 'elementor-podcast-manager' ); ?></strong> <?php esc_html_e( 'Hosts lock feeds so nobody else can move a show. If this is your podcast, confirm it below, or turn off “Lock feed” at your host.', 'elementor-podcast-manager' ); ?></p>
				</div>
				<div class="epm-callout" data-preview-existing hidden><p></p></div>
				<div class="epm-callout epm-callout--warn" data-preview-moved hidden><p></p></div>
				<div class="epm-callout" data-preview-notes hidden><p></p></div>
				<div class="epm-callout epm-callout--warn" data-preview-incomplete hidden>
					<p><strong><?php esc_html_e( 'The feed could not be read completely.', 'elementor-podcast-manager' ); ?></strong> <span data-preview-incomplete-text></span></p>
					<p data-path-only="external"><?php esc_html_e( 'You can connect the show with the episodes that were found; the regular sync and a later import add the rest. Episodes that are already here are updated, never duplicated.', 'elementor-podcast-manager' ); ?></p>
					<div class="epm-callout__actions" data-retry-wrap>
						<button type="button" class="button" data-action="retry-feed"><?php esc_html_e( 'Try reading the rest again', 'elementor-podcast-manager' ); ?></button>
					</div>
				</div>

				<fieldset class="epm-stack--tight" data-path-only="move">
					<legend class="epm-field__label"><?php esc_html_e( 'Moving options', 'elementor-podcast-manager' ); ?></legend>
					<label class="epm-choice">
						<input type="checkbox" name="download_media" value="1" checked />
						<span class="epm-choice__title"><?php esc_html_e( 'Copy audio and episode images to this website', 'elementor-podcast-manager' ); ?></span>
						<span class="epm-choice__text"><?php esc_html_e( 'Recommended when you close the old account. Large shows take a while; the copy continues in the background. Without it, the audio keeps playing from your old host.', 'elementor-podcast-manager' ); ?></span>
					</label>
					<label class="epm-choice" data-confirm-owner hidden>
						<input type="checkbox" name="confirm_owner" value="1" aria-describedby="epm-setup-confirm-error" />
						<span class="epm-choice__title"><?php esc_html_e( 'I own this podcast and have the right to move it', 'elementor-podcast-manager' ); ?></span>
						<span class="epm-choice__text"><?php esc_html_e( 'Required for locked feeds.', 'elementor-podcast-manager' ); ?></span>
					</label>
					<p class="epm-field__error" id="epm-setup-confirm-error" data-error-for="confirm_owner" hidden><?php esc_html_e( 'Confirm that you own this podcast to move it.', 'elementor-podcast-manager' ); ?></p>
					<label class="epm-choice" data-accept-partial hidden>
						<input type="checkbox" name="accept_partial" value="1" aria-describedby="epm-setup-partial-error" />
						<span class="epm-choice__title" data-accept-partial-label></span>
						<span class="epm-choice__text"><?php esc_html_e( 'Only if those episodes are gone for good. Otherwise try reading the rest of the feed again, or fix the feed at your old host first.', 'elementor-podcast-manager' ); ?></span>
					</label>
					<p class="epm-field__error" id="epm-setup-partial-error" data-error-for="accept_partial" hidden><?php esc_html_e( 'Confirm that the missing episodes may stay behind, or try reading the rest of the feed again first.', 'elementor-podcast-manager' ); ?></p>
				</fieldset>

				<fieldset class="epm-stack--tight" data-path-only="external">
					<legend class="epm-field__label"><?php esc_html_e( 'New episodes from your host', 'elementor-podcast-manager' ); ?></legend>
					<label class="epm-choice">
						<input type="radio" name="new_status" value="publish" <?php checked( 'draft' !== $epm_hosting['new_status'] ); ?> />
						<span class="epm-choice__title"><?php esc_html_e( 'Publish them on this website automatically', 'elementor-podcast-manager' ); ?></span>
						<span class="epm-choice__text"><?php esc_html_e( 'The site checks your host’s feed every hour.', 'elementor-podcast-manager' ); ?></span>
					</label>
					<label class="epm-choice">
						<input type="radio" name="new_status" value="draft" <?php checked( 'draft', $epm_hosting['new_status'] ); ?> />
						<span class="epm-choice__title"><?php esc_html_e( 'Add them as drafts for review', 'elementor-podcast-manager' ); ?></span>
						<span class="epm-choice__text"><?php esc_html_e( 'Useful when you add guest photos or links before an episode page goes live.', 'elementor-podcast-manager' ); ?></span>
					</label>
				</fieldset>
			</div>

			<div class="epm-card__footer epm-card__footer--split">
				<button type="button" class="epm-button-link epm-button-link--muted" data-action="back"><?php esc_html_e( 'Back', 'elementor-podcast-manager' ); ?></button>
				<button type="submit" class="button button-primary button-large" data-import-button disabled><?php esc_html_e( 'Import episodes', 'elementor-podcast-manager' ); ?></button>
			</div>
		</form>
	</section>

	<!-- Step 3: import progress. -->
	<section class="epm-panel epm-card" data-panel="import" tabindex="-1" aria-labelledby="epm-setup-import-title" hidden>
		<h2 class="epm-panel__title" id="epm-setup-import-title"><?php esc_html_e( 'Importing your episodes', 'elementor-podcast-manager' ); ?></h2>
		<p class="epm-panel__lede" data-import-lede><?php esc_html_e( 'The import continues in the background if you leave this page. Episodes that already exist here are updated, never duplicated.', 'elementor-podcast-manager' ); ?></p>

		<div class="epm-progress" data-import-progress>
			<div class="epm-progress__track" role="progressbar" aria-labelledby="epm-setup-import-title" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
				<div class="epm-progress__bar"></div>
			</div>
			<div class="epm-progress__label">
				<span data-import-count></span>
				<span data-import-summary></span>
			</div>
		</div>

		<div class="epm-callout epm-callout--error" data-import-error hidden><p></p></div>
		<div class="epm-callout epm-callout--warn" data-import-incomplete hidden><p></p></div>

		<div class="epm-callout epm-callout--warn" data-media-failed hidden>
			<p><strong data-media-failed-title></strong> <?php esc_html_e( 'These episodes still play from the old host. Open each one to add the audio file, or run the import again, before you close the old account.', 'elementor-podcast-manager' ); ?></p>
			<ul class="epm-callout__list" data-media-failed-list></ul>
		</div>

		<details class="epm-details" data-import-details>
			<summary><?php esc_html_e( 'Show the import log', 'elementor-podcast-manager' ); ?></summary>
			<div class="epm-details__body">
				<ul class="epm-log" data-import-log aria-live="off"></ul>
			</div>
		</details>

		<div class="epm-card__footer epm-card__footer--split">
			<button type="button" class="epm-button-link epm-button-link--danger" data-action="cancel-import"><?php esc_html_e( 'Stop the import', 'elementor-podcast-manager' ); ?></button>
			<button type="button" class="button button-primary button-large" data-action="next" data-import-continue disabled><?php esc_html_e( 'Continue', 'elementor-podcast-manager' ); ?></button>
		</div>
	</section>

	<!-- Step 4: show details. -->
	<section class="epm-panel epm-card" data-panel="show" tabindex="-1" aria-labelledby="epm-setup-show-title" hidden>
		<form data-step-form="show" novalidate>
			<h2 class="epm-panel__title" id="epm-setup-show-title"><?php esc_html_e( 'Show details', 'elementor-podcast-manager' ); ?></h2>
			<p class="epm-panel__lede">
				<span data-path-only="new move"><?php esc_html_e( 'This is what listeners see in Apple Podcasts, Spotify and on your website.', 'elementor-podcast-manager' ); ?></span>
				<span data-path-only="external"><?php esc_html_e( 'Your host publishes these details to the apps. Here they are used on your website, for example in the podcast header widget.', 'elementor-podcast-manager' ); ?></span>
			</p>

			<div class="epm-field">
				<label class="epm-field__label" for="epm-setup-title"><?php esc_html_e( 'Podcast title', 'elementor-podcast-manager' ); ?></label>
				<input type="text" id="epm-setup-title" name="title" value="<?php echo esc_attr( (string) $epm_settings['title'] ); ?>" required autocomplete="off" aria-describedby="epm-setup-title-error" />
				<p class="epm-field__error" id="epm-setup-title-error" data-error-for="title" hidden><?php esc_html_e( 'Enter the name of your podcast.', 'elementor-podcast-manager' ); ?></p>
			</div>

			<div class="epm-field">
				<label class="epm-field__label" for="epm-setup-description"><?php esc_html_e( 'Description', 'elementor-podcast-manager' ); ?></label>
				<?php $epm_description = trim( html_entity_decode( wp_strip_all_tags( (string) $epm_settings['description'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ); ?>
				<textarea id="epm-setup-description" name="description" rows="4" aria-describedby="epm-setup-description-help" data-initial="<?php echo esc_attr( $epm_description ); ?>"><?php echo esc_textarea( $epm_description ); ?></textarea>
				<p class="epm-field__help" id="epm-setup-description-help"><?php esc_html_e( 'What the show is about and who it is for. Directories show the first two lines in search results.', 'elementor-podcast-manager' ); ?></p>
			</div>

			<div class="epm-field-row">
				<div class="epm-field">
					<label class="epm-field__label" for="epm-setup-author"><?php esc_html_e( 'Author or presenter name', 'elementor-podcast-manager' ); ?></label>
					<input type="text" id="epm-setup-author" name="author" value="<?php echo esc_attr( (string) $epm_settings['author'] ); ?>" autocomplete="name" />
				</div>
				<div class="epm-field">
					<label class="epm-field__label" for="epm-setup-category"><?php esc_html_e( 'Category', 'elementor-podcast-manager' ); ?></label>
					<select id="epm-setup-category" name="category">
						<option value=""><?php esc_html_e( 'Choose a category', 'elementor-podcast-manager' ); ?></option>
						<?php foreach ( \EPM\Categories::all() as $epm_cat => $epm_subs ) : ?>
							<optgroup label="<?php echo esc_attr( $epm_cat ); ?>">
								<option value="<?php echo esc_attr( $epm_cat ); ?>" <?php selected( $epm_category, $epm_cat ); ?>><?php echo esc_html( $epm_cat ); ?></option>
								<?php foreach ( $epm_subs as $epm_sub ) : ?>
									<?php $epm_value = \EPM\Categories::encode( $epm_cat, $epm_sub ); ?>
									<option value="<?php echo esc_attr( $epm_value ); ?>" <?php selected( $epm_category, $epm_value ); ?>><?php echo esc_html( $epm_cat . ' › ' . $epm_sub ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="epm-field-row">
				<div class="epm-field">
					<label class="epm-field__label" for="epm-setup-owner-name"><?php esc_html_e( 'Owner name', 'elementor-podcast-manager' ); ?></label>
					<input type="text" id="epm-setup-owner-name" name="owner_name" value="<?php echo esc_attr( (string) $epm_settings['owner_name'] ); ?>" autocomplete="name" />
				</div>
				<div class="epm-field">
					<label class="epm-field__label" for="epm-setup-owner-email"><?php esc_html_e( 'Owner email', 'elementor-podcast-manager' ); ?></label>
					<input type="email" id="epm-setup-owner-email" name="owner_email" value="<?php echo esc_attr( (string) $epm_settings['owner_email'] ); ?>" autocomplete="email" aria-describedby="epm-setup-owner-email-help epm-setup-owner-email-error" />
					<p class="epm-field__help" id="epm-setup-owner-email-help"><?php esc_html_e( 'Spotify, Amazon and YouTube send a verification code to this address. It appears in your feed, so use one you are happy to share.', 'elementor-podcast-manager' ); ?></p>
					<p class="epm-field__error" id="epm-setup-owner-email-error" data-error-for="owner_email" hidden><?php esc_html_e( 'Enter an email address like name@example.com.', 'elementor-podcast-manager' ); ?></p>
				</div>
			</div>

			<div class="epm-field-row">
				<div class="epm-field">
					<label class="epm-field__label" for="epm-setup-language"><?php esc_html_e( 'Language', 'elementor-podcast-manager' ); ?></label>
					<input type="text" id="epm-setup-language" name="language" value="<?php echo esc_attr( (string) $epm_settings['language'] ); ?>" list="epm-setup-languages" autocomplete="off" spellcheck="false" />
					<datalist id="epm-setup-languages">
						<?php foreach ( [ 'en_US', 'en_GB', 'de_DE', 'de_AT', 'de_CH', 'fr_FR', 'es_ES', 'it_IT', 'nl_NL', 'pt_BR', 'pt_PT', 'sv_SE', 'da_DK', 'nb_NO', 'fi', 'pl_PL', 'cs_CZ', 'ja', 'zh_CN' ] as $epm_lang ) : ?>
							<option value="<?php echo esc_attr( $epm_lang ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
				</div>
				<fieldset class="epm-field">
					<legend class="epm-field__label"><?php esc_html_e( 'Content', 'elementor-podcast-manager' ); ?></legend>
					<label><input type="radio" name="explicit" value="clean" <?php checked( 'explicit' !== $epm_settings['explicit'] ); ?> /> <?php esc_html_e( 'Suitable for all ages', 'elementor-podcast-manager' ); ?></label>
					<label><input type="radio" name="explicit" value="explicit" <?php checked( 'explicit', $epm_settings['explicit'] ); ?> /> <?php esc_html_e( 'Explicit', 'elementor-podcast-manager' ); ?></label>
				</fieldset>
			</div>

			<fieldset class="epm-field">
				<legend class="epm-field__label"><?php esc_html_e( 'Episode order', 'elementor-podcast-manager' ); ?></legend>
				<label><input type="radio" name="type" value="episodic" <?php checked( 'serial' !== $epm_settings['type'] ); ?> /> <?php esc_html_e( 'Newest first — episodes stand on their own (interviews, news)', 'elementor-podcast-manager' ); ?></label>
				<label><input type="radio" name="type" value="serial" <?php checked( 'serial', $epm_settings['type'] ); ?> /> <?php esc_html_e( 'Oldest first — listeners should start at episode 1 (stories, courses)', 'elementor-podcast-manager' ); ?></label>
			</fieldset>

			<?php $epm_previous = \EPM\Feed::previous_plugins(); ?>
			<?php if ( ! empty( $epm_previous ) ) : ?>
				<fieldset class="epm-field" data-path-only="new move">
					<legend class="epm-field__label"><?php esc_html_e( 'Your previous feed address', 'elementor-podcast-manager' ); ?></legend>
					<label>
						<input type="checkbox" name="feed_alias" value="1" <?php checked( ! empty( $epm_settings['feed_alias'] ) ); ?> aria-describedby="epm-setup-feed-alias-help" />
						<?php
						printf(
							/* translators: %s: old feed address, e.g. https://example.com/feed/podcast/ */
							esc_html__( 'Redirect %s to this feed', 'elementor-podcast-manager' ),
							'<code>' . esc_html( home_url( '/feed/podcast/' ) ) . '</code>'
						);
						?>
					</label>
					<p class="epm-field__help" id="epm-setup-feed-alias-help">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: plugin names, e.g. "PowerPress" */
								__( 'This site has settings of %s, whose feed was at /feed/podcast/. Apps and directories subscribed there follow the permanent redirect to the new feed. Turn it on once the old plugin is deactivated.', 'elementor-podcast-manager' ),
								implode( ', ', $epm_previous )
							)
						);
						?>
					</p>
				</fieldset>
			<?php endif; ?>

			<div class="epm-field">
				<span class="epm-field__label" id="epm-setup-artwork-label"><?php esc_html_e( 'Podcast artwork', 'elementor-podcast-manager' ); ?></span>
				<div class="epm-artwork-picker">
					<div class="epm-artwork-picker__preview<?php echo '' !== $epm_artwork['url'] ? ' has-image' : ''; ?>" data-artwork-preview>
						<?php if ( '' !== $epm_artwork['url'] ) : ?>
							<img src="<?php echo esc_url( $epm_artwork['url'] ); ?>" alt="<?php esc_attr_e( 'Current podcast artwork', 'elementor-podcast-manager' ); ?>" />
						<?php else : ?>
							<span><?php esc_html_e( 'Square image', 'elementor-podcast-manager' ); ?><br />3000 × 3000</span>
						<?php endif; ?>
					</div>
					<div class="epm-stack--tight">
						<input type="hidden" name="artwork_id" value="<?php echo esc_attr( (string) (int) $epm_settings['artwork_id'] ); ?>" />
						<button type="button" class="button" data-action="choose-artwork" aria-describedby="epm-setup-artwork-label">
							<?php echo (int) $epm_settings['artwork_id'] > 0 ? esc_html__( 'Replace artwork', 'elementor-podcast-manager' ) : esc_html__( 'Choose artwork', 'elementor-podcast-manager' ); ?>
						</button>
						<p class="epm-field__help"><?php esc_html_e( 'JPEG or PNG, exactly square, 1400 to 3000 px. Keep text large: it is shown as small as 55 px.', 'elementor-podcast-manager' ); ?></p>
						<ul class="epm-checklist" data-artwork-checks>
							<?php foreach ( $epm_artwork['messages'] as $epm_message ) : ?>
								<li class="epm-checklist__item epm-checklist__item--warning"><span class="epm-checklist__icon" aria-hidden="true">!</span><span class="epm-checklist__label"><?php echo esc_html( $epm_message ); ?></span></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			</div>

			<p class="epm-field__error" data-error hidden></p>

			<div class="epm-card__footer epm-card__footer--split">
				<button type="button" class="epm-button-link epm-button-link--muted" data-action="back"><?php esc_html_e( 'Back', 'elementor-podcast-manager' ); ?></button>
				<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save and continue', 'elementor-podcast-manager' ); ?></button>
			</div>
		</form>
	</section>

	<!-- Step 5: design preset and podcast page. -->
	<section class="epm-panel epm-card" data-panel="look" tabindex="-1" aria-labelledby="epm-setup-look-title" hidden>
		<form data-step-form="look" novalidate>
			<h2 class="epm-panel__title" id="epm-setup-look-title"><?php esc_html_e( 'Look and podcast page', 'elementor-podcast-manager' ); ?></h2>
			<p class="epm-panel__lede"><?php esc_html_e( 'Pick a starting style for the player and episode lists. Everything stays adjustable in Podcast → Design and in each Elementor widget.', 'elementor-podcast-manager' ); ?></p>

			<fieldset class="epm-choices">
				<legend class="epm-sr-only"><?php esc_html_e( 'Style', 'elementor-podcast-manager' ); ?></legend>
				<?php foreach ( $epm_presets as $epm_id => $epm_p ) : ?>
					<?php
					$epm_tokens = (array) ( $epm_p['tokens'] ?? [] );
					$epm_swatch = sprintf(
						'--swatch-bg:%1$s;--swatch-surface:%2$s;--swatch-text:%3$s;--swatch-accent:%4$s;--swatch-radius:%5$spx',
						sanitize_hex_color( (string) ( $epm_tokens['background'] ?? '#ffffff' ) ) ?: '#ffffff',
						sanitize_hex_color( (string) ( $epm_tokens['surface'] ?? '#f6f7f7' ) ) ?: '#f6f7f7',
						sanitize_hex_color( (string) ( $epm_tokens['text'] ?? '#111111' ) ) ?: '#111111',
						sanitize_hex_color( (string) ( $epm_tokens['accent'] ?? '#2271b1' ) ) ?: '#2271b1',
						(int) ( $epm_tokens['border_radius'] ?? 8 )
					);
					?>
					<label class="epm-choice epm-preset-tile">
						<input type="radio" name="preset" value="<?php echo esc_attr( (string) $epm_id ); ?>" <?php checked( $epm_preset, $epm_id ); ?> />
						<span class="epm-preset-swatch" style="<?php echo esc_attr( $epm_swatch ); ?>" aria-hidden="true">
							<span class="epm-preset-swatch__play"></span>
							<span class="epm-preset-swatch__lines"><span></span><span></span></span>
						</span>
						<span class="epm-choice__title"><?php echo esc_html( (string) $epm_p['name'] ); ?></span>
						<span class="epm-choice__text"><?php echo esc_html( (string) ( $epm_p['description'] ?? '' ) ); ?></span>
					</label>
				<?php endforeach; ?>
			</fieldset>

			<?php if ( $epm_state['page_id'] > 0 && get_post( $epm_state['page_id'] ) ) : ?>
				<p class="epm-field__help">
					<?php
					printf(
						/* translators: %s: link to the podcast page */
						esc_html__( 'Your podcast page: %s', 'elementor-podcast-manager' ),
						'<a href="' . esc_url( (string) get_permalink( $epm_state['page_id'] ) ) . '">' . esc_html( get_the_title( $epm_state['page_id'] ) ) . '</a>'
					);
					?>
				</p>
			<?php else : ?>
				<label class="epm-choice">
					<input type="checkbox" name="create_page" value="1" checked />
					<span class="epm-choice__title"><?php esc_html_e( 'Create a podcast page', 'elementor-podcast-manager' ); ?></span>
					<span class="epm-choice__text"><?php esc_html_e( 'A published page with the latest episode, subscribe buttons and the episode list. Each episode also gets its own page automatically. Rebuild it in Elementor with the podcast widgets any time.', 'elementor-podcast-manager' ); ?></span>
				</label>
			<?php endif; ?>

			<p class="epm-field__error" data-error hidden></p>

			<div class="epm-card__footer epm-card__footer--split">
				<button type="button" class="epm-button-link epm-button-link--muted" data-action="back"><?php esc_html_e( 'Back', 'elementor-podcast-manager' ); ?></button>
				<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save and continue', 'elementor-podcast-manager' ); ?></button>
			</div>
		</form>
	</section>

	<!-- Step 6: what happens next. -->
	<section class="epm-panel epm-card" data-panel="done" tabindex="-1" aria-labelledby="epm-setup-done-title" hidden>
		<h2 class="epm-panel__title" id="epm-setup-done-title">
			<span data-path-only="new"><?php esc_html_e( 'Your podcast is set up', 'elementor-podcast-manager' ); ?></span>
			<span data-path-only="move"><?php esc_html_e( 'Your episodes are here — one step left', 'elementor-podcast-manager' ); ?></span>
			<span data-path-only="external"><?php esc_html_e( 'Your website is connected to your host', 'elementor-podcast-manager' ); ?></span>
		</h2>

		<div class="epm-stack" data-path-only="new">
			<p class="epm-panel__lede"><?php esc_html_e( 'Publish your first episode, then submit this feed address once to each platform. New episodes reach them automatically after that.', 'elementor-podcast-manager' ); ?></p>
			<div class="epm-copy">
				<code class="epm-copy__value"><?php echo esc_html( \EPM\Feed::url() ); ?></code>
				<button type="button" class="button" data-copy="<?php echo esc_attr( \EPM\Feed::url() ); ?>"><?php esc_html_e( 'Copy feed address', 'elementor-podcast-manager' ); ?></button>
			</div>
		</div>

		<div class="epm-stack" data-path-only="move">
			<p class="epm-panel__lede"><?php esc_html_e( 'Point your old feed at this website with a permanent (301) redirect. Apple Podcasts, Spotify and every app then switch over by themselves: subscribers, reviews and rankings stay.', 'elementor-podcast-manager' ); ?></p>
			<ol class="epm-checklist">
				<li class="epm-checklist__item">
					<span class="epm-checklist__icon" aria-hidden="true">1</span>
					<span class="epm-checklist__label"><?php esc_html_e( 'Copy this site’s feed address', 'elementor-podcast-manager' ); ?></span>
					<div class="epm-checklist__text epm-copy">
						<code class="epm-copy__value"><?php echo esc_html( \EPM\Feed::url() ); ?></code>
						<button type="button" class="button" data-copy="<?php echo esc_attr( \EPM\Feed::url() ); ?>"><?php esc_html_e( 'Copy feed address', 'elementor-podcast-manager' ); ?></button>
					</div>
				</li>
				<li class="epm-checklist__item">
					<span class="epm-checklist__icon" aria-hidden="true">2</span>
					<span class="epm-checklist__label" data-redirect-title><?php esc_html_e( 'Set the redirect at your old host', 'elementor-podcast-manager' ); ?></span>
					<p class="epm-checklist__text" data-redirect-help></p>
				</li>
				<li class="epm-checklist__item">
					<span class="epm-checklist__icon" aria-hidden="true">3</span>
					<span class="epm-checklist__label"><?php esc_html_e( 'Keep the old account for four weeks', 'elementor-podcast-manager' ); ?></span>
					<p class="epm-checklist__text"><?php esc_html_e( 'Apple asks for at least four weeks so every app sees the redirect. This feed also announces its new address in the meantime.', 'elementor-podcast-manager' ); ?></p>
				</li>
			</ol>
		</div>

		<div class="epm-stack" data-path-only="external">
			<p class="epm-panel__lede"><?php esc_html_e( 'New episodes you publish at your host appear here within the hour. Keep publishing at your host; edits you make here to an episode page are kept.', 'elementor-podcast-manager' ); ?></p>
			<dl class="epm-kv">
				<dt><?php esc_html_e( 'Feed for listeners', 'elementor-podcast-manager' ); ?></dt>
				<dd data-public-feed><?php echo esc_html( \EPM\Hosting::public_feed_url() ); ?></dd>
				<dt><?php esc_html_e( 'This site’s old feed address', 'elementor-podcast-manager' ); ?></dt>
				<dd><?php esc_html_e( 'Redirects permanently to your host’s feed.', 'elementor-podcast-manager' ); ?></dd>
			</dl>
		</div>

		<div class="epm-stack" data-readiness hidden>
			<h3 class="epm-card__title"><?php esc_html_e( 'Before you submit', 'elementor-podcast-manager' ); ?></h3>
			<ul class="epm-checklist" data-readiness-list></ul>
		</div>

		<div class="epm-card__footer">
			<a class="button button-primary button-large" data-path-only="new" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . \EPM\EpisodePostType::CPT ) ); ?>"><?php esc_html_e( 'Add your first episode', 'elementor-podcast-manager' ); ?></a>
			<a class="button button-primary button-large" data-path-only="move external" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . \EPM\EpisodePostType::CPT ) ); ?>"><?php esc_html_e( 'View imported episodes', 'elementor-podcast-manager' ); ?></a>
			<a class="button button-large" data-path-only="new move" href="<?php echo esc_url( admin_url( 'admin.php?page=epm-distribution' ) ); ?>"><?php esc_html_e( 'Get listed on Apple, Spotify and more', 'elementor-podcast-manager' ); ?></a>
			<a class="button button-large" data-page-link hidden href="#"><?php esc_html_e( 'View podcast page', 'elementor-podcast-manager' ); ?></a>
			<a class="epm-button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=epm-dashboard' ) ); ?>"><?php esc_html_e( 'Go to the podcast dashboard', 'elementor-podcast-manager' ); ?></a>
		</div>
	</section>
</div>
