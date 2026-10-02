<?php
/**
 * The result of an import (Hosting & import, setup assistant): a waiting
 * import, the file being copied, what still loads from the old host and an
 * unfinished move. Filled by admin/js/epm-import-result.js.
 *
 * @package EPM
 */

defined( 'ABSPATH' ) || exit;
?>
<p class="description" data-copy-current hidden></p>
<div class="epm-callout epm-callout--warn" data-import-waiting role="status" hidden><p></p></div>
<div class="epm-callout epm-callout--warn" data-remaining hidden>
	<p><strong data-remaining-title></strong> <span data-remaining-text></span></p>
	<div data-remaining-groups></div>
	<div class="epm-stack--tight" data-remaining-actions hidden>
		<div class="epm-callout__actions">
			<button type="button" class="button" data-action="retry-copies"><?php esc_html_e( 'Copy the missing files again', 'elementor-podcast-manager' ); ?></button>
		</div>
		<div class="epm-stack--tight" data-confirm-move hidden>
			<label class="epm-check">
				<input type="checkbox" name="confirm_remaining" value="1" aria-describedby="epm-confirm-remaining-error" />
				<span data-confirm-move-label></span>
			</label>
			<p class="epm-field__error" id="epm-confirm-remaining-error" data-error-for="confirm_remaining" hidden><?php esc_html_e( 'Confirm that these files may stay at the old host, or copy them again first.', 'elementor-podcast-manager' ); ?></p>
			<div class="epm-callout__actions">
				<button type="button" class="button" data-action="confirm-move"><?php esc_html_e( 'Finish the move', 'elementor-podcast-manager' ); ?></button>
			</div>
		</div>
	</div>
</div>
<div class="epm-callout epm-callout--ok" data-copy-complete hidden><p><?php esc_html_e( 'Every file of the imported episodes is on this website now: audio, episode images and transcript files.', 'elementor-podcast-manager' ); ?></p></div>
