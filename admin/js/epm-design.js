/**
 * Design screen: live preview, preset gallery and contrast check.
 *
 * window.epmDesign comes from EPM\Admin::design_preview_config(): the
 * table of design tokens (which --epm-* variable each one sets and how its
 * value is written), the saved values, every preset's values and details,
 * the details shown by default (built-in and the site's) and the
 * dark-background rule. Nothing here is saved: the token form saves
 * through options.php, the details form and applying a preset are POSTs.
 *
 * The preview renders the saved details (the server renders it exactly
 * as the site does). Previewing a preset shows its looks and layouts and
 * lists the details it would change; the confirmation dialog lists them
 * again.
 */
(function () {
	'use strict';

	var config = window.epmDesign;
	var root = document.querySelector('[data-epm-design]');
	if (!config || !root || !window.wp || !wp.i18n) {
		return;
	}

	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;

	var form = root.querySelector('[data-epm-design-form]');
	var presetForm = root.querySelector('[data-epm-preset-form]');
	var canvas = root.querySelector('[data-epm-preview-canvas]');
	var statusEl = root.querySelector('[data-epm-preview-status]');
	var presetNote = root.querySelector('[data-epm-preview-preset]');
	var presetNoteText = root.querySelector('[data-epm-preview-preset-text]');
	var dirtyEl = root.querySelector('[data-epm-dirty]');
	var resetButton = root.querySelector('[data-epm-design-reset]');
	var dialog = root.querySelector('[data-epm-preset-dialog]');
	var applyButton = root.querySelector('[data-epm-preset-apply]');
	var detailsForm = root.querySelector('[data-epm-details-form]');
	var detailsDirtyEl = root.querySelector('[data-epm-details-dirty]');
	var detailsConfig = config.details || { neutral: {}, site: {}, labels: {}, places: {} };
	var detailsDirty = false;

	if (!form || !canvas) {
		return;
	}

	var map = config.map || {};
	var lastValid = Object.assign({}, config.values || {});
	var activeId = String(config.active || '');
	var mode = 'fields';
	var dirty = false;
	var confirmed = false;
	var contrastState = {};
	var decimal = new Intl.NumberFormat(document.documentElement.lang || undefined, {
		minimumFractionDigits: 1,
		maximumFractionDigits: 1
	});

	// Every variable the preview may set, so stale ones can be removed.
	var managedVars = Object.keys(map).map(function (key) {
		return map[key]['var'];
	}).filter(Boolean).concat(Object.keys((config.dark && config.dark.vars) || {}));

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
	 * Canonical #rrggbb, or '' when the value is not a hex color.
	 *
	 * @param {string} value Typed value (# optional, 3 or 6 digits).
	 * @return {string} Color.
	 */
	function normalizeHex(value) {
		var hex = String(value == null ? '' : value).trim().toLowerCase();
		if (hex && hex.charAt(0) !== '#') {
			hex = '#' + hex;
		}
		if (/^#[0-9a-f]{3}$/.test(hex)) {
			hex = '#' + hex[1] + hex[1] + hex[2] + hex[2] + hex[3] + hex[3];
		}
		return /^#[0-9a-f]{6}$/.test(hex) ? hex : '';
	}

	/**
	 * WCAG relative luminance.
	 *
	 * @param {string} hex #rrggbb.
	 * @return {number} Luminance 0..1.
	 */
	function luminance(hex) {
		var channels = [1, 3, 5].map(function (i) {
			var c = parseInt(hex.substr(i, 2), 16) / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
		});
		return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
	}

	/**
	 * WCAG contrast ratio.
	 *
	 * @param {string} a Color.
	 * @param {string} b Color.
	 * @return {number} Ratio 1..21.
	 */
	function contrastRatio(a, b) {
		if (!a || !b) {
			return 1;
		}
		var la = luminance(a);
		var lb = luminance(b);
		return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
	}

	/**
	 * Form controls of a token.
	 *
	 * @param {string} key Token key.
	 * @return {NodeList} Controls.
	 */
	function controlsOf(key) {
		return form.querySelectorAll('[data-epm-token="' + key + '"]');
	}

	/**
	 * Current values in the form. An invalid field keeps its last valid
	 * value, so the preview never shows a broken state.
	 *
	 * @return {Object} Token key => value.
	 */
	function readForm() {
		var values = {};
		Object.keys(map).forEach(function (key) {
			var entry = map[key];
			var controls = controlsOf(key);
			var value;

			if (!controls.length) {
				values[key] = lastValid[key];
				return;
			}

			if (entry.kind === 'choice') {
				var checked = form.querySelector('[data-epm-token="' + key + '"]:checked');
				value = checked ? checked.value : lastValid[key];
			} else if (entry.kind === 'color') {
				var raw = String(controls[0].value || '').trim();
				if (entry.optional && raw === '') {
					value = '';
				} else {
					value = normalizeHex(raw) || lastValid[key];
				}
			} else if (entry.kind === 'px') {
				var number = controls[0].value;
				value = isPixelValue(number) ? String(parseInt(number, 10)) : lastValid[key];
			} else {
				value = controls[0].value;
			}

			values[key] = value;
			lastValid[key] = value;
		});
		return values;
	}

	/**
	 * Whether a number field holds a whole number from 0 to 200.
	 *
	 * @param {string} value Field value.
	 * @return {boolean} Valid.
	 */
	function isPixelValue(value) {
		return /^\d{1,3}$/.test(String(value).trim()) && Number(value) <= 200;
	}

	/**
	 * CSS custom properties for a set of values. Mirrors
	 * EPM\Admin::design_css_vars() (same table, same rules).
	 *
	 * @param {Object} values Token key => value.
	 * @return {Object} Property => value.
	 */
	function cssVars(values) {
		var vars = {};
		Object.keys(map).forEach(function (key) {
			var entry = map[key];
			if (!entry['var']) {
				return;
			}
			var value = String(values[key] == null ? '' : values[key]);
			var css = '';
			if (entry.kind === 'color') {
				css = normalizeHex(value);
			} else if (entry.kind === 'px') {
				css = value === '' ? '' : parseInt(value, 10) + 'px';
			} else if (entry.kind === 'choice') {
				if (Object.prototype.hasOwnProperty.call(entry.values, value)) {
					css = entry.values[value];
				} else {
					css = entry.values[entry.fallback] || '';
				}
			}
			if (css !== '') {
				vars[entry['var']] = css;
			}
		});

		var background = normalizeHex(values.background);
		if (background && config.dark && luminance(background) < config.dark.luminance) {
			Object.keys(config.dark.vars).forEach(function (name) {
				vars[name] = config.dark.vars[name];
			});
		}
		return vars;
	}

	/**
	 * Replace the modifier classes of an element.
	 *
	 * @param {Element} el       Element.
	 * @param {string}  prefix   Class prefix, e.g. "epm-player--".
	 * @param {Array}   modifiers Classes to add.
	 */
	function setModifiers(el, prefix, modifiers) {
		if (!el) {
			return;
		}
		Array.prototype.slice.call(el.classList).forEach(function (name) {
			if (name.indexOf(prefix) === 0) {
				el.classList.remove(name);
			}
		});
		modifiers.forEach(function (name) {
			el.classList.add(name);
		});
	}

	/**
	 * Every detail of every context: built-in defaults with a sparse map
	 * of choices on top (the site's, or a preset's).
	 *
	 * @param {Object} sparse Context => detail => shown.
	 * @return {Object} Context => detail => shown.
	 */
	function effectiveDetails(sparse) {
		var out = {};
		Object.keys(detailsConfig.neutral || {}).forEach(function (context) {
			out[context] = Object.assign({}, detailsConfig.neutral[context], (sparse && sparse[context]) || {});
		});
		return out;
	}

	/**
	 * What a preset's details change on the site, one entry per detail
	 * and new state, with the places it changes in.
	 *
	 * @param {Object} sparse The preset's details.
	 * @return {Array} [{ label, shown, places: [] }].
	 */
	function detailChanges(sparse) {
		var now = effectiveDetails(detailsConfig.site);
		var after = effectiveDetails(sparse);
		var byKey = {};
		var order = [];
		Object.keys(after).forEach(function (context) {
			Object.keys(after[context]).forEach(function (flag) {
				if (after[context][flag] === now[context][flag]) {
					return;
				}
				var key = flag + (after[context][flag] ? ':1' : ':0');
				if (!byKey[key]) {
					byKey[key] = { label: (detailsConfig.labels || {})[flag] || flag, shown: after[context][flag], places: [] };
					order.push(key);
				}
				byKey[key].places.push((detailsConfig.places || {})[context] || context);
			});
		});
		return order.map(function (key) {
			return byKey[key];
		});
	}

	/**
	 * A list of names, joined the way the page's language does.
	 *
	 * @param {Array} names Names.
	 * @return {string} Text.
	 */
	function listOf(names) {
		try {
			return new Intl.ListFormat(document.documentElement.lang || undefined, { type: 'conjunction' }).format(names);
		} catch (e) {
			return names.join(', ');
		}
	}

	/**
	 * One detail change as a sentence.
	 *
	 * @param {Object} change From detailChanges().
	 * @return {string} Text.
	 */
	function describeChange(change) {
		var format;

		if (change.shown) {
			/* translators: 1: detail, e.g. Volume slider, 2: where, e.g. Player and Episode pages */
			format = __('%1$s shown: %2$s', 'elementor-podcast-manager');
		} else {
			/* translators: 1: detail, e.g. Volume slider, 2: where, e.g. Player and Episode pages */
			format = __('%1$s hidden: %2$s', 'elementor-podcast-manager');
		}

		return sprintf(format, change.label, listOf(change.places));
	}

	/**
	 * The episode page's player layout: Minimal and Compact become Full
	 * while it shows a control those layouts hide (as on the site).
	 *
	 * @param {string} layout  Player layout.
	 * @param {Object} details Every detail of every context.
	 * @return {string} Layout.
	 */
	function episodePageLayout(layout, details) {
		if (layout !== 'minimal' && layout !== 'compact') {
			return layout;
		}
		var page = details.episode_page || {};
		return (config.episodePageFull || []).some(function (flag) {
			return !!page[flag];
		}) ? 'full' : layout;
	}

	/**
	 * Show a set of values in the preview.
	 *
	 * @param {Object} values  Token key => value.
	 * @param {Object} details Sparse details (the site's when omitted).
	 */
	function applyPreview(values, details) {
		var vars = cssVars(values);
		managedVars.forEach(function (name) {
			if (Object.prototype.hasOwnProperty.call(vars, name)) {
				canvas.style.setProperty(name, vars[name]);
			} else {
				canvas.style.removeProperty(name);
			}
		});

		var font = map.font_family && map.font_family.values ? map.font_family.values[values.font_family] : '';
		canvas.classList.toggle('has-custom-font', !!font);

		var all = effectiveDetails(details === undefined ? detailsConfig.site : details);
		canvas.querySelectorAll('[data-epm-preview-part="player"] .epm-player').forEach(function (el) {
			setModifiers(el, 'epm-player--', ['epm-player--' + values.default_player_layout]);
		});
		canvas.querySelectorAll('[data-epm-preview-part="episode-page"] .epm-player').forEach(function (el) {
			setModifiers(el, 'epm-player--', ['epm-player--' + episodePageLayout(String(values.default_player_layout), all)]);
		});

		var layout = String(values.default_episode_layout || 'list');
		var isCards = (config.cardLayouts || []).indexOf(layout) !== -1;
		var cards = canvas.querySelector('[data-epm-preview-list="cards"]');
		var rows = canvas.querySelector('[data-epm-preview-list="rows"]');
		var rowLayout = isCards ? 'list' : layout;
		var numbers = !all.list || all.list.show_episode_number !== false;

		setModifiers(cards && cards.querySelector('.epm-episode-list'), 'epm-episode-list--', ['epm-episode-list--' + (isCards ? layout : 'cards')]);
		setModifiers(rows && rows.querySelector('.epm-episode-list'), 'epm-episode-list--', ['epm-episode-list--' + rowLayout].concat(
			numbers && (config.numberedLayouts || []).indexOf(rowLayout) !== -1 && rows.querySelector('.epm-episode-row__number') ? ['epm-episode-list--numbered'] : []
		));
		if (cards) {
			cards.hidden = !isCards;
		}
		if (rows) {
			rows.hidden = isCards;
		}
	}

	/**
	 * Update the contrast list from the form values. A pair that flips
	 * between pass and fail is announced once.
	 *
	 * @param {Object}  values Token key => value.
	 * @param {boolean} silent Do not announce (first run).
	 */
	function updateContrast(values, silent) {
		root.querySelectorAll('[data-epm-contrast-pair]').forEach(function (item) {
			var fgKey = item.getAttribute('data-fg');
			var bgKey = item.getAttribute('data-bg');
			var min = parseFloat(item.getAttribute('data-min'));
			var label = item.getAttribute('data-label');
			// An automatic track uses the muted text color.
			var fg = normalizeHex(values[fgKey]) || normalizeHex(values.muted);
			var bg = normalizeHex(values[bgKey]);
			// Rounded down, so the shown ratio never passes when the real one fails.
			var ratio = Math.floor(contrastRatio(fg, bg) * 10) / 10;
			var pass = ratio >= min;
			var sample = item.querySelector('[data-epm-contrast-sample]');

			item.classList.toggle('is-pass', pass);
			item.classList.toggle('is-fail', !pass);
			if (sample) {
				sample.style.color = fg;
				sample.style.background = bg;
			}
			item.querySelector('[data-epm-contrast-ratio]').textContent = decimal.format(ratio) + ':1';
			item.querySelector('[data-epm-contrast-verdict]').textContent = pass
				? __('Pass', 'elementor-podcast-manager')
				/* translators: %s: required contrast ratio, e.g. 4.5 */
				: sprintf(__('Too low, needs %s:1', 'elementor-podcast-manager'), decimal.format(min));

			var key = fgKey + '/' + bgKey;
			if (!silent && contrastState[key] !== undefined && contrastState[key] !== pass) {
				var message;
				if (pass) {
					message = sprintf(
						/* translators: 1: color pair, e.g. "Text on background", 2: contrast ratio */
						__('%1$s: contrast %2$s:1 passes.', 'elementor-podcast-manager'),
						label,
						decimal.format(ratio)
					);
				} else {
					message = sprintf(
						/* translators: 1: color pair, 2: contrast ratio, 3: required ratio */
						__('%1$s: contrast %2$s:1 is too low. It needs at least %3$s:1.', 'elementor-podcast-manager'),
						label,
						decimal.format(ratio),
						decimal.format(min)
					);
				}
				speak(message);
			}
			contrastState[key] = pass;
		});
	}

	/**
	 * Show or clear a field's error message.
	 *
	 * @param {Element} field   Field.
	 * @param {string}  message Error ('' clears it).
	 */
	function setFieldError(field, message) {
		var wrap = field.closest('.epm-design-field');
		var error = wrap ? wrap.querySelector('[data-epm-field-error]') : null;
		if (!error) {
			return;
		}
		error.textContent = message || '';
		error.hidden = !message;
		if (message) {
			field.setAttribute('aria-invalid', 'true');
			if ((field.getAttribute('aria-describedby') || '').indexOf(error.id) === -1) {
				field.setAttribute('aria-describedby', ((field.getAttribute('aria-describedby') || '') + ' ' + error.id).trim());
			}
		} else {
			field.removeAttribute('aria-invalid');
			field.setAttribute('aria-describedby', (field.getAttribute('aria-describedby') || '').split(/\s+/).filter(function (id) {
				return id && id !== error.id;
			}).join(' '));
		}
	}

	/**
	 * Error text for an invalid field ('' when valid).
	 *
	 * @param {Element} field Field.
	 * @return {string} Message.
	 */
	function fieldProblem(field) {
		var key = field.getAttribute('data-epm-token');
		var entry = map[key];
		if (!entry) {
			return '';
		}
		var value = String(field.value || '').trim();
		if (entry.kind === 'color') {
			if (entry.optional && value === '') {
				return '';
			}
			return normalizeHex(value) ? '' : __('Enter a color as # and six characters from 0–9 and a–f, for example #1d4ed8.', 'elementor-podcast-manager');
		}
		if (entry.kind === 'px') {
			/* translators: 1: lowest value, 2: highest value */
			return isPixelValue(value) ? '' : sprintf(__('Enter a whole number from %1$d to %2$d.', 'elementor-podcast-manager'), 0, 200);
		}
		return '';
	}

	/**
	 * Mark the form as changed.
	 *
	 * @param {boolean} on Changed.
	 */
	function setDirty(on) {
		dirty = on;
		if (dirtyEl) {
			dirtyEl.textContent = on ? __('Unsaved changes', 'elementor-podcast-manager') : '';
		}
		if (resetButton) {
			resetButton.hidden = !on;
		}
	}

	/**
	 * Show the form values (leaves a preset preview).
	 */
	function showMine() {
		var wasPreset = mode === 'preset';
		mode = 'fields';
		var active = presetForm ? presetForm.querySelector('.epm-preset__input[value="' + (window.CSS && CSS.escape ? CSS.escape(activeId) : activeId) + '"]') : null;
		if (active) {
			active.checked = true;
		}
		if (presetNote) {
			presetNote.hidden = true;
		}
		applyPreview(readForm());
		if (wasPreset && statusEl) {
			statusEl.textContent = __('Your design', 'elementor-podcast-manager');
		}
	}

	/**
	 * Preview a preset without applying it.
	 *
	 * @param {string} id Preset ID.
	 */
	function previewPreset(id) {
		var preset = config.presets && config.presets[id];
		if (!preset) {
			return;
		}
		mode = 'preset';
		applyPreview(preset.values, preset.details || {});
		if (presetNote && presetNoteText) {
			var changes = detailChanges(preset.details || {});
			/* translators: %s: preset name */
			var text = sprintf(__('Previewing “%s”. It is not applied yet: apply the preset to use it.', 'elementor-podcast-manager'), preset.name);
			if (changes.length) {
				text += ' ' + sprintf(
					/* translators: %s: list of detail changes, e.g. "Volume slider hidden: Player" */
					__('Details it changes on your site: %s.', 'elementor-podcast-manager'),
					changes.map(describeChange).join('; ')
				);
			}
			presetNoteText.textContent = text;
			presetNote.hidden = false;
		}
		if (statusEl) {
			/* translators: %s: preset name */
			statusEl.textContent = sprintf(__('Preset preview: %s', 'elementor-podcast-manager'), preset.name);
		}
	}

	/**
	 * Keep the color picker, the hex field and the Automatic box in step.
	 *
	 * @param {Element} wrap  Color field wrapper.
	 * @param {Object}  values Current values.
	 */
	function syncColorField(wrap, values) {
		var hexField = wrap.querySelector('[data-epm-token]');
		var picker = wrap.querySelector('[data-epm-color-picker]');
		var auto = wrap.querySelector('[data-epm-track-auto]');
		var hex = normalizeHex(hexField.value);

		if (auto) {
			var isAuto = auto.checked;
			wrap.classList.toggle('is-auto', isAuto);
			hexField.readOnly = isAuto;
			picker.disabled = isAuto;
			if (isAuto) {
				picker.value = normalizeHex(values.muted) || picker.value;
				return;
			}
		}
		if (hex) {
			picker.value = hex;
		}
	}

	/**
	 * React to any change of a design field.
	 *
	 * @param {Element} target Changed element.
	 */
	function onFieldChange(target) {
		if (mode === 'preset') {
			showMine();
		}
		var values = readForm();
		root.querySelectorAll('[data-epm-color]').forEach(function (wrap) {
			if (wrap.contains(target) || wrap.querySelector('[data-epm-track-auto]')) {
				syncColorField(wrap, values);
			}
		});
		applyPreview(values);
		updateContrast(values, false);
		setDirty(true);
	}

	form.addEventListener('input', function (e) {
		var target = e.target;

		if (target.matches('[data-epm-color-picker]')) {
			var hexField = target.closest('[data-epm-color]').querySelector('[data-epm-token]');
			hexField.value = target.value;
			setFieldError(hexField, '');
		} else if (target.matches('[data-epm-token]') && (target.type === 'text' || target.type === 'number')) {
			// Clear an error as soon as the value is valid; new errors wait
			// for the field to be left (change), so typing isn't interrupted.
			if (target.getAttribute('aria-invalid') === 'true' && !fieldProblem(target)) {
				setFieldError(target, '');
			}
		} else if (!target.matches('[data-epm-token], [data-epm-track-auto]')) {
			return;
		}
		onFieldChange(target);
	});

	form.addEventListener('change', function (e) {
		var target = e.target;

		if (target.matches('[data-epm-track-auto]')) {
			var wrap = target.closest('[data-epm-color]');
			var hexField = wrap.querySelector('[data-epm-token]');
			var picker = wrap.querySelector('[data-epm-color-picker]');
			if (target.checked) {
				hexField.value = '';
				setFieldError(hexField, '');
			} else {
				hexField.value = normalizeHex(picker.value) || normalizeHex(readForm().muted);
			}
			onFieldChange(target);
			if (!target.checked) {
				hexField.focus();
			}
			return;
		}

		if (target.matches('.epm-color__hex')) {
			var problem = fieldProblem(target);
			if (!problem && target.value.trim() !== '') {
				target.value = normalizeHex(target.value);
			}
			var auto = target.closest('[data-epm-color]').querySelector('[data-epm-track-auto]');
			if (auto && target.value.trim() === '') {
				auto.checked = true;
				onFieldChange(auto);
			}
			setFieldError(target, problem);
			return;
		}

		if (target.matches('[data-epm-token][type="number"]')) {
			setFieldError(target, fieldProblem(target));
		}
	});

	// Native validation (pattern, min, max) shows the message next to the
	// field too, not only in the browser's bubble.
	form.addEventListener('invalid', function (e) {
		var target = e.target;
		if (target.matches('[data-epm-token]')) {
			setFieldError(target, fieldProblem(target) || target.validationMessage);
		}
	}, true);

	form.addEventListener('reset', function () {
		// Values are restored after the event.
		window.setTimeout(function () {
			form.querySelectorAll('[data-epm-token]').forEach(function (field) {
				setFieldError(field, '');
			});
			var values = readForm();
			root.querySelectorAll('[data-epm-color]').forEach(function (wrap) {
				var auto = wrap.querySelector('[data-epm-track-auto]');
				if (auto) {
					auto.checked = wrap.querySelector('[data-epm-token]').value.trim() === '';
				}
				syncColorField(wrap, values);
			});
			showMine();
			updateContrast(values, true);
			setDirty(false);
			speak(__('Changes discarded. The saved design is shown again.', 'elementor-podcast-manager'));
		}, 0);
	});

	form.addEventListener('submit', function () {
		// Saving leaves the page: no "unsaved" warning is needed.
		setDirty(false);
	});

	// Leaving with unsaved changes asks first (the browser shows its own
	// message). Exporting downloads a file and stays on the page, so it
	// doesn't ask.
	var downloadUntil = 0;
	var exportForm = root.querySelector('[data-epm-design-export]');
	if (exportForm) {
		exportForm.addEventListener('submit', function () {
			downloadUntil = Date.now() + 2000;
		});
	}
	window.addEventListener('beforeunload', function (e) {
		if ((!dirty && !detailsDirty) || Date.now() < downloadUntil) {
			return;
		}
		e.preventDefault();
		e.returnValue = '';
	});

	// ---------------------------------------------------------------------
	// Details shown by default: the preview shows saved details, so a
	// change says it needs saving (and leaving the page asks first).
	// ---------------------------------------------------------------------

	if (detailsForm) {
		detailsForm.addEventListener('change', function (e) {
			if (!e.target.matches('input[type="checkbox"]')) {
				return;
			}
			detailsDirty = true;
			if (detailsDirtyEl) {
				detailsDirtyEl.textContent = __('Unsaved changes. Save the details to see them in the preview and on your site.', 'elementor-podcast-manager');
			}
		});
		detailsForm.addEventListener('submit', function () {
			detailsDirty = false;
		});
	}

	// ---------------------------------------------------------------------
	// Preset gallery.
	// ---------------------------------------------------------------------

	if (presetForm) {
		presetForm.addEventListener('change', function (e) {
			if (!e.target.matches('.epm-preset__input')) {
				return;
			}
			var id = e.target.value;
			var preset = config.presets && config.presets[id];
			var mine = readForm();
			var same = id === activeId && preset && Object.keys(preset.values).every(function (key) {
				return String(preset.values[key]) === String(mine[key]);
			});
			if (same) {
				showMine();
			} else {
				previewPreset(id);
			}
		});

		presetForm.addEventListener('submit', function (e) {
			if (confirmed || !dialog || typeof dialog.showModal !== 'function') {
				// Applying replaces the form values: nothing is left to warn about.
				setDirty(false);
				return;
			}
			e.preventDefault();
			var checked = presetForm.querySelector('.epm-preset__input:checked');
			if (!checked) {
				return;
			}
			var preset = config.presets && config.presets[checked.value];
			var title = dialog.querySelector('[data-epm-dialog-title]');
			/* translators: %s: preset name */
			title.textContent = sprintf(__('Apply “%s”?', 'elementor-podcast-manager'), preset ? preset.name : checked.value);
			dialog.querySelector('[data-epm-dialog-dirty]').hidden = !(dirty || detailsDirty);
			var detailsBox = dialog.querySelector('[data-epm-dialog-details]');
			var detailsList = dialog.querySelector('[data-epm-dialog-details-list]');
			if (detailsBox && detailsList) {
				var changes = detailChanges((preset && preset.details) || {});
				detailsList.textContent = '';
				changes.forEach(function (change) {
					var item = document.createElement('li');
					item.textContent = describeChange(change);
					detailsList.appendChild(item);
				});
				detailsBox.hidden = !changes.length;
			}
			dialog.showModal();
			dialog.querySelector('[data-epm-dialog-cancel]').focus();
		});
	}

	if (dialog) {
		dialog.querySelector('[data-epm-dialog-cancel]').addEventListener('click', function () {
			dialog.close();
		});
		dialog.querySelector('[data-epm-dialog-confirm]').addEventListener('click', function () {
			confirmed = true;
			dialog.close();
			// The user agreed to replace unsaved changes in the dialog.
			setDirty(false);
			if (typeof presetForm.requestSubmit === 'function') {
				presetForm.requestSubmit();
			} else {
				presetForm.submit();
			}
		});
		dialog.addEventListener('close', function () {
			if (!confirmed && applyButton) {
				applyButton.focus();
			}
		});
	}

	var mineButton = root.querySelector('[data-epm-preview-mine]');
	if (mineButton) {
		mineButton.addEventListener('click', function () {
			showMine();
			var active = presetForm && presetForm.querySelector('.epm-preset__input:checked');
			if (active) {
				active.focus();
			}
		});
	}

	// Progressive enhancement: the Automatic box needs this script.
	root.querySelectorAll('[data-epm-track-auto-row]').forEach(function (row) {
		row.hidden = false;
	});

	var initial = readForm();
	applyPreview(initial);
	updateContrast(initial, true);
})();
