/** Elementor: explicit inheritance resets, task guidance and starter insertion. */
(function () {
	'use strict';

	function containerOf(view) {
		return view && (view.container || (view.options && view.options.container)) || null;
	}

	function applySettings(container, settings) {
		// A button is a distinct action. Finish the pending typed-value transaction
		// so Undo restores that value instead of coalescing typing and reset.
		window.$e.internal('document/history/end-transaction');
		window.$e.run('document/elements/settings', {
			container: container, settings: settings, options: { external: true }
		});
		window.$e.internal('document/history/end-transaction');
	}

	function useDefaults(view) {
		var container = containerOf(view);
		if (!container || !container.settings || !window.$e) { return; }
		var controls = container.settings.controls || {}, settings = {};
		Object.keys(controls).forEach(function (name) {
			var control = controls[name];
			if (/^show_/.test(name) && control && control.type === 'select' && control.options &&
				Object.prototype.hasOwnProperty.call(control.options, 'yes') &&
				Object.prototype.hasOwnProperty.call(control.options, 'no')) {
				settings[name] = '';
			}
		});
		if (Object.keys(settings).length) { applySettings(container, settings); }
	}

	function decorateLibrary() {
		var config = window.epmEditor || {};
		document.querySelectorAll('#elementor-panel .elementor-element-wrapper').forEach(function (tile) {
			var title = tile.querySelector('.title, .elementor-element-title');
			if (!title || tile.querySelector('.epm-widget-purpose')) { return; }
			var name = Object.keys(config.titles || {}).find(function (key) {
				return config.titles[key] === title.textContent.trim();
			});
			var purpose = (config.descriptions || {})[name];
			if (!purpose) { return; }
			var text = document.createElement('small');
			text.className = 'epm-widget-purpose';
			text.textContent = purpose.split('. ')[0] + '.';
			title.after(text);
		});
	}

	function insertStarter(view) {
		var config = window.epmEditor || {}, container = containerOf(view);
		if (!container || !window.$e || !window.elementor.getPreviewContainer) { return; }
		var kind = container.settings.get('epm_starter_kind') || 'show';
		if (kind === 'episode' && (!config.episode ||
			Number(window.elementor.config.document.id) !== Number(config.documentId))) {
			window.elementor.notifications.showToast({ message: config.contextError });
			return;
		}
		var model = config.starters && config.starters[kind];
		if (!model) { return; }
		model = JSON.parse(JSON.stringify(model));
		function freshIds(element) {
			element.id = Math.random().toString(16).slice(2, 9);
			(element.elements || []).forEach(freshIds);
		}
		freshIds(model);
		try {
			window.$e.run('document/elements/create', {
				container: window.elementor.getPreviewContainer(), model: model
			});
		} catch (error) {
			window.elementor.notifications.showToast({ message: config.insertError });
		}
	}

	function bind() {
		if (!window.elementor || !window.elementor.channels || !window.elementor.channels.editor || bind.done) { return; }
		bind.done = true;
		// The old and current editors share the native tile markup; their behavior
		// hook signatures differ. Observe the panel rather than coupling to a view.
		var libraryObserver = new MutationObserver(decorateLibrary);
		libraryObserver.observe(document.getElementById('elementor-panel') || document.body, {
			childList: true, subtree: true
		});
		decorateLibrary();
		window.elementor.channels.editor.on('epm:details:defaults', useDefaults);
		window.elementor.channels.editor.on('epm:starter:insert', insertStarter);
		((window.epmEditor && window.epmEditor.resets) || []).forEach(function (name) {
			window.elementor.channels.editor.on('epm:style:inherit:' + name, function (view) {
				var container = containerOf(view);
				if (!container || !window.$e) { return; }
				var settings = {}, controls = container.settings.controls || {}, target = name;
				var control = controls[name] || {}, device = window.elementor.channels.deviceMode.request('currentMode');
				// New editors duplicate responsive controls in JS. Resolve the active
				// device at click time so a mobile reset cannot erase desktop values.
				if ((control.is_responsive || control.responsive) && device && device !== 'desktop' && controls[name + '_' + device]) {
					target = name + '_' + device;
					control = controls[target];
				}
				settings[target] = control.type === 'slider' ? { size: '', unit: 'px', sizes: [] } :
					control.type === 'dimensions' ? { top: '', right: '', bottom: '', left: '', unit: 'px', isLinked: true } : '';
				applySettings(container, settings);
			});
		});
	}

	bind();
	if (!bind.done && window.jQuery) { window.jQuery(window).on('elementor:init', bind); }
})();
