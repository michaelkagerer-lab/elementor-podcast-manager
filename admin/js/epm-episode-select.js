/** Accessible, paginated episode search for the native Elementor panel. */
(function ($) {
	'use strict';
	var nextId = 0;
	function validEpisode(item) {
		return item && Number.isSafeInteger(Number(item.id)) && Number(item.id) > 0 && typeof item.title === 'string';
	}
	var ControlView = elementor.modules.controls.BaseData.extend({
		ui: function () {
			var ui = ControlView.__super__.ui.apply(this, arguments);
			ui.search = '.epm-episode-select__search';
			ui.results = '.epm-episode-select__results';
			ui.current = '.epm-episode-select__current';
			ui.status = '.epm-episode-select__status';
			ui.retry = '.epm-episode-select__retry';
			ui.more = '.epm-episode-select__more';
			ui.currentRetry = '.epm-episode-select__current-retry';
			ui.clear = '.epm-episode-select__clear';
			return ui;
		},

		onReady: function () {
			if (this.namespace) { this.onBeforeDestroy(); }
			var self = this, id = 'epm-episode-search-' + (++nextId);
			this.namespace = '.epmEpisodeSelect' + nextId;
			this.activeIndex = -1;
			this.ui.search.attr({ id: id, 'aria-labelledby': id + '-label', 'aria-controls': id + '-results', 'aria-describedby': id + '-hint ' + id + '-current' });
			this.$el.find('.epm-episode-select__label').attr({ id: id + '-label', 'for': id });
			this.$el.find('.epm-episode-select__hint').attr('id', id + '-hint');
			this.ui.results.attr({ id: id + '-results', 'aria-labelledby': id + '-label' });
			this.ui.current.attr('id', id + '-current');
			this.renderCurrent();

			this.ui.search.on('input', function () {
				self.cancelSearch();
				var term = $(this).val();
				// Invalidate visible results immediately, before the debounce expires.
				self.ui.results.empty();
				self.setActive(-1);
				self.setOpen(true);
				self.ui.status.text(epmEpisodeSelect.loading).addClass('epm-episode-select__loading');
				self.ui.retry.add(self.ui.more).attr('hidden', true);
				self.timer = setTimeout(function () { self.search(term, 1); }, 300);
			}).on('focus', function () {
				if (self.focusingSearch) { return; }
				if (!self.ui.results.children().length && !self.searchRequest && !self.timer) {
					self.search(self.ui.search.val(), 1);
				}
				self.setOpen(true);
			}).on('keydown', function (event) {
				var key = event.key, count = self.ui.results.children('[role="option"]').length;
				if (key === 'Escape') { event.preventDefault(); event.stopPropagation(); self.close(); }
				else if (key === 'ArrowDown' || key === 'ArrowUp') {
					event.preventDefault(); self.setOpen(true);
					if (!count && !self.searchRequest && !self.timer) { self.search(self.ui.search.val(), 1); }
					if (count) { self.setActive(self.activeIndex < 0 ? (key === 'ArrowDown' ? 0 : count - 1) : (self.activeIndex + (key === 'ArrowDown' ? 1 : -1) + count) % count); }
				} else if (key === 'Enter' && self.ui.search.attr('aria-expanded') === 'true' && self.activeIndex >= 0) {
					event.preventDefault(); self.choose(self.ui.results.children('[role="option"]').eq(self.activeIndex));
				}
			});
			this.$el.on('focusout', function (event) {
				if (!self.el.contains(event.relatedTarget)) { self.close(); }
			});
			this.ui.results.on('mousedown', '[role="option"]', function (e) { e.preventDefault(); });
			this.ui.results.on('click', '[role="option"]', function () { self.choose($(this)); });
			this.ui.retry.on('click', function () {
				self.focusSearch(); self.search(self.term, self.page);
			});
			this.ui.currentRetry.on('click', function () { self.focusSearch(); self.renderCurrent(); });
			this.ui.more.on('click', function () { self.focusSearch(); self.search(self.term, self.page + 1); });
			this.ui.clear.on('click', function () {
				self.focusSearch(); self.close(); self.setValue(''); self.ui.search.val(''); self.renderCurrent();
			});
			$(document).on('click' + this.namespace, function (event) {
				if (!self.el.contains(event.target)) { self.close(); }
			});
		},

		focusSearch: function () {
			// Move focus before hiding an action button; otherwise its blur can
			// close the control and cancel the retry/pagination it just started.
			this.focusingSearch = true; this.ui.search[0].focus(); this.focusingSearch = false;
		},

		onBeforeDestroy: function () {
			this.cancelSearch();
			this.currentRequestId = (this.currentRequestId || 0) + 1;
			if (this.currentRequest) { this.currentRequest.abort(); }
			if (this.namespace) { $(document).off(this.namespace); }
		},

		cancelSearch: function () {
			clearTimeout(this.timer); this.timer = null;
			this.requestId = (this.requestId || 0) + 1;
			if (this.searchRequest) { this.searchRequest.abort(); this.searchRequest = null; }
		},

		setOpen: function (open) {
			this.ui.results.attr('hidden', !open);
			this.ui.search.attr('aria-expanded', String(open));
			if (!open) { this.setActive(-1); }
		},

		close: function () {
			this.cancelSearch();
			this.setOpen(false);
			this.ui.results.empty();
			this.ui.status.text('').removeClass('epm-episode-select__loading epm-episode-select__empty');
			this.ui.results.attr('aria-busy', 'false');
			this.ui.retry.add(this.ui.more).attr('hidden', true);
		},

		setActive: function (index) {
			this.activeIndex = index;
			var options = this.ui.results.children('[role="option"]');
			options.removeClass('is-active');
			if (index < 0 || !options.eq(index).length) { this.ui.search.removeAttr('aria-activedescendant'); return; }
			var option = options.eq(index).addClass('is-active');
			this.ui.search.attr('aria-activedescendant', option.attr('id'));
			option[0].scrollIntoView({ block: 'nearest' });
		},

		choose: function (option) {
			var id = Number(option.data('id'));
			if (!Number.isSafeInteger(id) || id <= 0) { return; }
			this.close(); this.setValue(id); this.ui.search.val('');
			this.ui.results.empty(); this.renderCurrent();
			this.focusSearch();
		},

		search: function (term, page) {
			var self = this;
			this.cancelSearch();
			var requestId = this.requestId;
			this.term = term; this.page = page || 1;
			this.ui.retry.add(this.ui.more).attr('hidden', true);
			if (this.page === 1) { this.ui.results.empty(); this.setActive(-1); }
			this.ui.status.text(epmEpisodeSelect.loading).addClass('epm-episode-select__loading').removeClass('epm-episode-select__empty');
			this.ui.results.attr('aria-busy', 'true'); this.setOpen(true);
			this.searchRequest = $.ajax({ url: epmEpisodeSelect.ajaxUrl, method: 'POST', dataType: 'json', timeout: 15000, data: {
				action: 'epm_episode_search', _ajax_nonce: epmEpisodeSelect.nonce, s: term, page: this.page
			} }).done(function (response) {
				if (requestId !== self.requestId || !self.isAlive()) { return; }
				if (!response || !response.success || !response.data || !Array.isArray(response.data.items) || !response.data.items.every(validEpisode)) { self.searchError(); return; }
				self.ui.status.text('').removeClass('epm-episode-select__loading');
				$.each(response.data.items, function (i, item) {
					self.ui.results.append($('<li role="option"></li>').attr({
						id: self.ui.results.attr('id') + '-option-' + item.id,
						'aria-selected': String(String(item.id) === String(self.getControlValue()))
					}).data('id', item.id).text(self.label(item)));
				});
				if (!self.ui.results.children().length) { self.ui.status.text(epmEpisodeSelect.noResults).addClass('epm-episode-select__empty'); }
				self.ui.more.attr('hidden', !response.data.more);
			}).fail(function (_xhr, status) {
				if (status !== 'abort' && requestId === self.requestId && self.isAlive()) { self.searchError(); }
			}).always(function () {
				if (requestId === self.requestId && self.isAlive()) { self.searchRequest = null; self.ui.results.attr('aria-busy', 'false'); }
			});
		},

		searchError: function () {
			this.ui.status.text(epmEpisodeSelect.searchError).removeClass('epm-episode-select__loading epm-episode-select__empty');
			this.ui.retry.removeAttr('hidden');
		},

		isAlive: function () {
			var destroyed = typeof this.isDestroyed === 'function' ? this.isDestroyed() : !!this.isDestroyed;
			return !destroyed && this.ui && this.ui.results && typeof this.ui.results.find === 'function';
		},

		label: function (item) {
			return item.title + (item.date ? ' — ' + item.date : '') + (item.status ? ' (' + item.status + ')' : '');
		},

		renderCurrent: function () {
			var self = this, id = this.getControlValue();
			this.currentRequestId = (this.currentRequestId || 0) + 1;
			var requestId = this.currentRequestId;
			if (this.currentRequest) { this.currentRequest.abort(); }
			this.ui.clear.attr('hidden', !id);
			this.ui.currentRetry.attr('hidden', true);
			if (!id) { this.ui.current.text(epmEpisodeSelect.noSelection); return; }
			this.ui.current.text(epmEpisodeSelect.loading);
			this.currentRequest = $.ajax({ url: epmEpisodeSelect.ajaxUrl, method: 'POST', dataType: 'json', timeout: 15000, data: {
				action: 'epm_episode_search', _ajax_nonce: epmEpisodeSelect.nonce, include: id
			} }).done(function (response) {
				if (requestId !== self.currentRequestId || !self.isAlive() || String(id) !== String(self.getControlValue())) { return; }
				if (!response || !response.success || !response.data || !Array.isArray(response.data.items) || !response.data.items.every(validEpisode)) {
					self.currentError();
				} else if (!response.data.items.length) { self.ui.current.text(epmEpisodeSelect.unavailable); }
				else if (Number(response.data.items[0].id) === Number(id)) { self.ui.current.text(self.label(response.data.items[0])); }
				else { self.currentError(); }
			}).fail(function (_xhr, status) {
				if (status !== 'abort' && requestId === self.currentRequestId && self.isAlive()) { self.currentError(); }
			});
		},

		currentError: function () {
			this.ui.current.text(epmEpisodeSelect.currentError);
			this.ui.currentRetry.removeAttr('hidden');
		}
	});
	elementor.addControlView('epm_episode_select', ControlView);
})(jQuery);
