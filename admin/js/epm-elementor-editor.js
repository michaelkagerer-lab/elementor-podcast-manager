/**
 * Elementor editor: "Use Podcast → Design defaults" on the Podcast Player,
 * Latest Episode and Episode List widgets.
 *
 * The button (an Elementor BUTTON control, event "epm:details:defaults")
 * sets every detail control of the widget (show_* selects) to Default
 * (''), as one settings change: it can be undone, and Elementor stores no
 * detail for the widget any more, so it follows Podcast → Design →
 * Details shown by default.
 */
(function () {
	'use strict';

	function containerOf(view) {
		if (!view) {
			return null;
		}
		return view.container || (view.options && view.options.container) || null;
	}

	function useDefaults(view) {
		var container = containerOf(view);
		if (!container || !container.settings || !window.$e) {
			return;
		}
		var controls = container.settings.controls || {};
		var settings = {};
		Object.keys(controls).forEach(function (name) {
			var control = controls[name];
			if (/^show_/.test(name) && control && control.type === 'select' && control.options && Object.prototype.hasOwnProperty.call(control.options, 'yes') && Object.prototype.hasOwnProperty.call(control.options, 'no')) {
				settings[name] = '';
			}
		});
		if (!Object.keys(settings).length) {
			return;
		}
		window.$e.run('document/elements/settings', {
			container: container,
			settings: settings,
			options: { external: true }
		});
	}

	function bind() {
		if (!window.elementor || !window.elementor.channels || !window.elementor.channels.editor || bind.done) {
			return;
		}
		bind.done = true;
		window.elementor.channels.editor.on('epm:details:defaults', useDefaults);
	}

	bind();
	if (!bind.done && window.jQuery) {
		window.jQuery(window).on('elementor:init', bind);
	}
})();
