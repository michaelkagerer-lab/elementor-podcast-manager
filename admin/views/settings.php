<?php
/**
 * Podcast settings view (Settings API).
 *
 * @package EPM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = epm()->settings->all();
?>
<div class="wrap epm-settings">
	<h1><?php esc_html_e( 'Podcast Settings', 'elementor-podcast-manager' ); ?></h1>
	<?php settings_errors(); ?>

	<form method="post" action="options.php">
		<?php settings_fields( 'epm_podcast_settings_group' ); ?>

		<h2><?php esc_html_e( 'Podcast', 'elementor-podcast-manager' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="epm-s-title"><?php esc_html_e( 'Podcast title', 'elementor-podcast-manager' ); ?></label></th>
				<td><input type="text" id="epm-s-title" name="epm_podcast_settings[title]" value="<?php echo esc_attr( (string) $settings['title'] ); ?>" class="regular-text" required /></td>
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
				<th scope="row"><label for="epm-s-host"><?php esc_html_e( 'Host', 'elementor-podcast-manager' ); ?></label></th>
				<td><input type="text" id="epm-s-host" name="epm_podcast_settings[host]" value="<?php echo esc_attr( (string) $settings['host'] ); ?>" class="regular-text" /></td>
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
					<select id="epm-s-category" name="epm_podcast_settings[category]">
						<option value=""><?php esc_html_e( '— Select a category —', 'elementor-podcast-manager' ); ?></option>
						<?php if ( ! $epm_known_category ) : ?>
							<option value="<?php echo esc_attr( $epm_current_category ); ?>" selected><?php echo esc_html( $epm_current_category ); ?></option>
						<?php endif; ?>
						<?php foreach ( \EPM\Categories::all() as $epm_cat => $epm_subs ) : ?>
							<optgroup label="<?php echo esc_attr( $epm_cat ); ?>">
								<option value="<?php echo esc_attr( $epm_cat ); ?>" <?php selected( $epm_current_category, $epm_cat ); ?>><?php echo esc_html( $epm_cat ); ?></option>
								<?php foreach ( $epm_subs as $epm_sub ) : ?>
									<?php $epm_value = \EPM\Categories::encode( $epm_cat, $epm_sub ); ?>
									<option value="<?php echo esc_attr( $epm_value ); ?>" <?php selected( $epm_current_category, $epm_value ); ?>><?php echo esc_html( $epm_cat . ' › ' . $epm_sub ); ?></option>
								<?php endforeach; ?>
							</optgroup>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Apple Podcasts categories (used by most directories). Choose a subcategory where one fits.', 'elementor-podcast-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-language"><?php esc_html_e( 'Language', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<input type="text" id="epm-s-language" name="epm_podcast_settings[language]" value="<?php echo esc_attr( (string) $settings['language'] ); ?>" class="regular-text" list="epm-language-suggestions" />
					<datalist id="epm-language-suggestions">
						<?php foreach ( [ 'en', 'en-us', 'en-gb', 'de', 'de-de', 'de-at', 'de-ch', 'fr', 'es', 'it', 'nl', 'pt', 'pt-br', 'sv', 'da', 'no', 'fi', 'pl', 'cs', 'ja', 'zh' ] as $epm_lang ) : ?>
							<option value="<?php echo esc_attr( $epm_lang ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
					<p class="description">
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
				<th scope="row"><label for="epm-s-explicit"><?php esc_html_e( 'Explicit', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<select id="epm-s-explicit" name="epm_podcast_settings[explicit]">
						<option value="clean" <?php selected( $settings['explicit'], 'clean' ); ?>><?php esc_html_e( 'Clean', 'elementor-podcast-manager' ); ?></option>
						<option value="explicit" <?php selected( $settings['explicit'], 'explicit' ); ?>><?php esc_html_e( 'Explicit', 'elementor-podcast-manager' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-type"><?php esc_html_e( 'Podcast type', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<select id="epm-s-type" name="epm_podcast_settings[type]">
						<option value="episodic" <?php selected( $settings['type'], 'episodic' ); ?>><?php esc_html_e( 'Episodic', 'elementor-podcast-manager' ); ?></option>
						<option value="serial" <?php selected( $settings['type'], 'serial' ); ?>><?php esc_html_e( 'Serial', 'elementor-podcast-manager' ); ?></option>
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
				<td><input type="email" id="epm-s-owner-email" name="epm_podcast_settings[owner_email]" value="<?php echo esc_attr( (string) $settings['owner_email'] ); ?>" class="regular-text" /></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Artwork', 'elementor-podcast-manager' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row" id="epm-s-artwork_id"><?php esc_html_e( 'Podcast artwork', 'elementor-podcast-manager' ); ?></th>
				<td>
					<?php $this->media_field( 'artwork_id', (int) $settings['artwork_id'], __( 'Choose podcast artwork', 'elementor-podcast-manager' ) ); ?>
					<p class="description"><?php esc_html_e( 'Square image, at least 1400×1400 px recommended for podcast directories.', 'elementor-podcast-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Default episode artwork', 'elementor-podcast-manager' ); ?></th>
				<td>
					<?php $this->media_field( 'default_artwork_id', (int) $settings['default_artwork_id'], __( 'Choose default episode artwork', 'elementor-podcast-manager' ) ); ?>
					<p class="description"><?php esc_html_e( 'Used when an episode has no artwork of its own.', 'elementor-podcast-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-default-author"><?php esc_html_e( 'Default author', 'elementor-podcast-manager' ); ?></label></th>
				<td><input type="text" id="epm-s-default-author" name="epm_podcast_settings[default_author]" value="<?php echo esc_attr( (string) $settings['default_author'] ); ?>" class="regular-text" /></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Distribution', 'elementor-podcast-manager' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="epm-s-feed-limit"><?php esc_html_e( 'Feed episode limit', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<input type="number" id="epm-s-feed-limit" name="epm_podcast_settings[feed_limit]" value="<?php echo esc_attr( (string) $settings['feed_limit'] ); ?>" class="small-text" min="0" max="10000" step="1" />
					<p class="description"><?php esc_html_e( 'Newest episodes with distribution-ready audio included in the feed. 0 = unlimited. Episodes without audio never displace eligible episodes.', 'elementor-podcast-manager' ); ?></p>
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
						<label for="epm-s-cta-label"><?php esc_html_e( 'Button label', 'elementor-podcast-manager' ); ?></label><br />
						<input type="text" id="epm-s-cta-label" name="epm_podcast_settings[latest_cta_label]" value="<?php echo esc_attr( (string) $settings['latest_cta_label'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Listen to the latest episode', 'elementor-podcast-manager' ); ?>" />
					</p>
					<p class="description"><?php esc_html_e( 'When enabled, the [podcast_latest_cta] shortcode renders a button that always links to the newest published episode.', 'elementor-podcast-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'RSS Feed URL', 'elementor-podcast-manager' ); ?></th>
				<td>
					<p><code><?php echo esc_html( \EPM\Feed::url() ); ?></code></p>
					<p>
						<button type="button" class="button" data-epm-copy="<?php echo esc_attr( \EPM\Feed::url() ); ?>"><?php esc_html_e( 'Copy', 'elementor-podcast-manager' ); ?></button>
						<a href="<?php echo esc_url( \EPM\Feed::url() ); ?>" target="_blank" rel="noopener" class="button"><?php esc_html_e( 'Open Feed', 'elementor-podcast-manager' ); ?></a>
					</p>
					<p class="description"><?php esc_html_e( 'Use this URL when submitting your podcast to podcast platforms.', 'elementor-podcast-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="epm-s-funding-url"><?php esc_html_e( 'Support / funding link', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<input type="url" id="epm-s-funding-url" name="epm_podcast_settings[funding_url]" value="<?php echo esc_attr( (string) $settings['funding_url'] ); ?>" class="regular-text" placeholder="https://" />
					<p>
						<label for="epm-s-funding-label"><?php esc_html_e( 'Link text', 'elementor-podcast-manager' ); ?></label><br />
						<input type="text" id="epm-s-funding-label" name="epm_podcast_settings[funding_label]" value="<?php echo esc_attr( (string) $settings['funding_label'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Support the show', 'elementor-podcast-manager' ); ?>" />
					</p>
					<p class="description"><?php esc_html_e( 'Optional. Podcast apps that support Podcasting 2.0 show this as a support/donation link.', 'elementor-podcast-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Platform links', 'elementor-podcast-manager' ); ?></th>
				<td>
					<?php $this->links_repeater( 'platform_links', (array) ( $settings['platform_links'] ?? [] ) ); ?>
					<p class="description"><?php esc_html_e( 'Shown by the Subscribe Links widget (e.g. Spotify, Apple Podcasts, YouTube).', 'elementor-podcast-manager' ); ?></p>
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
		<p class="description"><?php esc_html_e( 'Advanced. Leave these unchanged unless you are moving, pausing or ending the podcast.', 'elementor-podcast-manager' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="epm-s-new-feed-url"><?php esc_html_e( 'New feed URL', 'elementor-podcast-manager' ); ?></label></th>
				<td>
					<input type="url" id="epm-s-new-feed-url" name="epm_podcast_settings[new_feed_url]" value="<?php echo esc_attr( (string) $settings['new_feed_url'] ); ?>" class="regular-text" placeholder="https://" />
					<p class="description"><?php esc_html_e( 'Only when moving the podcast to another host: directories follow this URL to the new feed. Keep the old feed online until they have switched.', 'elementor-podcast-manager' ); ?></p>
					<label>
						<input type="checkbox" name="epm_podcast_settings[moved_in]" value="1" <?php checked( ! empty( $settings['moved_in'] ) ); ?> />
						<?php esc_html_e( 'This show moved here from another host (the feed announces this address as its new home; keep it on for at least four weeks)', 'elementor-podcast-manager' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Directory options', 'elementor-podcast-manager' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="epm_podcast_settings[locked]" value="1" <?php checked( ! empty( $settings['locked'] ) ); ?> />
						<?php esc_html_e( 'Lock the feed (other platforms may not import it without the owner’s consent)', 'elementor-podcast-manager' ); ?>
					</label><br />
					<label>
						<input type="checkbox" name="epm_podcast_settings[complete]" value="1" <?php checked( ! empty( $settings['complete'] ) ); ?> />
						<?php esc_html_e( 'The podcast is complete (no new episodes will be published)', 'elementor-podcast-manager' ); ?>
					</label><br />
					<label>
						<input type="checkbox" name="epm_podcast_settings[itunes_block]" value="1" <?php checked( ! empty( $settings['itunes_block'] ) ); ?> />
						<?php esc_html_e( 'Hide the podcast from Apple Podcasts', 'elementor-podcast-manager' ); ?>
					</label>
				</td>
			</tr>
		</table>

		<?php submit_button(); ?>
	</form>
</div>
