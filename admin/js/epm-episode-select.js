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

		search: function (term, page) {
			var self = this;
			this.ui.results.html('<li class="epm-episode-select__loading">' + epmEpisodeSelect.loading + '</li>');
			this.ui.results.removeAttr('hidden');

			$.post(epmEpisodeSelect.ajaxUrl, {
				action: 'epm_episode_search',
				_ajax_nonce: epmEpisodeSelect.nonce,
				s: term,
				page: page || 1
			}).done(function (response) {
				self.ui.results.empty();
				if (response && response.success && response.data && response.data.items && response.data.items.length) {
					$.each(response.data.items, function (i, item) {
						self.ui.results.append(
							$('<li role="option"></li>')
								.data('id', item.id)
								.text(item.title + (item.date ? ' — ' + item.date : ''))
						);
					});
					if (response.data.more) {
						var more = $('<li class="epm-episode-select__more"></li>').text('…');
						more.on('click', function (e) {
							e.stopPropagation();
							self.search(term, (page || 1) + 1);
						});
						self.ui.results.append(more);
					}
				} else {
					self.ui.results.append('<li class="epm-episode-select__empty">' + epmEpisodeSelect.noResults + '</li>');
				}
			}).fail(function () {
				self.ui.results.html('<li class="epm-episode-select__empty">' + epmEpisodeSelect.noResults + '</li>');
			});
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
					var item = response.data.items[0];
					self.ui.current.text(item.title + (item.date ? ' — ' + item.date : ''));
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
