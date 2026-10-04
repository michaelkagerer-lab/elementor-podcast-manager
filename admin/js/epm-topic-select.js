/* Keep Elementor's native Select2 behavior and Undo, with complete topic search. */
jQuery(window).on('elementor:init', function () {
	'use strict';
	var Native = elementor.getControlView('select2');
	var cfg = window.epmTopicSelect;
	if (!Native || !cfg) { return; }
	elementor.addControlView('epm_topic_select', Native.extend({
		getSelect2Options: function () {
			var options = Native.prototype.getSelect2Options.apply(this, arguments);
			options.ajax = {
				url: cfg.url, type: 'POST', dataType: 'json', delay: 250, timeout: 15000,
				data: function (params) { return {action: 'epm_topic_search', _ajax_nonce: cfg.nonce, s: params.term || '', page: params.page || 1}; },
				transport: function (params, success, failure) {
					var request = jQuery.ajax(params);
					request.done(function (response) {
						if (!response || !response.success || !response.data || !Array.isArray(response.data.results) || response.data.results.some(function (item) { return !item || typeof item.id !== 'string' || typeof item.text !== 'string'; })) { failure(); return; }
						success(response);
					});
					request.fail(function (xhr, status) { if (status !== 'abort') { failure(); } });
					return {abort: function () { request.abort(); }};
				},
				processResults: function (response) {
					return response.data;
				}
			};
			return options;
		},
		applySavedValue: function () {
			var self = this;
			var select = this.$el.find('select');
			var values = this.getControlValue() || [];
			if (!Array.isArray(values)) { values = [values]; }
			var missing = values.filter(function (slug) { return !Array.from(select[0].options).some(function (option) { return option.value === slug; }); });
			missing.forEach(function (slug) { select.append(new Option(slug, slug, true, true)); });
			Native.prototype.applySavedValue.apply(this, arguments);
			// Customize one error message without replacing the native locale dictionary.
			var instance = select.data('select2');
			if (instance) { instance.options.get('translations').dict.errorLoading = function () { return cfg.error; }; }
			if (this.epmLookup) { this.epmLookup.abort(); }
			// Hydrate labels in bounded chunks; never remove or rewrite saved slugs.
			var chunks = [];
			for (var i = 0; i < missing.length; i += 100) { chunks.push(missing.slice(i, i + 100)); }
			function hydrate() {
				if (!chunks.length || !self.$el[0].isConnected) { return; }
				self.epmLookup = jQuery.ajax({url: cfg.url, type: 'POST', dataType: 'json', timeout: 15000, data: {action: 'epm_topic_search', _ajax_nonce: cfg.nonce, include: chunks.shift()}}).done(function (response) {
					if (!self.$el[0].isConnected || !response.success || !response.data || !Array.isArray(response.data.results)) { return; }
					response.data.results.forEach(function (item) {
						Array.from(select[0].options).forEach(function (option) { if (option.value === item.id) { option.text = item.text; } });
					});
					select.select2('data').forEach(function (selected) {
						var item = response.data.results.find(function (result) { return result.id === selected.id; });
						if (item) { selected.text = item.text; }
					});
					select.trigger('change.select2');
					hydrate();
				});
			}
			clearTimeout(this.epmHydrateTimer);
			this.epmHydrateTimer = setTimeout(hydrate, 0);
		},
		onBeforeDestroy: function () {
			clearTimeout(this.epmHydrateTimer);
			if (this.epmLookup) { this.epmLookup.abort(); }
			if (this.select2Instance) { Native.prototype.onBeforeDestroy.apply(this, arguments); }
		}
	}));
});
