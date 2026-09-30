/**
 * EPM Elementor editor control: searchable episode select.
 *
 * Custom control view for the `epm_episode_select` control type. Searches
 * episodes via AJAX (paginated server-side) so catalogs larger than 100
 * episodes remain fully reachable.
 */
(function ($) {
	'use strict';

	var ControlView = elementor.modules.controls.BaseData.extend({

		ui: function () {
			var ui = ControlView.__super__.ui.apply(this, arguments);
			ui.search = '.epm-episode-select__search';
			ui.results = '.epm-episode-select__results';
			ui.current = '.epm-episode-select__current';
			return ui;
		},

		onReady: function () {
			var self = this;
			var timer = null;

			this.renderCurrent();

			this.ui.search.on('input', function () {
				clearTimeout(timer);
				var term = $(this).val();
				timer = setTimeout(function () {
					self.search(term, 1);
				}, 300);
			});

			this.ui.search.on('focus', function () {
				if (!self.ui.results.children().length) {
					self.search('', 1);
				}
				self.ui.results.removeAttr('hidden');
				self.ui.search.attr('aria-expanded', 'true');
			});

			// Close the dropdown when clicking elsewhere.
			$(document).on('click.epmEpisodeSelect', function (e) {
				if (!$(e.target).closest('.epm-episode-select').length) {
					self.ui.results.attr('hidden', true);
					self.ui.search.attr('aria-expanded', 'false');
				}
			});

			this.ui.results.on('click', 'li', function () {
				var id = $(this).data('id');
				self.setValue(id);
				self.renderCurrent();
				self.ui.results.attr('hidden', true);
				self.ui.search.attr('aria-expanded', 'false');
				self.ui.search.val('');
			});
		},

		onBeforeDestroy: function () {
			$(document).off('click.epmEpisodeSelect');
		},

		/**
		 * Search episodes. Responses can arrive out of order (the empty
		 * search fired on focus vs. the typed search), so only the latest
		 * request may render. Page 2+ appends instead of replacing.
		 */
		search: function (term, page) {
			var self = this;
			var requestId = (this.requestId || 0) + 1;
			page = page || 1;
			this.requestId = requestId;

			this.ui.results.find('.epm-episode-select__more, .epm-episode-select__loading, .epm-episode-select__empty').remove();
			if (page === 1) {
				this.ui.results.empty();
			}
			this.ui.results.append($('<li class="epm-episode-select__loading"></li>').text(epmEpisodeSelect.loading));
			this.ui.results.removeAttr('hidden');

			$.post(epmEpisodeSelect.ajaxUrl, {
				action: 'epm_episode_search',
				_ajax_nonce: epmEpisodeSelect.nonce,
				s: term,
				page: page
			}).done(function (response) {
				if (requestId !== self.requestId) {
					return; // A newer search superseded this one.
				}
				self.ui.results.find('.epm-episode-select__loading').remove();
				if (response && response.success && response.data && response.data.items && response.data.items.length) {
					$.each(response.data.items, function (i, item) {
						self.ui.results.append(
							$('<li role="option"></li>')
								.data('id', item.id)
								.text(self.label(item))
						);
					});
					if (response.data.more) {
						var more = $('<li class="epm-episode-select__more"></li>').text(epmEpisodeSelect.loadMore || '…');
						more.on('click', function (e) {
							e.stopPropagation();
							self.search(term, page + 1);
						});
						self.ui.results.append(more);
					}
				} else if (page === 1) {
					self.ui.results.append($('<li class="epm-episode-select__empty"></li>').text(epmEpisodeSelect.noResults));
				}
			}).fail(function () {
				if (requestId !== self.requestId) {
					return;
				}
				self.ui.results.find('.epm-episode-select__loading').remove();
				self.ui.results.append($('<li class="epm-episode-select__empty"></li>').text(epmEpisodeSelect.noResults));
			});
		},

		label: function (item) {
			return item.title +
				(item.date ? ' — ' + item.date : '') +
				(item.status ? ' (' + item.status + ')' : '');
		},

		renderCurrent: function () {
			var self = this;
			var id = this.getControlValue();

			if (!id) {
				this.ui.current.text('');
				return;
			}

			this.ui.current.text(epmEpisodeSelect.loading);

			$.post(epmEpisodeSelect.ajaxUrl, {
				action: 'epm_episode_search',
				_ajax_nonce: epmEpisodeSelect.nonce,
				include: id
			}).done(function (response) {
				if (response && response.success && response.data && response.data.items && response.data.items.length) {
					self.ui.current.text(self.label(response.data.items[0]));
				} else {
					self.ui.current.text('');
				}
			}).fail(function () {
				self.ui.current.text('');
			});
		}
	});

	elementor.addControlView('epm_episode_select', ControlView);
})(jQuery);
