/**
 * EPM admin JS: media pickers, audio drop zone, chapter repeaters, copy buttons.
 */
(function ($) {
	'use strict';

	function mediaFrame(title, type, onSelect) {
		var frame = wp.media({
			title: title,
			button: { text: epmAdmin.use },
			library: type ? { type: type } : {},
			multiple: false
		});
		frame.on('select', function () {
			var attachment = frame.state().get('selection').first().toJSON();
			onSelect(attachment);
		});
		frame.open();
	}

	// Generic image pickers (artwork, guest image, settings).
	$(document).on('click', '[data-epm-media-choose]', function (e) {
		e.preventDefault();
		var wrap = $(this).closest('[data-epm-media]');
		mediaFrame($(this).data('title') || epmAdmin.choose, 'image', function (attachment) {
			wrap.find('[data-epm-media-id]').val(attachment.id);
			var url = (attachment.sizes && attachment.sizes.thumbnail)
				? attachment.sizes.thumbnail.url
				: attachment.url;
			wrap.find('[data-epm-media-preview]').html(
				'<img src="' + url + '" alt="" class="epm-media-preview__img" />'
			);
		});
	});

	$(document).on('click', '[data-epm-media-remove]', function (e) {
		e.preventDefault();
		var wrap = $(this).closest('[data-epm-media]');
		wrap.find('[data-epm-media-id]').val('');
		wrap.find('[data-epm-media-preview]').empty();
	});

	// Audio picker: select from the Media Library, update the box in place.
	// The attachment ID is stored in the hidden input and persists through
	// the normal secured post-save workflow. No page reload.
	$(document).on('click', '[data-epm-choose-audio]', function (e) {
		e.preventDefault();
		var wrap = $(this).closest('[data-epm-upload]');
		mediaFrame(epmAdmin.choose, 'audio', function (attachment) {
			setAudioFromAttachment(wrap, attachment.id);
		});
	});

	$(document).on('click', '[data-epm-remove-audio]', function (e) {
		e.preventDefault();
		var wrap = $(this).closest('[data-epm-upload]');
		wrap.find('[data-epm-audio-id]').val('');
		renderAudioState(wrap, null);
	});

	/**
	 * Fetch attachment details (nonce + post-level authorization enforced
	 * server-side) and render the audio state in place.
	 */
	function setAudioFromAttachment(wrap, attachmentId) {
		showAudioError(wrap, '');
		setAudioBusy(wrap, true);
		$.post(ajaxurl, {
			action: 'epm_audio_describe',
			_ajax_nonce: epmAdmin.nonce,
			post_id: $('#post_ID').val() || wrap.data('epm-post-id') || 0,
			attachment_id: attachmentId
		}).done(function (response) {
			if (response && response.success && response.data) {
				wrap.find('[data-epm-audio-id]').val(response.data.id);
				renderAudioState(wrap, response.data);
			} else {
				showAudioError(wrap, (response && response.data && response.data.message) || epmAdmin.strings.describeFailed);
			}
		}).fail(function () {
			showAudioError(wrap, epmAdmin.strings.describeFailed);
		}).always(function () {
			setAudioBusy(wrap, false);
		});
	}

	function setAudioBusy(wrap, busy) {
		wrap.find('[data-epm-choose-audio], [data-epm-remove-audio]').prop('disabled', busy);
	}

	function showAudioError(wrap, message) {
		var box = wrap.find('[data-epm-error]');
		if (!message) {
			box.attr('hidden', true).empty();
			return;
		}
		box.removeAttr('hidden').text(message);
	}

	function escapeHtml(text) {
		return String(text == null ? '' : text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	/**
	 * Render the audio box state in place (file info + preview, or empty).
	 */
	function renderAudioState(wrap, data) {
		var state = wrap.find('[data-epm-audio-state]');
		var chooseBtn = wrap.find('[data-epm-choose-audio]');
		var removeBtn = wrap.find('[data-epm-remove-audio]');

		if (!data) {
			state.html('<p class="epm-upload__hint">' + escapeHtml(epmAdmin.strings.dropHint) + '</p>');
			chooseBtn.text(epmAdmin.strings.uploadAudio);
			removeBtn.remove();
			return;
		}

		var meta = [data.duration, data.size_formatted, data.mime].filter(Boolean).join(' ');
		var html = '<p class="epm-upload__file"><strong>' + escapeHtml(data.filename) + '</strong><br />' +
			'<span class="epm-upload__meta">' + escapeHtml(meta) + '</span>';
		if (!data.is_distribution) {
			html += '<br /><span class="epm-upload__warning">' + escapeHtml(epmAdmin.strings.wavWarning) + '</span>';
		}
		html += '</p>';
		if (data.url) {
			html += '<audio controls preload="none" src="' + escapeHtml(data.url) + '" class="epm-upload__preview"></audio>';
		}
		state.html(html);
		chooseBtn.text(epmAdmin.strings.replaceAudio);
		if (!removeBtn.length) {
			chooseBtn.after(' <button type="button" class="button" data-epm-remove-audio>' + escapeHtml(epmAdmin.strings.remove) + '</button>');
		}
	}

	// Drag & drop for audio files, with progress and in-place update.
	$(document).on('dragover dragenter', '[data-epm-drop]', function (e) {
		e.preventDefault();
		$(this).addClass('is-dragover');
	});
	$(document).on('dragleave dragend drop', '[data-epm-drop]', function (e) {
		e.preventDefault();
		$(this).removeClass('is-dragover');
	});
	$(document).on('drop', '[data-epm-drop]', function (e) {
		var files = e.originalEvent.dataTransfer.files;
		if (!files || !files.length) {
			return;
		}
		var file = files[0];
		var wrap = $(this).closest('[data-epm-upload]');
		if (!/audio\/(mpeg|mp4|x-m4a|wav)/.test(file.type || '')) {
			showAudioError(wrap, epmAdmin.strings.invalidType);
			return;
		}
		uploadAudioFile(wrap, file);
	});

	function uploadAudioFile(wrap, file) {
		showAudioError(wrap, '');
		var progress = wrap.find('[data-epm-progress]');
		var bar = wrap.find('[data-epm-progress-bar]');
		var label = wrap.find('[data-epm-progress-label]');
		progress.removeAttr('hidden');
		bar.css('width', '0%');
		label.text(epmAdmin.strings.uploading);
		setAudioBusy(wrap, true);

		var data = new FormData();
		data.append('file', file);
		data.append('action', 'epm_upload_audio');
		data.append('_ajax_nonce', epmAdmin.nonce);
		data.append('post_id', $('#post_ID').val() || wrap.data('epm-post-id') || 0);

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
						if (evt.lengthComputable) {
							var pct = Math.round((evt.loaded / evt.total) * 100);
							bar.css('width', pct + '%');
							label.text(epmAdmin.strings.uploading + ' ' + pct + '%');
						}
					});
				}
				return xhr;
			}
		}).done(function (response) {
			if (response && response.success && response.data && response.data.id) {
				setAudioFromAttachment(wrap, response.data.id);
			} else {
				showAudioError(wrap, (response && response.data && response.data.message) || epmAdmin.strings.uploadFailed);
			}
		}).fail(function (jqXHR) {
			var message = epmAdmin.strings.uploadFailed;
			if (jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
				message = jqXHR.responseJSON.data.message;
			}
			showAudioError(wrap, message);
		}).always(function () {
			progress.attr('hidden', true);
			setAudioBusy(wrap, false);
		});
	}

	// Chapter / link repeaters: stable unique keys, accessible reordering.
	// New rows use a unique key (never the row count) so deleting a row and
	// adding new ones can never create duplicate field names. PHP accepts
	// string keys and normalizes rows sequentially on save.
	function repeaterKey() {
		return 'new_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
	}
	$(document).on('click', '[data-epm-repeat-add]', function (e) {
		e.preventDefault();
		var wrap = $(this).closest('[data-epm-repeat]');
		var template = wrap.find('[data-epm-repeat-template]').html();
		wrap.find('[data-epm-repeat-rows]').append(template.replace(/__INDEX__/g, repeaterKey()));
	});
	$(document).on('click', '[data-epm-repeat-remove]', function (e) {
		e.preventDefault();
		var row = $(this).closest('[data-epm-repeat-row]');
		var wrap = row.closest('[data-epm-repeat]');
		row.remove();
		// Keep keyboard focus in a sensible place after removal.
		wrap.find('[data-epm-repeat-add]').focus();
	});
	$(document).on('click', '[data-epm-repeat-up]', function (e) {
		e.preventDefault();
		var row = $(this).closest('[data-epm-repeat-row]');
		var prev = row.prev('[data-epm-repeat-row]');
		if (prev.length) {
			row.insertBefore(prev);
			row.find('[data-epm-repeat-up]').focus();
		}
	});
	$(document).on('click', '[data-epm-repeat-down]', function (e) {
		e.preventDefault();
		var row = $(this).closest('[data-epm-repeat-row]');
		var next = row.next('[data-epm-repeat-row]');
		if (next.length) {
			row.insertAfter(next);
			row.find('[data-epm-repeat-down]').focus();
		}
	});

	// Copy buttons (feed URL). Falls back to selecting a temporary field
	// where the async clipboard API is unavailable (non-HTTPS admin).
	$(document).on('click', '[data-epm-copy]', function (e) {
		e.preventDefault();
		var text = String($(this).data('epm-copy'));
		var btn = $(this);
		var label = btn.data('epm-label') || btn.text();
		btn.data('epm-label', label);

		var done = function () {
			btn.text(label + ' ✓');
			window.setTimeout(function () {
				btn.text(label);
			}, 2000);
		};

		if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(done);
			return;
		}

		var field = $('<textarea readonly></textarea>').val(text).css({ position: 'fixed', left: '-9999px' }).appendTo(document.body);
		field[0].select();
		try {
			if (document.execCommand('copy')) {
				done();
			}
		} catch (err) { /* copying is best-effort */ }
		field.remove();
	});
})(jQuery);
