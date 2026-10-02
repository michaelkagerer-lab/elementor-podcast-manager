<?php
/**
 * Podcast settings view (Settings API).
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings         = epm()->settings->all();
$epm_external     = \EPM\Hosting::is_external();
$epm_public_feed  = \EPM\Hosting::public_feed_url();
?>
<div class="wrap epm-settings">
	<h1><?php esc_html_e( 'Podcast settings', 'elementor-podcast-manager' ); ?></h1>
	<hr class="wp-header-end" />
	<?php settings_errors(); ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'epm_podcast_settings_group' ); ?>

		<h2><?php esc_html_e( 'Podcast', 'elementor-podcast-manager' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="epm-s-title"><?php esc_html_e( 'Podcast title', 'elementor-podcast-manager' ); ?></label></th>
				<td><input type="text" id="epm-s-title" name="epm_podcast_settings[title]" value="<?php echo esc_attr( (string) $settings['title'] ); ?>" class="regular-text" required aria-required="true" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-desc"><?php esc_html_e( 'Description', 'elementor-podcast-manager' ); ?></label></th>
				<td><textarea id="epm-s-desc" name="epm_podcast_settings[description]" rows="5" class="large-text"><?php echo esc_textarea( (string) $settings['description'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-short"><?php esc_html_e( 'Short description', 'elementor-podcast-manager' ); ?></label></th>
				<td><textarea id="epm-s-short" name="epm_podcast_settings[short_description]" rows="3" class="large-text"><?php echo esc_textarea( (string) $settings['short_description'] ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-author"><?php esc_html_e( 'Author', 'elementor-podcast-manager' ); ?></label></th>
				<td><input type="text" id="epm-s-author" name="epm_podcast_settings[author]" value="<?php echo esc_attr( (string) $settings['author'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-host"><?php esc_html_e( 'Host (presenter)', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<input type="text" id="epm-s-host" name="epm_podcast_settings[host]" value="<?php echo esc_attr( (string) $settings['host'] ); ?>" class="regular-text" aria-describedby="epm-s-host-help" />
					<p class="description" id="epm-s-host-help"><?php esc_html_e( 'The person who presents the show. Shown in the podcast header and in the feed.', 'elementor-podcast-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-url"><?php esc_html_e( 'Website URL', 'elementor-podcast-manager' ); ?></label></th>
				<td><input type="url" id="epm-s-url" name="epm_podcast_settings[website_url]" value="<?php echo esc_attr( (string) $settings['website_url'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-category"><?php esc_html_e( 'Category', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<?php
					$epm_current_category = \EPM\Categories::encode( (string) $settings['category'], (string) ( $settings['subcategory'] ?? '' ) );
					$epm_known_category   = '' === (string) $settings['category'] || \EPM\Categories::is_valid( (string) $settings['category'] );
					?>
					<select id="epm-s-category" name="epm_podcast_settings[category]" aria-describedby="epm-s-category-help">
						<option value=""><?php esc_html_e( '— Select a category —', 'elementor-podcast-manager' ); ?></option>
						<?php if ( ! $epm_known_category ) : ?>
							<option value="<?php echo esc_attr( $epm_current_category ); ?>" selected><?php echo esc_html( $epm_current_category ); ?></option>
						<?php endif; ?>
						<?php foreach ( \EPM\Categories::all() as $epm_cat => $epm_subs ) : ?>
							<optgroup label="<?php echo esc_attr( \EPM\Categories::label( $epm_cat ) ); ?>">
								<option value="<?php echo esc_attr( $epm_cat ); ?>" <?php selected( $epm_current_category, $epm_cat ); ?>><?php echo esc_html( \EPM\Categories::label( $epm_cat ) ); ?></option>
								<?php foreach ( $epm_subs as $epm_sub ) : ?>
									<?php $epm_value = \EPM\Categories::encode( $epm_cat, $epm_sub ); ?>
									<option value="<?php echo esc_attr( $epm_value ); ?>" <?php selected( $epm_current_category, $epm_value ); ?>><?php echo esc_html( \EPM\Categories::label( $epm_cat ) . ' › ' . \EPM\Categories::label( $epm_sub ) ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
					<p class="description" id="epm-s-category-help"><?php esc_html_e( 'Apple Podcasts categories (used by most directories). Choose a subcategory where one fits.', 'elementor-podcast-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-language"><?php esc_html_e( 'Language', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<input type="text" id="epm-s-language" name="epm_podcast_settings[language]" value="<?php echo esc_attr( (string) $settings['language'] ); ?>" class="regular-text" list="epm-language-suggestions" aria-describedby="epm-s-language-help" autocomplete="off" />
					<datalist id="epm-language-suggestions">
						<?php foreach ( [ 'en', 'en-us', 'en-gb', 'de', 'de-de', 'de-at', 'de-ch', 'fr', 'es', 'it', 'nl', 'pt', 'pt-br', 'sv', 'da', 'no', 'fi', 'pl', 'cs', 'ja', 'zh' ] as $epm_lang ) : ?>
							<option value="<?php echo esc_attr( $epm_lang ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
					<p class="description" id="epm-s-language-help">
						<?php
						printf(
							/* translators: %s: normalized RSS language code */
							esc_html__( 'Language code (e.g. de-at) or WordPress locale (e.g. de_AT). The feed uses: %s', 'elementor-podcast-manager' ),
							'<code>' . esc_html( \EPM\Feed::rss_language( (string) $settings['language'] ) ) . '</code>'
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-explicit"><?php esc_html_e( 'Content', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<select id="epm-s-explicit" name="epm_podcast_settings[explicit]">
						<option value="clean" <?php selected( $settings['explicit'], 'clean' ); ?>><?php esc_html_e( 'Suitable for all ages', 'elementor-podcast-manager' ); ?></option>
						<option value="explicit" <?php selected( $settings['explicit'], 'explicit' ); ?>><?php esc_html_e( 'Explicit', 'elementor-podcast-manager' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-type"><?php esc_html_e( 'Episode order', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<select id="epm-s-type" name="epm_podcast_settings[type]">
						<option value="episodic" <?php selected( $settings['type'], 'episodic' ); ?>><?php esc_html_e( 'Newest first (episodic)', 'elementor-podcast-manager' ); ?></option>
						<option value="serial" <?php selected( $settings['type'], 'serial' ); ?>><?php esc_html_e( 'Oldest first (serial)', 'elementor-podcast-manager' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-copyright"><?php esc_html_e( 'Copyright', 'elementor-podcast-manager' ); ?></label></th>
				<td><input type="text" id="epm-s-copyright" name="epm_podcast_settings[copyright]" value="<?php echo esc_attr( (string) $settings['copyright'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-owner-name"><?php esc_html_e( 'Owner name', 'elementor-podcast-manager' ); ?></label></th>
				<td><input type="text" id="epm-s-owner-name" name="epm_podcast_settings[owner_name]" value="<?php echo esc_attr( (string) $settings['owner_name'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-owner-email"><?php esc_html_e( 'Owner email', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<input type="email" id="epm-s-owner-email" name="epm_podcast_settings[owner_email]" value="<?php echo esc_attr( (string) $settings['owner_email'] ); ?>" class="regular-text" autocomplete="email" aria-describedby="epm-s-owner-email-help" />
					<p class="description" id="epm-s-owner-email-help"><?php esc_html_e( 'Apple Podcasts, Spotify and YouTube send the verification code for your show to this address.', 'elementor-podcast-manager' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Artwork', 'elementor-podcast-manager' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row" id="epm-s-artwork_id"><?php esc_html_e( 'Podcast artwork', 'elementor-podcast-manager' ); ?></th>
				<td>
					<?php $this->media_field( 'artwork_id', (int) $settings['artwork_id'], __( 'Choose podcast artwork', 'elementor-podcast-manager' ), 'epm_podcast_settings', __( 'Remove podcast artwork', 'elementor-podcast-manager' ), 'epm-s-artwork-help' ); ?>
					<p class="description" id="epm-s-artwork-help"><?php esc_html_e( 'Square JPEG or PNG, 1400 to 3000 px wide (3000 px is best). Apple Podcasts and Spotify require it.', 'elementor-podcast-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Default episode artwork', 'elementor-podcast-manager' ); ?></th>
				<td>
					<?php $this->media_field( 'default_artwork_id', (int) $settings['default_artwork_id'], __( 'Choose default episode artwork', 'elementor-podcast-manager' ), 'epm_podcast_settings', __( 'Remove default episode artwork', 'elementor-podcast-manager' ), 'epm-s-default-artwork-help' ); ?>
					<p class="description" id="epm-s-default-artwork-help"><?php esc_html_e( 'Used when an episode has no artwork of its own.', 'elementor-podcast-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-default-author"><?php esc_html_e( 'Default author', 'elementor-podcast-manager' ); ?></label></th>
				<td><input type="text" id="epm-s-default-author" name="epm_podcast_settings[default_author]" value="<?php echo esc_attr( (string) $settings['default_author'] ); ?>" class="regular-text" /></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Feed and links', 'elementor-podcast-manager' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="epm-s-feed-limit"><?php esc_html_e( 'Feed episode limit', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<input type="number" id="epm-s-feed-limit" name="epm_podcast_settings[feed_limit]" value="<?php echo esc_attr( (string) $settings['feed_limit'] ); ?>" class="small-text" min="0" max="10000" step="1" aria-describedby="epm-s-feed-limit-help" />
					<p class="description" id="epm-s-feed-limit-help"><?php esc_html_e( 'Newest episodes with distribution-ready audio included in the feed. 0 = unlimited. Episodes without audio never displace eligible episodes.', 'elementor-podcast-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Latest-episode CTA', 'elementor-podcast-manager' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="epm_podcast_settings[latest_cta_enabled]" value="1" <?php checked( ! empty( $settings['latest_cta_enabled'] ) ); ?> />
						<?php esc_html_e( 'Enable automatic latest-episode call to action', 'elementor-podcast-manager' ); ?>
					</label>
					<p>
						<label for="epm-s-cta-label"><?php esc_html_e( 'Button label', 'elementor-podcast-manager' ); ?></label>
						<input type="text" id="epm-s-cta-label" name="epm_podcast_settings[latest_cta_label]" value="<?php echo esc_attr( (string) $settings['latest_cta_label'] ); ?>" class="regular-text" aria-describedby="epm-s-cta-label-help" />
					</p>
					<p class="description" id="epm-s-cta-label-help"><?php esc_html_e( 'Leave empty to use “Listen to the latest episode”.', 'elementor-podcast-manager' ); ?></p>
					<p class="description"><?php esc_html_e( 'When enabled, the [podcast_latest_cta] shortcode renders a button that always links to the newest published episode.', 'elementor-podcast-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Feed address', 'elementor-podcast-manager' ); ?></th>
				<td>
					<div class="epm-copy">
						<code class="epm-copy__value"><?php echo esc_html( $epm_public_feed ); ?></code>
						<?php echo \EPM\Admin::copy_button( $epm_public_feed, __( 'Copy feed address', 'elementor-podcast-manager' ), '', __( 'Feed address copied.', 'elementor-podcast-manager' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in copy_button(). ?>
						<a href="<?php echo esc_url( $epm_public_feed ); ?>" target="_blank" rel="noopener" class="button"><?php esc_html_e( 'View feed', 'elementor-podcast-manager' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'elementor-podcast-manager' ); ?></span></a>
					</div>
					<p class="description">
						<?php
						if ( $epm_external ) {
							printf(
								/* translators: 1: podcast host name, 2: link to the Hosting & import screen */
								esc_html__( '%1$s publishes this feed. Submit it to podcast platforms, or change where the show is hosted under %2$s.', 'elementor-podcast-manager' ),
								esc_html( \EPM\Hosting::provider_name() ),
								'<a href="' . esc_url( admin_url( 'admin.php?page=epm-hosting' ) ) . '">' . esc_html__( 'Hosting & import', 'elementor-podcast-manager' ) . '</a>'
							);
						} else {
							esc_html_e( 'Submit this address to Apple Podcasts, Spotify and other podcast platforms.', 'elementor-podcast-manager' );
						}
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-funding-url"><?php esc_html_e( 'Support / funding link', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<input type="url" id="epm-s-funding-url" name="epm_podcast_settings[funding_url]" value="<?php echo esc_attr( (string) $settings['funding_url'] ); ?>" class="regular-text" inputmode="url" spellcheck="false" aria-describedby="epm-s-funding-help" />
					<p>
						<label for="epm-s-funding-label"><?php esc_html_e( 'Link text', 'elementor-podcast-manager' ); ?></label>
						<input type="text" id="epm-s-funding-label" name="epm_podcast_settings[funding_label]" value="<?php echo esc_attr( (string) $settings['funding_label'] ); ?>" class="regular-text" aria-describedby="epm-s-funding-label-help" />
					</p>
					<p class="description" id="epm-s-funding-label-help"><?php esc_html_e( 'Leave empty to use “Support the show”.', 'elementor-podcast-manager' ); ?></p>
					<p class="description" id="epm-s-funding-help"><?php esc_html_e( 'Optional. Podcast apps that support Podcasting 2.0 show this as a support or donation link.', 'elementor-podcast-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Platform links', 'elementor-podcast-manager' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'Where listeners can follow the show, for example Apple Podcasts, Spotify or YouTube. Shown by the Subscribe Links widget. Links you add on the Distribution screen appear here automatically.', 'elementor-podcast-manager' ); ?></p>
					<?php $this->links_repeater( 'platform_links', (array) ( $settings['platform_links'] ?? [] ) ); ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Social links', 'elementor-podcast-manager' ); ?></th>
				<td>
					<?php $this->links_repeater( 'social_links', (array) ( $settings['social_links'] ?? [] ) ); ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Episode pages', 'elementor-podcast-manager' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Automatic episode page', 'elementor-podcast-manager' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="epm_podcast_settings[auto_embed]" value="1" <?php checked( ! empty( $settings['auto_embed'] ) ); ?> />
						<?php esc_html_e( 'Add the player, guest, show notes, chapters and transcript to episode pages automatically', 'elementor-podcast-manager' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Works with any theme. Skipped automatically when an Elementor Pro Theme Builder template or an Elementor-built episode page places the podcast widgets itself.', 'elementor-podcast-manager' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Feed status', 'elementor-podcast-manager' ); ?></h2>
		<p class="description">
			<?php
			echo esc_html(
				$epm_external
					? __( 'Advanced. Your host publishes the feed, so these options only affect this website’s own feed.', 'elementor-podcast-manager' )
					: __( 'Advanced. Leave these unchanged unless you are moving, pausing or ending the podcast.', 'elementor-podcast-manager' )
			);
			?>
		</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="epm-s-new-feed-url"><?php esc_html_e( 'New feed URL', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<input type="url" id="epm-s-new-feed-url" name="epm_podcast_settings[new_feed_url]" value="<?php echo esc_attr( (string) $settings['new_feed_url'] ); ?>" class="regular-text" inputmode="url" spellcheck="false" aria-describedby="epm-s-new-feed-help" />
					<p class="description" id="epm-s-new-feed-help"><?php esc_html_e( 'Only when moving the podcast to another host: directories follow this URL to the new feed. Keep the old feed online until they have switched.', 'elementor-podcast-manager' ); ?></p>
					<label>
						<input type="checkbox" name="epm_podcast_settings[moved_in]" value="1" <?php checked( ! empty( $settings['moved_in'] ) ); ?> />
						<?php esc_html_e( 'This show moved here from another host (the feed announces this address as its new home; keep it on for at least four weeks)', 'elementor-podcast-manager' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Previous feed address', 'elementor-podcast-manager' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="epm_podcast_settings[feed_alias]" value="1" <?php checked( ! empty( $settings['feed_alias'] ) ); ?> aria-describedby="epm-s-feed-alias-help" />
						<?php
						printf(
							/* translators: %s: old feed address, e.g. https://example.com/feed/podcast/ */
							esc_html__( 'Redirect %s to this feed', 'elementor-podcast-manager' ),
							'<code>' . esc_html( home_url( '/feed/podcast/' ) ) . '</code>'
						);
						?>
					</label>
					<p class="description" id="epm-s-feed-alias-help">
						<?php
						$epm_previous = \EPM\Feed::previous_plugins();
						echo esc_html(
							empty( $epm_previous )
								? __( 'For shows that moved here from PowerPress or Seriously Simple Podcasting on this website: their feed was at /feed/podcast/ (or ?feed=podcast). Apps and directories subscribed there follow a permanent redirect to this feed. Turn it on once the old plugin is deactivated.', 'elementor-podcast-manager' )
								: sprintf(
									/* translators: %s: plugin names, e.g. "PowerPress" */
									__( 'This site has settings of %s, whose feed was at /feed/podcast/ (or ?feed=podcast). Apps and directories subscribed there follow a permanent redirect to this feed. Turn it on once the old plugin is deactivated.', 'elementor-podcast-manager' ),
									implode( ', ', $epm_previous )
								)
						);
						?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Directory options', 'elementor-podcast-manager' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="epm_podcast_settings[locked]" value="1" <?php checked( ! empty( $settings['locked'] ) ); ?> />
						<?php esc_html_e( 'Lock the feed (other platforms may not import it without the owner’s consent)', 'elementor-podcast-manager' ); ?>
					</label>
					<label>
						<input type="checkbox" name="epm_podcast_settings[complete]" value="1" <?php checked( ! empty( $settings['complete'] ) ); ?> />
						<?php esc_html_e( 'The podcast is complete (no new episodes will be published)', 'elementor-podcast-manager' ); ?>
					</label>
					<label>
						<input type="checkbox" name="epm_podcast_settings[itunes_block]" value="1" <?php checked( ! empty( $settings['itunes_block'] ) ); ?> />
						<?php esc_html_e( 'Hide the podcast from Apple Podcasts', 'elementor-podcast-manager' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Save settings', 'elementor-podcast-manager' ) ); ?>
	</form>
</div>
