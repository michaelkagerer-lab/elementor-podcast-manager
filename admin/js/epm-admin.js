/**
 * EPM classic admin script: media pickers, the episode audio box (upload,
 * drop zone, audio URL), repeaters (chapters, links) with paste support,
 * the next-episode-number helper, transcript files, copy buttons, the setup
 * reminder and Quick Edit in the episode list.
 *
 * Strings come from epmAdmin.strings (translated in PHP) and wp.i18n.
 * Every asynchronous result is announced with wp.a11y.speak().
 */
(function ($) {
	'use strict';

	var __ = wp.i18n.__;
	var _n = wp.i18n._n;
	var sprintf = wp.i18n.sprintf;
	var strings = (window.epmAdmin && window.epmAdmin.strings) || {};

	/**
	 * Announce a message to screen readers.
	 *
	 * @param {string} message    Text.
	 * @param {string} politeness polite|assertive.
	 */
	function speak(message, politeness) {
		if (message && wp.a11y && wp.a11y.speak) {
			wp.a11y.speak(message, politeness || 'polite');
		}
	}

	/**
	 * Escape text for HTML.
	 *
	 * @param {*} text Value.
	 * @return {string} Escaped text.
	 */
	function escapeHtml(text) {
		return String(text == null ? '' : text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

	/**
	 * Open a Media Library frame.
	 *
	 * @param {string}       title    Frame title.
	 * @param {string|Array} type     Library type filter.
	 * @param {string}       button   Button label.
	 * @param {Function}     onSelect Called with the attachment JSON.
	 */
	function mediaFrame(title, type, button, onSelect) {
		var frame = wp.media({
			title: title,
			button: { text: button || epmAdmin.use },
			library: type ? { type: type } : {},
			multiple: false
		});
		frame.on('select', function () {
			onSelect(frame.state().get('selection').first().toJSON());
		});
		// Filtered libraries (transcripts) would otherwise open on the
		// upload tab before their files are loaded. The library view
		// accepts dropped files too.
		frame.on('open', function () {
			if (frame.content && frame.content.mode) {
				frame.content.mode('browse');
			}
		});
		frame.open();
	}

	// ---------------------------------------------------------------------
	// Image pickers (artwork, guest photo, settings).
	// ---------------------------------------------------------------------

	$(document).on('click', '[data-epm-media-choose]', function (e) {
		e.preventDefault();
		var button = $(this);
		var wrap = button.closest('[data-epm-media]');
		mediaFrame(button.data('title') || epmAdmin.choose, 'image', epmAdmin.useImage, function (attachment) {
			wrap.find('[data-epm-media-id]').val(attachment.id);
			var url = (attachment.sizes && attachment.sizes.medium) ? attachment.sizes.medium.url
				: ((attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url);
			var img = $('<img alt="" class="epm-media-preview__img" />').attr('src', url);
			wrap.find('[data-epm-media-preview]').empty().append(img);
			button.text(button.data('replace-label') || strings.replaceImage);
			wrap.find('[data-epm-media-remove]').prop('hidden', false);
			speak(strings.imageSelected);
		});
	});

	$(document).on('click', '[data-epm-media-remove]', function (e) {
		e.preventDefault();
		var wrap = $(this).closest('[data-epm-media]');
		var choose = wrap.find('[data-epm-media-choose]');
		wrap.find('[data-epm-media-id]').val('');
		wrap.find('[data-epm-media-preview]').empty();
		$(this).prop('hidden', true);
		choose.text(choose.data('choose-label') || strings.chooseImage).trigger('focus');
		speak(strings.imageRemoved);
	});

	// ---------------------------------------------------------------------
	// Episode audio box.
	// ---------------------------------------------------------------------

	var AUDIO_TYPES = /^audio\/(mpeg|mp3|mp4|x-m4a|m4a|aac|wav|x-wav|wave)$/;
	var AUDIO_EXTENSIONS = /\.(mp3|m4a|wav)$/i;

	/**
	 * Post ID of the episode being edited.
	 *
	 * @param {jQuery} wrap Audio box.
	 * @return {number} Post ID.
	 */
	function postId(wrap) {
		return Number($('#post_ID').val() || wrap.data('epm-post-id') || 0);
	}

	/**
	 * Status line below the drop zone (role=status).
	 *
	 * @param {jQuery} wrap    Audio box.
	 * @param {string} message Text ('' clears it).
	 */
	function setAudioStatus(wrap, message) {
		wrap.find('[data-epm-status]').text(message || '');
	}

	/**
	 * Error box of the audio box (role=alert).
	 *
	 * @param {jQuery} wrap    Audio box.
	 * @param {string} message Text ('' hides it).
	 */
	function showAudioError(wrap, message) {
		var box = wrap.find('[data-epm-error]');
		if (!message) {
			box.prop('hidden', true).empty();
			return;
		}
		box.empty().append($('<p />').text(message)).prop('hidden', false);
	}

	/**
	 * Disable the audio buttons while a request runs.
	 *
	 * @param {jQuery}  wrap Audio box.
	 * @param {boolean} busy Busy state.
	 */
	function setAudioBusy(wrap, busy) {
		wrap.find('[data-epm-choose-audio], [data-epm-remove-audio], [data-epm-audio-url-check]').prop('disabled', busy);
		wrap.attr('aria-busy', busy ? 'true' : null);
	}

	/**
	 * Keep the duration hint in step with the audio source.
	 *
	 * @param {jQuery} wrap     Audio box.
	 * @param {string} source   media|external|''.
	 * @param {string} duration Detected duration.
	 */
	function updateDurationHint(wrap, source, duration) {
		var hint = wrap.find('[data-epm-duration-hint]');
		if (source === 'media' && duration) {
			hint.text(sprintf(strings.durationDetected, duration));
		} else if (source === 'external') {
			hint.text(strings.durationExternal);
		} else {
			hint.text(strings.durationAuto);
		}
	}

	/**
	 * Whether a Media Library file is attached.
	 *
	 * @param {jQuery} wrap Audio box.
	 * @return {boolean} Attached.
	 */
	function hasMedia(wrap) {
		return Number(wrap.find('[data-epm-audio-id]').val()) > 0;
	}

	/**
	 * Show the "not used while a file is attached" note when both a file
	 * and an address are set.
	 *
	 * @param {jQuery} wrap Audio box.
	 */
	function updateUrlInactive(wrap) {
		var url = $.trim(wrap.find('[data-epm-audio-url-input]').val() || '');
		wrap.find('[data-epm-audio-url-inactive]').prop('hidden', !(hasMedia(wrap) && url));
	}

	/**
	 * Render the audio box state in place: a Media Library file, an audio
	 * URL, or the empty drop hint. Mirrors EpisodeMeta::audio_state_html().
	 *
	 * @param {jQuery}      wrap Audio box.
	 * @param {Object|null} data File or URL details.
	 */
	function renderAudioState(wrap, data) {
		var state = wrap.find('[data-epm-audio-state]');
		var chooseBtn = wrap.find('[data-epm-choose-audio]');
		var removeBtn = wrap.find('[data-epm-remove-audio]');
		var html;

		// Scoped entrance (see epm-admin.css): never replays on page load.
		state.removeClass('is-fresh');

		if (!data) {
			state.html('<p class="epm-upload__hint">' + escapeHtml(strings.dropHint) + '</p>');
			chooseBtn.text(strings.uploadAudio);
			removeBtn.prop('hidden', true);
			updateDurationHint(wrap, '', '');
			updateUrlInactive(wrap);
			return;
		}

		var external = data.source === 'external';
		var meta = [data.format, data.duration, data.size_formatted].filter(Boolean).join(' · ');

		html = '<p class="epm-upload__file">';
		if (external) {
			html += '<span class="epm-upload__source"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span>' + escapeHtml(strings.audioUrl) + '</span>';
			html += '<span class="epm-upload__name">' + escapeHtml(data.host) + '</span>';
		} else {
			html += '<span class="epm-upload__name">' + escapeHtml(data.filename) + '</span>';
		}
		if (meta) {
			html += '<span class="epm-upload__meta">' + escapeHtml(meta) + '</span>';
		}
		if (external) {
			html += '<span class="epm-upload__url">' + escapeHtml(data.url) + '</span>';
		}
		html += '</p>';
		if (!data.is_distribution) {
			html += '<div class="notice notice-warning inline"><p>' + escapeHtml(strings.wavWarning) + '</p></div>';
		}
		if (data.url) {
			html += '<audio controls preload="none" class="epm-upload__preview" src="' + escapeHtml(data.url) + '"></audio>';
		}
		if (external) {
			html += '<p class="epm-upload__note">' + escapeHtml(sprintf(strings.externalNote, data.host)) + '</p>';
		}

		state.addClass('is-fresh').html(html);
		chooseBtn.text(external ? strings.uploadAudio : strings.replaceAudio);
		removeBtn.prop('hidden', false);
		updateDurationHint(wrap, external ? 'external' : 'media', data.duration);
		updateUrlInactive(wrap);
	}

	/**
	 * Fetch attachment details (nonce and post-level authorization are
	 * checked on the server) and render them in place.
	 *
	 * @param {jQuery} wrap         Audio box.
	 * @param {number} attachmentId Attachment ID.
	 * @return {jQuery.Deferred} Request.
	 */
	function setAudioFromAttachment(wrap, attachmentId) {
		showAudioError(wrap, '');
		setAudioBusy(wrap, true);
		setAudioStatus(wrap, strings.readingFile);

		return $.post(ajaxurl, {
			action: 'epm_audio_describe',
			_ajax_nonce: epmAdmin.nonce,
			post_id: postId(wrap),
			attachment_id: attachmentId
		}).done(function (response) {
			if (response && response.success && response.data) {
				wrap.find('[data-epm-audio-id]').val(response.data.id);
				response.data.source = 'media';
				renderAudioState(wrap, response.data);
				speak(sprintf(strings.audioAttached, response.data.filename));
			} else {
				showAudioError(wrap, (response && response.data && response.data.message) || strings.describeFailed);
			}
		}).fail(function () {
			showAudioError(wrap, strings.describeFailed);
		}).always(function () {
			setAudioBusy(wrap, false);
			setAudioStatus(wrap, '');
			wrap.find('[data-epm-progress]').prop('hidden', true);
		});
	}

	$(document).on('click', '[data-epm-choose-audio]', function (e) {
		e.preventDefault();
		var wrap = $(this).closest('[data-epm-upload]');
		mediaFrame(epmAdmin.choose, 'audio', epmAdmin.use, function (attachment) {
			setAudioFromAttachment(wrap, attachment.id);
		});
	});

	$(document).on('click', '[data-epm-remove-audio]', function (e) {
		e.preventDefault();
		var wrap = $(this).closest('[data-epm-upload]');
		var urlField = wrap.find('[data-epm-audio-url-input]');

		if (hasMedia(wrap)) {
			wrap.find('[data-epm-audio-id]').val('');
		} else {
			urlField.val('');
		}

		var url = $.trim(urlField.val() || '');
		if (url) {
			// The address takes over once the file is gone.
			renderAudioState(wrap, { source: 'external', url: url, host: hostOf(url), is_distribution: true });
		} else {
			renderAudioState(wrap, null);
		}
		wrap.find('[data-epm-choose-audio]').trigger('focus');
		speak(strings.audioRemoved);
	});

	/**
	 * Host name of a URL without "www.".
	 *
	 * @param {string} url Address.
	 * @return {string} Host.
	 */
	function hostOf(url) {
		try {
			return new URL(url).hostname.replace(/^www\./, '');
		} catch (err) {
			return url;
		}
	}

	/**
	 * Whether a dropped file is episode audio (by type, or by extension
	 * when the browser reports no or a generic type).
	 *
	 * @param {File} file Dropped file.
	 * @return {boolean} Accepted.
	 */
	function isAudioFile(file) {
		var type = String(file.type || '').toLowerCase();
		if (AUDIO_TYPES.test(type)) {
			return true;
		}
		return (!type || type === 'application/octet-stream') && AUDIO_EXTENSIONS.test(file.name || '');
	}

	/**
	 * Whether a drag carries files (not text or links).
	 *
	 * @param {Event} e jQuery event.
	 * @return {boolean} Files.
	 */
	function dragHasFiles(e) {
		var dt = e.originalEvent && e.originalEvent.dataTransfer;
		return !!dt && Array.prototype.indexOf.call(dt.types || [], 'Files') !== -1;
	}

	// Drag and drop. A depth counter keeps the highlight steady while the
	// pointer crosses the hint text and buttons inside the zone.
	$(document).on('dragenter', '[data-epm-drop]', function (e) {
		if (!dragHasFiles(e)) {
			return;
		}
		e.preventDefault();
		var zone = $(this);
		zone.data('epm-depth', (zone.data('epm-depth') || 0) + 1).addClass('is-dragover');
	});
	$(document).on('dragover', '[data-epm-drop]', function (e) {
		if (!dragHasFiles(e)) {
			return;
		}
		e.preventDefault();
		e.originalEvent.dataTransfer.dropEffect = 'copy';
	});
	$(document).on('dragleave', '[data-epm-drop]', function () {
		var zone = $(this);
		var depth = Math.max(0, (zone.data('epm-depth') || 0) - 1);
		zone.data('epm-depth', depth);
		if (depth === 0) {
			zone.removeClass('is-dragover');
		}
	});
	$(document).on('dragend', function () {
		$('[data-epm-drop]').data('epm-depth', 0).removeClass('is-dragover');
	});
	$(document).on('drop', '[data-epm-drop]', function (e) {
		e.preventDefault();
		var zone = $(this);
		zone.data('epm-depth', 0).removeClass('is-dragover');

		var dt = e.originalEvent.dataTransfer;
		var files = dt && dt.files;
		if (!files || !files.length) {
			return;
		}
		var wrap = zone.closest('[data-epm-upload]');
		if (!isAudioFile(files[0])) {
			showAudioError(wrap, strings.invalidType);
			return;
		}
		uploadAudioFile(wrap, files[0]);
	});

	/**
	 * Upload a dropped file with progress, then read its details.
	 *
	 * @param {jQuery} wrap Audio box.
	 * @param {File}   file Audio file.
	 */
	function uploadAudioFile(wrap, file) {
		var progress = wrap.find('[data-epm-progress]');
		var lastPct = -1;

		showAudioError(wrap, '');
		progress.val(0).prop('hidden', false);
		/* translators: 1: file name, 2: percentage uploaded */
		setAudioStatus(wrap, sprintf(__('Uploading %1$s… %2$d%%', 'elementor-podcast-manager'), file.name, 0));
		speak(sprintf(strings.uploadingFile, file.name));
		setAudioBusy(wrap, true);

		var data = new FormData();
		data.append('file', file);
		data.append('action', 'epm_upload_audio');
		data.append('_ajax_nonce', epmAdmin.nonce);
		data.append('post_id', postId(wrap));

		$.ajax({
			url: ajaxurl,
			type: 'POST',
			data: data,
			processData: false,
			contentType: false,
			xhr: function () {
				var xhr = $.ajaxSettings.xhr();
				if (xhr.upload) {
					xhr.upload.addEventListener('progress', function (evt) {
						if (!evt.lengthComputable) {
							return;
						}
						var pct = Math.round((evt.loaded / evt.total) * 100);
						if (pct === lastPct) {
							return;
						}
						lastPct = pct;
						progress.val(pct);
						/* translators: 1: file name, 2: percentage uploaded */
						setAudioStatus(wrap, sprintf(__('Uploading %1$s… %2$d%%', 'elementor-podcast-manager'), file.name, pct));
					});
				}
				return xhr;
			}
		}).done(function (response) {
			if (response && response.success && response.data && response.data.id) {
				progress.val(100);
				// The bar stays until the details are shown: no dead moment.
				setAudioFromAttachment(wrap, response.data.id);
				return;
			}
			finishUploadWithError(wrap, (response && response.data && response.data.message) || strings.uploadFailed);
		}).fail(function (jqXHR) {
			var message = strings.uploadFailed;
			if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
				message = jqXHR.responseJSON.data.message;
			}
			finishUploadWithError(wrap, message);
		});
	}

	/**
	 * End a failed upload.
	 *
	 * @param {jQuery} wrap    Audio box.
	 * @param {string} message Error.
	 */
	function finishUploadWithError(wrap, message) {
		wrap.find('[data-epm-progress]').prop('hidden', true);
		setAudioStatus(wrap, '');
		setAudioBusy(wrap, false);
		showAudioError(wrap, message);
	}

	// Audio URL: check it in place (reachable, format, size).
	/**
	 * Show or clear the error next to the audio URL field.
	 *
	 * @param {jQuery} wrap    Audio box.
	 * @param {string} message Error ('' clears it).
	 */
	function setUrlError(wrap, message) {
		var field = wrap.find('[data-epm-audio-url-input]');
		var error = wrap.find('[data-epm-audio-url-error]');
		error.text(message || '').prop('hidden', !message);
		field.attr('aria-invalid', message ? 'true' : null);
	}

	/**
	 * Check the typed audio URL with the server.
	 *
	 * @param {jQuery} wrap Audio box.
	 */
	function checkAudioUrl(wrap) {
		var field = wrap.find('[data-epm-audio-url-input]');
		var url = $.trim(field.val() || '');

		if (!/^https?:\/\/\S+$/i.test(url)) {
			setUrlError(wrap, strings.urlInvalid);
			field.trigger('focus');
			return;
		}

		setUrlError(wrap, '');
		setAudioBusy(wrap, true);
		setAudioStatus(wrap, strings.checkingUrl);

		$.post(ajaxurl, {
			action: 'epm_audio_url_check',
			_ajax_nonce: epmAdmin.nonce,
			post_id: postId(wrap),
			url: url
		}).done(function (response) {
			if (!response || !response.success || !response.data) {
				setUrlError(wrap, (response && response.data && response.data.message) || strings.urlInvalid);
				field.trigger('focus');
				return;
			}
			var data = response.data;
			data.source = 'external';
			field.val(data.url);
			if (data.warning) {
				setUrlError(wrap, data.warning);
			}
			if (hasMedia(wrap)) {
				updateUrlInactive(wrap);
				speak(strings.mediaWins);
				return;
			}
			renderAudioState(wrap, data);
			speak(sprintf(strings.urlChecked, data.host) + (data.warning ? ' ' + data.warning : ''));
		}).fail(function () {
			setUrlError(wrap, strings.urlCheckFailed);
		}).always(function () {
			setAudioBusy(wrap, false);
			setAudioStatus(wrap, '');
		});
	}

	$(document).on('click', '[data-epm-audio-url-check]', function (e) {
		e.preventDefault();
		checkAudioUrl($(this).closest('[data-epm-upload]'));
	});

	$(document).on('keydown', '[data-epm-audio-url-input]', function (e) {
		// Enter checks the address instead of submitting the whole episode.
		if (e.key === 'Enter') {
			e.preventDefault();
			checkAudioUrl($(this).closest('[data-epm-upload]'));
		}
	});

	$(document).on('input', '[data-epm-audio-url-input]', function () {
		var wrap = $(this).closest('[data-epm-upload]');
		if ($(this).attr('aria-invalid') === 'true') {
			setUrlError(wrap, '');
		}
		updateUrlInactive(wrap);
	});

	// ---------------------------------------------------------------------
	// Episode number: suggest the next free number.
	// ---------------------------------------------------------------------

	/**
	 * Suggested number for the season currently typed.
	 *
	 * @param {Object} next   {all, seasons}.
	 * @param {string} season Season field value.
	 * @return {number} Number.
	 */
	function suggestedNumber(next, season) {
		season = $.trim(season || '');
		if (season !== '' && /^\d+$/.test(season)) {
			var key = String(parseInt(season, 10));
			return (next.seasons && next.seasons[key]) ? Number(next.seasons[key]) : 1;
		}
		return Number(next.all) || 1;
	}

	/**
	 * Show the suggestion while the number field is empty. A typed number
	 * is never replaced.
	 */
	function updateNextNumber() {
		var hint = $('[data-epm-next-hint]');
		if (!hint.length) {
			return;
		}
		var field = $('#epm-episode-number');
		var button = $('[data-epm-next-number]');
		var next = hint.data('epm-next-numbers') || {};
		var season = $.trim($('#epm-season-number').val() || '');
		var number = suggestedNumber(next, season);
		var empty = $.trim(field.val() || '') === '';

		hint.text(
			season !== '' && /^\d+$/.test(season)
				/* translators: 1: season number, 2: suggested episode number */
				? sprintf(__('Next free number in season %1$s: %2$d', 'elementor-podcast-manager'), season, number)
				/* translators: %d: suggested episode number */
				: sprintf(__('Next free number: %d', 'elementor-podcast-manager'), number)
		);
		hint.prop('hidden', !empty);
		button.prop('hidden', !empty).data('epm-number', number);
	}

	$(document).on('click', '[data-epm-next-number]', function (e) {
		e.preventDefault();
		var field = $('#epm-episode-number');
		if ($.trim(field.val() || '') !== '') {
			return;
		}
		var number = $(this).data('epm-number');
		field.val(number).trigger('focus');
		updateNextNumber();
		/* translators: %d: episode number */
		speak(sprintf(__('Episode number set to %d.', 'elementor-podcast-manager'), number));
	});
	$(document).on('input change', '#epm-episode-number, #epm-season-number', updateNextNumber);

	// ---------------------------------------------------------------------
	// Repeaters (chapters, platform and social links).
	// ---------------------------------------------------------------------

	/**
	 * Unique key for a new row (never the row count, so removing and adding
	 * rows can't create duplicate field names).
	 *
	 * @return {string} Key.
	 */
	function repeaterKey() {
		return 'new_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
	}

	/**
	 * Rows of a repeater.
	 *
	 * @param {jQuery} wrap Repeater.
	 * @return {jQuery} Rows.
	 */
	function rowsOf(wrap) {
		return wrap.find('[data-epm-repeat-rows]').first().children('[data-epm-repeat-row]');
	}

	/**
	 * Mark the first row's "up" and the last row's "down" as unavailable.
	 *
	 * @param {jQuery} wrap Repeater.
	 */
	function updateOrderButtons(wrap) {
		var rows = rowsOf(wrap);
		rows.each(function (i) {
			$(this).find('[data-epm-repeat-up]').attr('aria-disabled', i === 0 ? 'true' : 'false');
			$(this).find('[data-epm-repeat-down]').attr('aria-disabled', i === rows.length - 1 ? 'true' : 'false');
		});
		wrap.find('[data-epm-paste-replace-row]').prop('hidden', !rowsWithContent(wrap).length);
	}

	/**
	 * Rows where anything was typed.
	 *
	 * @param {jQuery} wrap Repeater.
	 * @return {jQuery} Rows.
	 */
	function rowsWithContent(wrap) {
		return rowsOf(wrap).filter(function () {
			return $(this).find('input[type="text"], input[type="url"], input:not([type])').filter(function () {
				return $.trim($(this).val() || '') !== '';
			}).length > 0;
		});
	}

	/**
	 * Append a new row from the template.
	 *
	 * @param {jQuery} wrap Repeater.
	 * @return {jQuery} The row.
	 */
	function addRow(wrap) {
		var template = wrap.find('[data-epm-repeat-template]').html();
		var row = $($.parseHTML($.trim(template.replace(/__INDEX__/g, repeaterKey()))));
		wrap.find('[data-epm-repeat-rows]').first().append(row);
		return row.filter('[data-epm-repeat-row]');
	}

	$(document).on('click', '[data-epm-repeat-add]', function (e) {
		e.preventDefault();
		var wrap = $(this).closest('[data-epm-repeat]');
		var row = addRow(wrap);
		updateOrderButtons(wrap);
		row.find('input, select').first().trigger('focus');
		speak(wrap.data('epm-msg-added'));
	});

	$(document).on('click', '[data-epm-repeat-remove]', function (e) {
		e.preventDefault();
		var row = $(this).closest('[data-epm-repeat-row]');
		var wrap = row.closest('[data-epm-repeat]');
		var target = row.next('[data-epm-repeat-row]');
		if (!target.length) {
			target = row.prev('[data-epm-repeat-row]');
		}
		row.remove();
		updateOrderButtons(wrap);
		if (target.length) {
			target.find('input, select').first().trigger('focus');
		} else {
			wrap.find('[data-epm-repeat-add]').trigger('focus');
		}
		speak(wrap.data('epm-msg-removed'));
	});

	/**
	 * Move a row one step up or down and keep focus on the pressed button
	 * (or on its twin once the row reaches an end).
	 *
	 * @param {jQuery} button Pressed button.
	 * @param {number} step   -1 up, 1 down.
	 */
	function moveRow(button, step) {
		if (button.attr('aria-disabled') === 'true') {
			return;
		}
		var row = button.closest('[data-epm-repeat-row]');
		var wrap = row.closest('[data-epm-repeat]');
		var sibling = step < 0 ? row.prev('[data-epm-repeat-row]') : row.next('[data-epm-repeat-row]');
		if (!sibling.length) {
			return;
		}
		if (step < 0) {
			row.insertBefore(sibling);
		} else {
			row.insertAfter(sibling);
		}
		updateOrderButtons(wrap);

		var focus = button.attr('aria-disabled') === 'true'
			? row.find(step < 0 ? '[data-epm-repeat-down]' : '[data-epm-repeat-up]')
			: button;
		focus.trigger('focus');

		var rows = rowsOf(wrap);
		speak(sprintf(String(wrap.data('epm-msg-moved') || ''), rows.index(row) + 1, rows.length));
	}

	$(document).on('click', '[data-epm-repeat-up]', function (e) {
		e.preventDefault();
		moveRow($(this), -1);
	});
	$(document).on('click', '[data-epm-repeat-down]', function (e) {
		e.preventDefault();
		moveRow($(this), 1);
	});

	/**
	 * Check one row: a row with any value needs all its required fields.
	 *
	 * @param {jQuery} row Row.
	 * @return {jQuery} First invalid field (empty when the row is fine).
	 */
	function validateRow(row) {
		var inputs = row.find('input[type="text"], input[type="url"]');
		var filled = inputs.filter(function () {
			return $.trim($(this).val() || '') !== '';
		}).length > 0;
		var messages = [];
		var firstInvalid = $();

		row.find('[data-epm-required]').each(function () {
			var field = $(this);
			var missing = filled && $.trim(field.val() || '') === '';
			field.attr('aria-invalid', missing ? 'true' : null);
			if (missing) {
				messages.push(field.data('epm-error'));
				if (!firstInvalid.length) {
					firstInvalid = field;
				}
			}
		});

		var error = row.find('[data-epm-repeat-error]');
		var errorId = error.attr('id');
		row.toggleClass('is-invalid', messages.length > 0);
		error.text(messages.join(' ')).prop('hidden', messages.length === 0);
		row.find('[data-epm-required]').each(function () {
			var field = $(this);
			var ids = String(field.attr('aria-describedby') || '').split(/\s+/).filter(function (id) {
				return id && id !== errorId;
			});
			if (field.attr('aria-invalid') === 'true' && errorId) {
				ids.push(errorId);
			}
			field.attr('aria-describedby', ids.length ? ids.join(' ') : null);
		});

		return firstInvalid;
	}

	// Once a row was flagged, re-check it while the user fixes it.
	$(document).on('input', '[data-epm-repeat-row].is-invalid input', function () {
		validateRow($(this).closest('[data-epm-repeat-row]'));
	});

	/**
	 * Stop the save when a row is half filled, and say where. Bound directly
	 * on the form at load, so it runs before the handler WordPress adds when
	 * a submit button is clicked (which disables the buttons and shows the
	 * spinner unless the event was already prevented).
	 *
	 * @param {Event} e Submit event.
	 */
	function validateRepeatersOnSubmit(e) {
		var firstInvalid = $();
		$(this).find('[data-epm-repeat-row]').each(function () {
			if ($(this).closest('template').length) {
				return;
			}
			var invalid = validateRow($(this));
			if (!firstInvalid.length && invalid.length) {
				firstInvalid = invalid;
			}
		});
		if (!firstInvalid.length) {
			return;
		}
		e.preventDefault();
		e.stopImmediatePropagation();
		revealField(firstInvalid);
		firstInvalid.trigger('focus');
		speak(strings.fixField, 'assertive');
	}

	/**
	 * Make a field visible: open its meta box and disclosure.
	 *
	 * @param {jQuery} field Field.
	 */
	function revealField(field) {
		field.closest('.postbox.closed').removeClass('closed').find('.handlediv').attr('aria-expanded', 'true');
		field.parents('details').prop('open', true);
	}

	// Links in notices ("Go to the chapters") focus the field itself.
	$(document).on('click', '[data-epm-focus-field]', function (e) {
		var id = String($(this).attr('href') || '').replace(/^#/, '');
		var target = id ? $(document.getElementById(id)) : $();
		if (!target.length) {
			return;
		}
		e.preventDefault();
		revealField(target);
		var focusable = target.is(':input') ? target : target.find('[aria-invalid="true"], input, select, textarea, button').filter(':visible').first();
		if (target[0].scrollIntoView) {
			target[0].scrollIntoView({ block: 'center' });
		}
		(focusable.length ? focusable : target.attr('tabindex', '-1')).trigger('focus');
	});

	// ---------------------------------------------------------------------
	// Paste chapters.
	// ---------------------------------------------------------------------

	var TIME = '((?:\\d{1,3}:)?\\d{1,3}:\\d{2})(?:[.,]\\d{1,3})?';
	var TIME_FIRST = new RegExp('^(?:[-*•·–—]\\s*)?[\\[(]?' + TIME + '[\\])]?\\s*(?:[-–—:|.]+\\s*)?(.*)$');
	var TIME_LAST = new RegExp('^(?:[-*•·–—]\\s*)?(.*?)\\s*(?:[-–—:|]+\\s*)?[\\[(]?' + TIME + '[\\])]?$');

	/**
	 * Seconds of a h:mm:ss / mm:ss time.
	 *
	 * @param {string} time Time.
	 * @return {number} Seconds, or -1 when minutes or seconds exceed 59.
	 */
	function timeToSeconds(time) {
		var parts = time.split(':').map(Number);
		var seconds = parts.pop();
		var minutes = parts.pop() || 0;
		var hours = parts.pop() || 0;
		if (seconds > 59 || (hours > 0 && minutes > 59)) {
			return -1;
		}
		return hours * 3600 + minutes * 60 + seconds;
	}

	/**
	 * Canonical time: m:ss or h:mm:ss.
	 *
	 * @param {number} total Seconds.
	 * @return {string} Time.
	 */
	function formatTime(total) {
		var h = Math.floor(total / 3600);
		var m = Math.floor((total % 3600) / 60);
		var s = total % 60;
		var pad = function (n) {
			return (n < 10 ? '0' : '') + n;
		};
		return h > 0 ? h + ':' + pad(m) + ':' + pad(s) : m + ':' + pad(s);
	}

	/**
	 * Read chapters from pasted text. Accepts "00:00 Intro",
	 * "1:02:03 - Topic", "(12:30) Q&A", "[12:30] Title", "12:30 – Title",
	 * bullets, and the time at the end of the line ("Intro 00:00"). A URL at
	 * the end of a title becomes the chapter link.
	 *
	 * @param {string} text Pasted text.
	 * @return {{chapters: Array, skipped: Array}} Result.
	 */
	function parseChapters(text) {
		var chapters = [];
		var skipped = [];

		String(text || '').split(/\r?\n/).forEach(function (raw) {
			var line = $.trim(raw.replace(/ /g, ' '));
			if (!line) {
				return;
			}
			var match = line.match(TIME_FIRST);
			var time;
			var title;
			if (match && $.trim(match[2])) {
				time = match[1];
				title = match[2];
			} else {
				match = line.match(TIME_LAST);
				if (match && $.trim(match[1])) {
					time = match[2];
					title = match[1];
				}
			}
			var seconds = time ? timeToSeconds(time) : -1;
			if (!time || seconds < 0) {
				skipped.push(line);
				return;
			}
			title = $.trim(title).replace(/^[-–—:|.\s]+|[-–—:|\s]+$/g, '');
			var url = '';
			var link = title.match(/\s*(https?:\/\/\S+)$/i);
			if (link) {
				url = link[1];
				title = $.trim(title.slice(0, link.index)).replace(/[-–—:|\s]+$/g, '');
			}
			if (!title) {
				skipped.push(line);
				return;
			}
			chapters.push({ time: formatTime(seconds), seconds: seconds, title: title, url: url });
		});

		return { chapters: chapters, skipped: skipped };
	}

	$(document).on('click', '[data-epm-paste-apply]', function (e) {
		e.preventDefault();
		var paste = $(this).closest('[data-epm-paste-chapters]');
		var wrap = paste.closest('[data-epm-repeat]');
		var textarea = paste.find('[data-epm-paste-text]');
		var result = paste.find('[data-epm-paste-result]');
		var parsed = parseChapters(textarea.val());

		result.empty();

		if (!parsed.chapters.length) {
			result.append($('<p class="epm-field-error" />').text(__('No chapters found. Start each line with a time, for example “12:30 Listener questions”.', 'elementor-podcast-manager')));
			textarea.attr('aria-invalid', 'true').trigger('focus');
			return;
		}
		textarea.attr('aria-invalid', null);

		var replace = paste.find('[data-epm-paste-replace]').prop('checked');
		var existing = rowsWithContent(wrap);
		if (replace || !existing.length) {
			rowsOf(wrap).remove();
		}

		parsed.chapters.forEach(function (chapter) {
			var row = addRow(wrap);
			row.find('input[name$="[time]"]').val(chapter.time);
			row.find('input[name$="[title]"]').val(chapter.title);
			row.find('input[name$="[url]"]').val(chapter.url);
		});
		updateOrderButtons(wrap);

		var added = sprintf(
			/* translators: %d: number of chapters */
			_n('Added %d chapter.', 'Added %d chapters.', parsed.chapters.length, 'elementor-podcast-manager'),
			parsed.chapters.length
		);
		var messages = [added];
		result.append($('<p class="epm-paste__ok" />').text(added));

		if (parsed.skipped.length) {
			var skippedText = sprintf(
				/* translators: %d: number of lines */
				_n('%d line was skipped because it has no start time and title:', '%d lines were skipped because they have no start time and title:', parsed.skipped.length, 'elementor-podcast-manager'),
				parsed.skipped.length
			);
			var list = $('<ul class="epm-paste__skipped" />');
			parsed.skipped.slice(0, 8).forEach(function (line) {
				list.append($('<li />').append($('<code />').text(line)));
			});
			if (parsed.skipped.length > 8) {
				/* translators: %d: number of further lines */
				list.append($('<li />').text(sprintf(__('and %d more', 'elementor-podcast-manager'), parsed.skipped.length - 8)));
			}
			result.append($('<p class="epm-paste__warn" />').text(skippedText), list);
			messages.push(skippedText);
		}

		var hints = [];
		if (parsed.chapters[0].seconds !== 0) {
			hints.push(__('The first chapter usually starts at 0:00. Add an intro chapter at 0:00 if the list starts later.', 'elementor-podcast-manager'));
		}
		for (var i = 1; i < parsed.chapters.length; i++) {
			if (parsed.chapters[i].seconds <= parsed.chapters[i - 1].seconds) {
				hints.push(sprintf(
					/* translators: 1: chapter title, 2: start time */
					__('Chapter times should go up. “%1$s” at %2$s doesn’t start after the chapter above it: check its time.', 'elementor-podcast-manager'),
					parsed.chapters[i].title,
					parsed.chapters[i].time
				));
				break;
			}
		}
		hints.forEach(function (hint) {
			result.append($('<p class="epm-paste__hint" />').text(hint));
		});

		if (!parsed.skipped.length) {
			textarea.val('');
		}
		speak(messages.concat(hints).join(' '));
	});

	// ---------------------------------------------------------------------
	// Transcript file (.vtt / .srt).
	// ---------------------------------------------------------------------

	var TRANSCRIPT_FORMATS = { vtt: 'WebVTT', srt: 'SubRip' };

	$(document).on('click', '[data-epm-transcript-choose]', function (e) {
		e.preventDefault();
		var button = $(this);
		var box = button.closest('[data-epm-transcript-file]');
		var error = box.find('[data-epm-transcript-error]');

		mediaFrame(
			__('Choose a transcript file', 'elementor-podcast-manager'),
			['text/vtt', 'application/x-subrip'],
			__('Use this transcript', 'elementor-podcast-manager'),
			function (attachment) {
				var name = String(attachment.filename || attachment.url || '');
				var ext = (name.split('.').pop() || '').toLowerCase();
				if (!TRANSCRIPT_FORMATS[ext]) {
					error.text(__('Choose a WebVTT (.vtt) or SubRip (.srt) file. Other file types can’t be used for captions.', 'elementor-podcast-manager')).prop('hidden', false);
					speak(error.text(), 'assertive');
					return;
				}
				error.prop('hidden', true).empty();
				box.find('[data-epm-transcript-id]').val(attachment.id);
				box.find('[data-epm-transcript-name]').text(name).attr('href', attachment.url);
				box.find('[data-epm-transcript-type]').text(TRANSCRIPT_FORMATS[ext]);
				box.find('[data-epm-transcript-current]').prop('hidden', false);
				box.find('[data-epm-transcript-remove]').prop('hidden', false);
				button.text(button.data('replace-label'));
				/* translators: %s: file name */
				speak(sprintf(__('Transcript file selected: %s. Save the episode to keep it.', 'elementor-podcast-manager'), name));
			}
		);
	});

	$(document).on('click', '[data-epm-transcript-remove]', function (e) {
		e.preventDefault();
		var box = $(this).closest('[data-epm-transcript-file]');
		var choose = box.find('[data-epm-transcript-choose]');
		box.find('[data-epm-transcript-id]').val('');
		box.find('[data-epm-transcript-current]').prop('hidden', true);
		$(this).prop('hidden', true);
		choose.text(choose.data('choose-label')).trigger('focus');
		speak(__('Transcript file removed. Save the episode to keep this change.', 'elementor-podcast-manager'));
	});

	// ---------------------------------------------------------------------
	// Copy buttons (feed URL). The label cross-fades to "Copied".
	// ---------------------------------------------------------------------

	/**
	 * Select the visible value next to a copy button, so the user can copy
	 * it with the keyboard.
	 *
	 * @param {jQuery} button Copy button.
	 */
	function selectCopyValue(button) {
		var value = button.closest('.epm-copy').find('.epm-copy__value').get(0);
		if (!value || !window.getSelection) {
			return;
		}
		var range = document.createRange();
		range.selectNodeContents(value);
		var selection = window.getSelection();
		selection.removeAllRanges();
		selection.addRange(range);
	}

	$(document).on('click', '[data-epm-copy]', function (e) {
		e.preventDefault();
		var button = $(this);
		var text = String(button.attr('data-epm-copy') || '');

		var done = function () {
			window.clearTimeout(button.data('epm-copy-timer'));
			button.addClass('is-copied');
			button.data('epm-copy-timer', window.setTimeout(function () {
				button.removeClass('is-copied');
			}, 2000));
			speak(button.attr('data-epm-copied-message') || strings.copiedMessage);
		};
		var failed = function () {
			selectCopyValue(button);
			speak(strings.copyFailed, 'assertive');
		};
		var legacy = function () {
			var field = $('<textarea readonly></textarea>').val(text).css({ position: 'fixed', insetInlineStart: '-9999px', top: 0 }).appendTo(document.body);
			field[0].select();
			var ok = false;
			try {
				ok = document.execCommand('copy');
			} catch (err) {
				ok = false;
			}
			field.remove();
			button.trigger('focus');
			if (ok) {
				done();
			} else {
				failed();
			}
		};

		if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done, legacy);
			return;
		}
		legacy();
	});

	// ---------------------------------------------------------------------
	// Setup reminder ("Skip for now" / "Dismiss").
	// ---------------------------------------------------------------------

	$(document).on('click', '[data-epm-setup-dismiss]', function (e) {
		e.preventDefault();
		var button = $(this);
		var box = button.closest('[data-epm-setup-notice], [data-epm-setup-card]');
		button.prop('disabled', true);

		var failedMessage = __('The reminder could not be hidden. Reload the page and try again.', 'elementor-podcast-manager');

		$.post(ajaxurl, {
			action: 'epm_setup_dismiss',
			nonce: button.data('nonce') || epmAdmin.setupNonce
		}).done(function (response) {
			if (!response || !response.success) {
				button.prop('disabled', false);
				speak((response && response.data && response.data.message) || failedMessage, 'assertive');
				return;
			}
			var heading = $('.wrap h1').first();
			var finish = function () {
				box.remove();
				$('[data-epm-setup-notice], [data-epm-setup-card]').remove();
				heading.attr('tabindex', '-1').trigger('focus');
				speak(strings.setupDismissed);
			};
			if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: no-preference)').matches) {
				box.addClass('is-leaving');
				window.setTimeout(finish, 160);
			} else {
				finish();
			}
		}).fail(function (jqXHR) {
			button.prop('disabled', false);
			var message = jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message;
			speak(message || failedMessage, 'assertive');
		});
	});

	// ---------------------------------------------------------------------
	// Quick Edit in the episode list: fill the episode fields.
	// ---------------------------------------------------------------------

	/**
	 * Wrap core's inlineEditPost.edit() to fill the plugin's fields from
	 * the row's .epm-inline-data element.
	 */
	function initQuickEdit() {
		if (!window.inlineEditPost || !$('body').hasClass('post-type-podcast_episode')) {
			return;
		}
		var original = window.inlineEditPost.edit;
		window.inlineEditPost.edit = function (id) {
			var result = original.apply(this, arguments);
			var postIdValue = typeof id === 'object' ? parseInt(this.getId(id), 10) : parseInt(id, 10);
			if (!postIdValue) {
				return result;
			}
			var data = $('#epm-inline-' + postIdValue);
			var row = $('#edit-' + postIdValue);
			if (!data.length || !row.length) {
				return result;
			}
			row.find('[data-epm-quick="number"]').val(data.attr('data-number') || '');
			row.find('[data-epm-quick="season"]').val(data.attr('data-season') || '');
			row.find('[data-epm-quick="type"]').val(data.attr('data-type') || 'full');
			row.find('[data-epm-quick="explicit"]').val(data.attr('data-explicit') || 'inherit');
			return result;
		};
	}

	$(function () {
		$('[data-epm-repeat]').each(function () {
			updateOrderButtons($(this));
		});
		$('#post, .epm-settings form').on('submit', validateRepeatersOnSubmit);
		updateNextNumber();
		initQuickEdit();
	});
})(jQuery);
