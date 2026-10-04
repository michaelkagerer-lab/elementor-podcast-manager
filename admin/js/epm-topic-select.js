/* Keep Elementor's native Select2 behavior and Undo, with complete topic search. */
jQuery(window).on('elementor:init', function () {
	'use strict';
	var Native = elementor.getControlView('select2');
	var cfg = window.epmTopicSelect;
	if (!Native || !cfg) { return; }
	function validResponse(response) {
		return !!(response && response.success && response.data && Array.isArray(response.data.results) && response.data.results.every(function (item) { return item && typeof item.id === 'string' && typeof item.text === 'string'; }));
	}
	elementor.addControlView('epm_topic_select', Native.extend({
		getSelect2Options: function () {
			var options = Native.prototype.getSelect2Options.apply(this, arguments);
			options.ajax = {
				url: cfg.url, type: 'POST', dataType: 'json', delay: 250, timeout: 15000,
				data: function (params) { return {action: 'epm_topic_search', _ajax_nonce: cfg.nonce, s: params.term || '', page: params.page || 1}; },
				transport: function (params, success, failure) {
					var request = jQuery.ajax(params);
					request.done(function (response) {
						if (!validResponse(response)) { failure(); return; }
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
			var missing = values.filter(function (slug) {
				var option = Array.from(select[0].options).find(function (item) { return item.value === slug; });
				if (!option) { option = new Option(slug, slug, true, true); option.dataset.epmPending = '1'; select.append(option); }
				return option.dataset.epmPending === '1';
			});
			Native.prototype.applySavedValue.apply(this, arguments);
			// Older native Select2 renders an unnamed search input with a textbox role.
			// Match that role's text-input semantics and keep the visible control label.
			select.next('.select2').find('.select2-search__field').attr({type: 'text', 'aria-label': this.model.get('label')});
			// Customize one error message without replacing the native locale dictionary.
			var instance = select.data('select2');
			if (instance) { instance.options.get('translations').dict.errorLoading = function () { return cfg.error; }; }
			clearTimeout(this.epmHydrateTimer);
			var generation = this.epmGeneration = (this.epmGeneration || 0) + 1;
			if (this.epmLookup) { this.epmLookup.abort(); }
			this.$el.find('[data-epm-topic-error]').remove();
			function failed() {
				if (generation !== self.epmGeneration || !self.$el[0].isConnected) { return; }
				var box = jQuery('<div data-epm-topic-error class="elementor-control-field-description"></div>');
				jQuery('<p role="status"></p>').text(cfg.error).appendTo(box);
				jQuery('<button type="button" data-epm-topic-retry class="elementor-button"></button>').text(cfg.retry).on('click', function () {
					select.next('.select2').find('.select2-search__field').trigger('focus');
					self.applySavedValue();
				}).appendTo(box);
				self.$el.find('[data-epm-topic-error]').remove();
				box.appendTo(self.$el.find('.elementor-control-input-wrapper'));
			}
			// Hydrate labels in bounded chunks; never remove or rewrite saved slugs.
			var chunks = [];
			for (var i = 0; i < missing.length; i += 100) { chunks.push(missing.slice(i, i + 100)); }
			function hydrate() {
				if (!chunks.length || generation !== self.epmGeneration || !self.$el[0].isConnected) { return; }
				self.epmLookup = jQuery.ajax({url: cfg.url, type: 'POST', dataType: 'json', timeout: 15000, data: {action: 'epm_topic_search', _ajax_nonce: cfg.nonce, include: chunks.shift()}}).done(function (response) {
					if (generation !== self.epmGeneration || !self.$el[0].isConnected) { return; }
					if (!validResponse(response)) { failed(); return; }
					response.data.results.forEach(function (item) {
						Array.from(select[0].options).forEach(function (option) { if (option.value === item.id) { option.text = item.text; delete option.dataset.epmPending; } });
					});
					select.select2('data').forEach(function (selected) {
						var item = response.data.results.find(function (result) { return result.id === selected.id; });
						if (item) { selected.text = item.text; }
					});
					select.trigger('change.select2');
					hydrate();
				}).fail(function (xhr, status) { if (status !== 'abort') { failed(); } });
			}
			this.epmHydrateTimer = setTimeout(hydrate, 0);
		},
		onBeforeDestroy: function () {
			this.epmGeneration = (this.epmGeneration || 0) + 1;
			clearTimeout(this.epmHydrateTimer);
			if (this.epmLookup) { this.epmLookup.abort(); }
			if (this.select2Instance) { Native.prototype.onBeforeDestroy.apply(this, arguments); }
		}
	}));
});
