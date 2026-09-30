/**
 * EPM player engine — the ONE audio implementation (v2).
 *
 * Architecture:
 * - PlaybackController: one class wrapping ONE HTMLAudioElement. Every
 *   playback source (full player, card/row button, sticky bar, chapter
 *   seek) talks to a controller, never to a raw audio element.
 * - Registry: controllers are keyed by episode ID, so every UI bound to
 *   the same episode shares one controller and one audio element.
 * - Views subscribe to controller events; disconnected views are pruned
 *   lazily, so removed DOM nodes never leak listeners.
 * - init(scope) is idempotent: per-element flags guarantee exactly one
 *   binding per element, no matter how often Elementor re-renders.
 *
 * Markup contract (see Renderer::player(), frozen):
 *   [data-epm-player]            root, data-epm-episode-id/src/title/artwork/duration
 *     audio                      native element (hidden)
 *     [data-epm-play]            play/pause toggle (data-label-play/data-label-pause)
 *     [data-epm-timeline]        slider (role=slider)
 *     [data-epm-progress]        played fill
 *     [data-epm-handle]          timeline handle (positioned via left %)
 *     [data-epm-current]         current time label
 *     [data-epm-total]           total time label
 *     [data-epm-seek-rel]        relative seek buttons
 *     [data-epm-speed]           speed cycle button
 *     [data-epm-volume]          volume range input
 *   [data-epm-card-play="{id}"]  card/row play buttons (data-epm-src, data-epm-title)
 *   [data-epm-chapters]          chapter list (data-epm-episode-id/src/title)
 *     [data-epm-seek="{sec}"]    chapter seek buttons
 *   [data-epm-sticky]            footer sticky shell (hidden until playback)
 *
 * Vanilla JS, no frameworks, no jQuery.
 */
(function () {
	'use strict';

	/* ------------------------------------------------------------------ */
	/* Localized strings (window.epmPlayer.strings via wp_localize_script, */
	/* English fallback so nothing breaks when localization is absent).   */
	/* ------------------------------------------------------------------ */

	var STR = {
		play: 'Play',
		pause: 'Pause',
		playEpisode: 'Play episode',
		pauseEpisode: 'Pause episode',
		playPause: 'Play or pause',
		audioError: 'This audio could not be loaded. Check your connection and try again.',
		retry: 'Retry',
		speedChanged: 'Playback speed: %s'
	};

	var CONFIG = {
		podcastTitle: '',
		resume: true
	};

	try {
		var _settings = window.epmPlayer || {};
		var _localized = _settings.strings || {};
		Object.keys(STR).forEach(function (key) {
			if (typeof _localized[key] === 'string' && _localized[key] !== '') {
				STR[key] = _localized[key];
			}
		});
		if (typeof _settings.podcastTitle === 'string') {
			CONFIG.podcastTitle = _settings.podcastTitle;
		}
		if (_settings.resume === false || _settings.resume === '0' || _settings.resume === '') {
			CONFIG.resume = false;
		}
	} catch (e) { /* localization is optional */ }

	var SPEEDS = [1, 1.25, 1.5, 2, 0.75];

	/* ------------------------------------------------------------------ */
	/* Per-visitor memory: resume position per episode, preferred speed.   */
	/* Best-effort: storage may be unavailable (privacy modes, quotas).    */
	/* ------------------------------------------------------------------ */

	var Store = {
		get: function (key) {
			try {
				return window.localStorage ? window.localStorage.getItem('epm:' + key) : null;
			} catch (e) {
				return null;
			}
		},
		set: function (key, value) {
			try {
				if (window.localStorage) {
					if (value === null) {
						window.localStorage.removeItem('epm:' + key);
					} else {
						window.localStorage.setItem('epm:' + key, String(value));
					}
				}
			} catch (e) { /* storage is optional */ }
		}
	};

	function formatTime(seconds) {
		seconds = Math.max(0, Math.floor(seconds || 0));
		var h = Math.floor(seconds / 3600);
		var m = Math.floor((seconds % 3600) / 60);
		var s = seconds % 60;
		if (h > 0) {
			return h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
		}
		return m + ':' + String(s).padStart(2, '0');
	}

	function clickRatio(e, el) {
		var rect = el.getBoundingClientRect();
		if (!rect.width) {
			return 0;
		}
		var x = (e.clientX - rect.left) / rect.width;
		return Math.min(1, Math.max(0, x));
	}

	/* ------------------------------------------------------------------ */
	/* Screen-reader announcements (single polite live region).            */
	/* ------------------------------------------------------------------ */

	var liveRegion = null;

	function announce(message) {
		if (!message || !document.body) {
			return;
		}
		try {
			if (!liveRegion) {
				liveRegion = document.createElement('div');
				liveRegion.className = 'epm-sr-only';
				liveRegion.setAttribute('aria-live', 'polite');
				document.body.appendChild(liveRegion);
			}
			liveRegion.textContent = '';
			window.setTimeout(function () {
				if (liveRegion) {
					liveRegion.textContent = message;
				}
			}, 30);
		} catch (e) { /* announcing is best-effort */ }
	}

	/* ------------------------------------------------------------------ */
	/* PlaybackController: the complete playback contract.                 */
	/*                                                                     */
	/* Methods: play, pause, toggle, seekRelative, seekAbsolute,           */
	/*   seekRatio, cycleSpeed, setVolume, getDuration, getSpeedLabel,     */
	/*   isPlaying, subscribe, unsubscribe.                                */
	/* Events: play, pause, time, ended, error, speed, loaded.             */
	/* ------------------------------------------------------------------ */

	function PlaybackController(episodeId, audio, meta) {
		this.episodeId = String(episodeId);
		this.audio = audio;
		this.meta = meta || {};
		this.speedIndex = 0;
		this.views = [];
		this._restored = false;
		this._lastSaved = 0;
		this._attachAudioEvents();

		// Preferred speed carries over between episodes and visits.
		var speed = parseFloat(Store.get('speed'));
		var index = SPEEDS.indexOf(speed);
		if (index > 0) {
			this.speedIndex = index;
			this.audio.defaultPlaybackRate = SPEEDS[index];
			this.audio.playbackRate = SPEEDS[index];
		}

		// Every controller keeps all card/row buttons and chapter lists of
		// its episode in sync. documentElement never disconnects, so this
		// single subscription lives exactly as long as the controller.
		var self = this;
		this.subscribe({
			el: document.documentElement,
			onEvent: function (controller, eventName) {
				if (eventName === 'play' || eventName === 'pause' ||
					eventName === 'ended' || eventName === 'error') {
					syncCardButtons(self.episodeId);
				}
				if (eventName === 'time' || eventName === 'loaded' || eventName === 'play') {
					syncChapters(self);
				}
				self._remember(eventName);
				if (Registry.active === self) {
					MediaSessionBridge.update(self, eventName);
				}
			}
		});

		// Metadata may already be loaded (preload="metadata").
		if (this.audio.readyState >= 1) {
			this._restorePosition();
		}
	}

	/**
	 * Resume where the visitor left off (per episode, per browser).
	 */
	PlaybackController.prototype._restorePosition = function () {
		if (this._restored || !CONFIG.resume) {
			return;
		}
		this._restored = true;
		var saved = parseFloat(Store.get('pos:' + this.episodeId));
		var d = this.getDuration();
		if (saved > 5 && (!d || saved < d - 5) && (this.audio.currentTime || 0) < 1) {
			try {
				this.audio.currentTime = saved;
			} catch (e) { /* seeking may fail before data is available */ }
		}
	};

	PlaybackController.prototype._remember = function (eventName) {
		if (!CONFIG.resume) {
			return;
		}
		if (eventName === 'loaded') {
			this._restorePosition();
			return;
		}
		if (eventName === 'ended') {
			Store.set('pos:' + this.episodeId, null);
			return;
		}
		var t = this.audio.currentTime || 0;
		if (eventName === 'pause' || (eventName === 'time' && Math.abs(t - this._lastSaved) >= 5)) {
			this._lastSaved = t;
			Store.set('pos:' + this.episodeId, t > 5 ? Math.floor(t) : null);
		}
	};

	PlaybackController.prototype._attachAudioEvents = function () {
		var self = this;
		var map = [
			['play', 'play'],
			['pause', 'pause'],
			['timeupdate', 'time'],
			['ended', 'ended'],
			['loadedmetadata', 'loaded'],
			['durationchange', 'loaded']
		];
		map.forEach(function (pair) {
			self.audio.addEventListener(pair[0], function () {
				self._emit(pair[1]);
			});
		});
		self.audio.addEventListener('error', function () {
			self._emit('error', { kind: 'media' });
		});
	};

	PlaybackController.prototype.subscribe = function (view) {
		if (!view || !view.el || typeof view.onEvent !== 'function') {
			return;
		}
		for (var i = 0; i < this.views.length; i++) {
			if (this.views[i].el === view.el) {
				return; // already subscribed: idempotent
			}
		}
		this.views.push(view);
	};

	PlaybackController.prototype.unsubscribe = function (view) {
		this.views = this.views.filter(function (v) {
			return v !== view;
		});
	};

	PlaybackController.prototype._emit = function (eventName, detail) {
		var self = this;
		// Prune views whose DOM was removed; listeners die with the nodes.
		this.views = this.views.filter(function (view) {
			if (!view.el || !view.el.isConnected) {
				return false;
			}
			try {
				view.onEvent(self, eventName, detail);
			} catch (e) { /* a view must never break playback */ }
			return true;
		});
	};

	PlaybackController.prototype.play = function () {
		var self = this;
		Registry.pauseOthers(this);
		Registry.setActive(this);
		try {
			var promise = this.audio.play();
			if (promise && typeof promise.catch === 'function') {
				promise.catch(function (err) {
					// Autoplay policies, interruptions, etc.
					self._emit('error', { kind: 'play', error: err });
				});
			}
		} catch (err) {
			this._emit('error', { kind: 'play', error: err });
		}
	};

	PlaybackController.prototype.pause = function () {
		try {
			this.audio.pause();
		} catch (e) { /* noop */ }
	};

	PlaybackController.prototype.toggle = function () {
		if (this.audio.paused) {
			this.play();
		} else {
			this.pause();
		}
	};

	PlaybackController.prototype.seekRelative = function (seconds) {
		this.seekAbsolute(this.audio.currentTime + seconds);
	};

	PlaybackController.prototype.seekAbsolute = function (seconds) {
		var d = this.getDuration();
		var t = Math.max(0, seconds || 0);
		if (d > 0) {
			t = Math.min(t, d);
		}
		try {
			this.audio.currentTime = t;
		} catch (e) { /* seek before metadata is allowed to fail silently */ }
		this._emit('time');
	};

	PlaybackController.prototype.seekRatio = function (ratio) {
		this.seekAbsolute(ratio * this.getDuration());
	};

	PlaybackController.prototype.cycleSpeed = function () {
		this.speedIndex = (this.speedIndex + 1) % SPEEDS.length;
		this.audio.defaultPlaybackRate = SPEEDS[this.speedIndex];
		this.audio.playbackRate = SPEEDS[this.speedIndex];
		Store.set('speed', SPEEDS[this.speedIndex]);
		this._emit('speed');
	};

	PlaybackController.prototype.setVolume = function (value) {
		var v = parseFloat(value);
		if (!isFinite(v)) {
			return;
		}
		this.audio.volume = Math.min(1, Math.max(0, v));
	};

	PlaybackController.prototype.getDuration = function () {
		var d = this.audio.duration;
		if (d && isFinite(d) && d > 0) {
			return d;
		}
		var m = parseFloat(this.meta.duration);
		return (m && isFinite(m) && m > 0) ? m : 0;
	};

	PlaybackController.prototype.getSpeedLabel = function () {
		var s = SPEEDS[this.speedIndex];
		return (s === 1 ? '1' : String(s)) + '×';
	};

	PlaybackController.prototype.isPlaying = function () {
		return !this.audio.paused;
	};

	/* ------------------------------------------------------------------ */
	/* Registry: controllers keyed by episode ID.                          */
	/* ------------------------------------------------------------------ */

	var Registry = {
		controllers: {},
		active: null,

		get: function (episodeId, factory) {
			var id = String(episodeId);
			if (!id) {
				return null;
			}
			var controller = this.controllers[id];
			if (!controller && typeof factory === 'function') {
				controller = factory();
				if (controller) {
					this.controllers[id] = controller;
				}
			}
			return controller || null;
		},

		/**
		 * Pause every other episode's controller. Positions are kept.
		 */
		pauseOthers: function (except) {
			Object.keys(this.controllers).forEach(function (id) {
				var controller = Registry.controllers[id];
				if (controller && controller !== except && controller.isPlaying()) {
					controller.pause();
				}
			});
		},

		setActive: function (controller) {
			this.active = controller;
			Sticky.attach(controller);
			MediaSessionBridge.update(controller, 'attach');
		}
	};

	/* ------------------------------------------------------------------ */
	/* Media Session: lock screen, headset and OS media controls.         */
	/* ------------------------------------------------------------------ */

	var MediaSessionBridge = {
		bound: false,

		supported: function () {
			return 'mediaSession' in navigator && typeof window.MediaMetadata === 'function';
		},

		bind: function () {
			if (this.bound || !this.supported()) {
				return;
			}
			this.bound = true;
			var handlers = {
				play: function () { if (Registry.active) { Registry.active.play(); } },
				pause: function () { if (Registry.active) { Registry.active.pause(); } },
				seekbackward: function (d) { if (Registry.active) { Registry.active.seekRelative(-((d && d.seekOffset) || 15)); } },
				seekforward: function (d) { if (Registry.active) { Registry.active.seekRelative((d && d.seekOffset) || 30); } },
				seekto: function (d) { if (Registry.active && d && typeof d.seekTime === 'number') { Registry.active.seekAbsolute(d.seekTime); } }
			};
			Object.keys(handlers).forEach(function (action) {
				try {
					navigator.mediaSession.setActionHandler(action, handlers[action]);
				} catch (e) { /* action not supported by this browser */ }
			});
		},

		update: function (controller, eventName) {
			if (!this.supported() || !controller) {
				return;
			}
			try {
				this.bind();
				if (eventName === 'attach' || eventName === 'play') {
					var meta = controller.meta || {};
					navigator.mediaSession.metadata = new window.MediaMetadata({
						title: meta.title || '',
						artist: CONFIG.podcastTitle || '',
						album: CONFIG.podcastTitle || '',
						artwork: meta.artwork ? [{ src: meta.artwork }] : []
					});
				}
				if (eventName === 'play' || eventName === 'pause' || eventName === 'ended') {
					navigator.mediaSession.playbackState = controller.isPlaying() ? 'playing' : 'paused';
				}
				var d = controller.getDuration();
				if (d > 0 && typeof navigator.mediaSession.setPositionState === 'function' &&
					(eventName === 'loaded' || eventName === 'speed' || eventName === 'play' || eventName === 'pause')) {
					navigator.mediaSession.setPositionState({
						duration: d,
						playbackRate: controller.audio.playbackRate || 1,
						position: Math.min(d, controller.audio.currentTime || 0)
					});
				}
			} catch (e) { /* media session is best-effort */ }
		}
	};

	function makeAudio(src) {
		var audio = new Audio();
		audio.preload = 'metadata';
		if (src) {
			audio.src = src;
		}
		return audio;
	}

	/* ------------------------------------------------------------------ */
	/* Shared timeline interaction (click, drag, keyboard).                 */
	/* ------------------------------------------------------------------ */

	function sliderKeys(e, controller) {
		var handled = true;
		switch (e.key) {
			case 'ArrowLeft':
				controller.seekRelative(-5);
				break;
			case 'ArrowRight':
				controller.seekRelative(5);
				break;
			case 'PageDown':
				controller.seekRelative(-30);
				break;
			case 'PageUp':
				controller.seekRelative(30);
				break;
			case 'Home':
				controller.seekAbsolute(0);
				break;
			case 'End':
				controller.seekAbsolute(controller.getDuration());
				break;
			default:
				handled = false;
		}
		if (handled) {
			e.preventDefault();
		}
	}

	function bindTimeline(timeline, controller) {
		var dragging = false;

		timeline.addEventListener('click', function (e) {
			controller.seekRatio(clickRatio(e, timeline));
		});

		timeline.addEventListener('keydown', function (e) {
			sliderKeys(e, controller);
		});

		timeline.addEventListener('pointerdown', function (e) {
			dragging = true;
			try {
				timeline.setPointerCapture(e.pointerId);
			} catch (err) { /* optional */ }
			controller.seekRatio(clickRatio(e, timeline));
		});

		timeline.addEventListener('pointermove', function (e) {
			if (dragging) {
				controller.seekRatio(clickRatio(e, timeline));
			}
		});

		var endDrag = function () {
			dragging = false;
		};
		timeline.addEventListener('pointerup', endDrag);
		timeline.addEventListener('pointercancel', endDrag);
	}

	/* ------------------------------------------------------------------ */
	/* Full player view.                                                   */
	/* ------------------------------------------------------------------ */

	function updatePlayerUI(root, refs, controller, eventName) {
		var d = controller.getDuration();
		var t = controller.audio.currentTime || 0;
		var pct = d > 0 ? (t / d) * 100 : 0;

		if (refs.progress) {
			refs.progress.style.width = pct + '%';
		}
		if (refs.handle) {
			refs.handle.style.left = pct + '%';
		}
		if (refs.current) {
			refs.current.textContent = formatTime(t);
		}
		if (refs.total) {
			refs.total.textContent = formatTime(d);
		}
		if (refs.timeline) {
			refs.timeline.setAttribute('aria-valuemin', '0');
			refs.timeline.setAttribute('aria-valuemax', String(Math.floor(d)));
			refs.timeline.setAttribute('aria-valuenow', String(Math.floor(t)));
		}

		var playing = controller.isPlaying();
		root.classList.toggle('is-playing', playing);

		if (refs.play) {
			refs.play.classList.toggle('is-playing', playing);
			refs.play.setAttribute(
				'aria-label',
				playing
					? (refs.play.dataset.labelPause || STR.pauseEpisode)
					: (refs.play.dataset.labelPlay || STR.playEpisode)
			);
		}

		if (refs.speed) {
			refs.speed.textContent = controller.getSpeedLabel();
		}

		if (eventName === 'error') {
			showPlayerError(root, refs, controller);
		} else if (eventName === 'play') {
			hidePlayerError(root, refs);
		}

		if (eventName === 'speed') {
			announce(STR.speedChanged.replace('%s', controller.getSpeedLabel()));
		}
	}

	function showPlayerError(root, refs, controller) {
		try {
			if (!refs.error) {
				var box = document.createElement('div');
				box.className = 'epm-player__error';
				box.setAttribute('role', 'alert');

				var msg = document.createElement('span');
				msg.className = 'epm-player__error-msg';

				var retry = document.createElement('button');
				retry.type = 'button';
				retry.className = 'epm-player__error-retry';
				retry.textContent = STR.retry;
				retry.addEventListener('click', function () {
					controller.play();
				});

				box.appendChild(msg);
				box.appendChild(retry);

				var main = root.querySelector('.epm-player__main') || root;
				main.appendChild(box);

				refs.error = box;
				refs.errorMsg = msg;
			}
			refs.errorMsg.textContent = STR.audioError;
			refs.error.hidden = false;
			root.classList.add('has-error');
		} catch (e) { /* error UI is best-effort */ }
	}

	function hidePlayerError(root, refs) {
		if (refs.error) {
			refs.error.hidden = true;
		}
		root.classList.remove('has-error');
	}

	/**
	 * Init-failure fallback: expose the native audio element with controls
	 * so the episode stays playable and accessible.
	 */
	function fallbackToNative(root, audio) {
		try {
			root.classList.add('epm-player--fallback');
			audio.setAttribute('controls', '');
			audio.removeAttribute('style');
		} catch (e) { /* noop */ }
	}

	function bindFullPlayer(root) {
		if (!root || root.nodeType !== 1 || root.dataset.epmInitialized) {
			return;
		}
		var audio = root.querySelector('audio');
		var episodeId = root.dataset.epmEpisodeId || '';
		if (!audio || !episodeId) {
			return;
		}

		var refs = {
			play: root.querySelector('[data-epm-play]'),
			timeline: root.querySelector('[data-epm-timeline]'),
			progress: root.querySelector('[data-epm-progress]'),
			handle: root.querySelector('[data-epm-handle]'),
			current: root.querySelector('[data-epm-current]'),
			total: root.querySelector('[data-epm-total]'),
			speed: root.querySelector('[data-epm-speed]'),
			volume: root.querySelector('[data-epm-volume]'),
			error: null,
			errorMsg: null
		};

		try {
			// Share one controller per episode across all representations.
			var controller = Registry.get(episodeId, function () {
				return new PlaybackController(episodeId, audio, {
					title: root.dataset.epmTitle || '',
					artwork: root.dataset.epmArtwork || '',
					duration: root.dataset.epmDuration || 0
				});
			});
			if (!controller) {
				return;
			}

			if (refs.play) {
				refs.play.addEventListener('click', function () {
					controller.toggle();
				});
			}

			root.querySelectorAll('[data-epm-seek-rel]').forEach(function (btn) {
				btn.addEventListener('click', function () {
					controller.seekRelative(parseInt(btn.dataset.epmSeekRel, 10) || 0);
				});
			});

			if (refs.timeline) {
				bindTimeline(refs.timeline, controller);
			}

			if (refs.speed) {
				refs.speed.addEventListener('click', function () {
					controller.cycleSpeed();
				});
			}

			if (refs.volume) {
				refs.volume.addEventListener('input', function () {
					controller.setVolume(refs.volume.value);
				});
				refs.volume.value = String(controller.audio.volume);
			}

			controller.subscribe({
				el: root,
				onEvent: function (c, eventName, detail) {
					updatePlayerUI(root, refs, c, eventName, detail);
				}
			});

			updatePlayerUI(root, refs, controller, 'init');
			root.dataset.epmInitialized = '1';
		} catch (err) {
			root.dataset.epmInitialized = '1';
			fallbackToNative(root, audio);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Card / row play buttons. All buttons of one episode update together. */
	/* ------------------------------------------------------------------ */

	function syncCardButtons(episodeId) {
		var controller = Registry.get(String(episodeId));
		var playing = controller ? controller.isPlaying() : false;
		var selector = '[data-epm-card-play="' + String(episodeId).replace(/"/g, '') + '"]';
		document.querySelectorAll(selector).forEach(function (btn) {
			if (!btn.isConnected) {
				return;
			}
			var title = btn.dataset.epmTitle || '';
			btn.classList.toggle('is-playing', playing);
			btn.setAttribute('aria-pressed', playing ? 'true' : 'false');
			btn.setAttribute('aria-label', (playing ? STR.pause : STR.play) + (title ? ' ' + title : ''));
			var label = btn.querySelector('span');
			if (label) {
				label.textContent = playing ? STR.pause : STR.play;
			}
		});
	}

	/**
	 * Mark the chapter currently playing in every chapter list of the
	 * controller's episode.
	 */
	function syncChapters(controller) {
		var selector = '[data-epm-chapters][data-epm-episode-id="' + String(controller.episodeId).replace(/"/g, '') + '"]';
		var lists = document.querySelectorAll(selector);
		if (!lists.length) {
			return;
		}
		var t = controller.audio.currentTime || 0;
		var started = t > 0 || controller.isPlaying();
		lists.forEach(function (list) {
			var buttons = list.querySelectorAll('[data-epm-seek]');
			var active = -1;
			if (started) {
				for (var i = 0; i < buttons.length; i++) {
					if ((parseInt(buttons[i].dataset.epmSeek, 10) || 0) <= t + 0.25) {
						active = i;
					}
				}
			}
			for (var j = 0; j < buttons.length; j++) {
				var item = buttons[j].closest('li') || buttons[j];
				var isActive = j === active;
				if (item.classList.contains('is-active') !== isActive) {
					item.classList.toggle('is-active', isActive);
					if (isActive) {
						buttons[j].setAttribute('aria-current', 'true');
					} else {
						buttons[j].removeAttribute('aria-current');
					}
				}
			}
		});
	}

	function bindCardButton(btn) {
		if (!btn || btn.nodeType !== 1 || btn.dataset.epmCardBound) {
			return;
		}
		var episodeId = btn.dataset.epmCardPlay || '';
		if (!episodeId) {
			return;
		}
		btn.dataset.epmCardBound = '1';

		// Exactly one click listener per button. State sync for all
		// duplicate buttons of the episode is handled by the controller's
		// own card-sync subscription (see PlaybackController).
		btn.addEventListener('click', function () {
			var controller = Registry.get(episodeId, function () {
				var src = btn.dataset.epmSrc || '';
				if (!src) {
					return null;
				}
				return new PlaybackController(episodeId, makeAudio(src), {
					title: btn.dataset.epmTitle || '',
					artwork: btn.dataset.epmArtwork || '',
					duration: btn.dataset.epmDuration || 0
				});
			});
			if (controller) {
				controller.toggle();
			}
		});
	}

	/* ------------------------------------------------------------------ */
	/* Chapters: always bound to their own episode ID.                     */
	/* ------------------------------------------------------------------ */

	function bindChapters(container) {
		if (!container || container.nodeType !== 1 || container.dataset.epmChaptersBound) {
			return;
		}
		var episodeId = container.dataset.epmEpisodeId || '';
		if (!episodeId) {
			return;
		}
		container.dataset.epmChaptersBound = '1';

		// Delegated: exactly one listener per chapter list.
		container.addEventListener('click', function (e) {
			var target = e.target instanceof Element ? e.target : null;
			var btn = target ? target.closest('[data-epm-seek]') : null;
			if (!btn || !container.contains(btn)) {
				return;
			}
			var seconds = parseInt(btn.dataset.epmSeek, 10) || 0;
			var controller = Registry.get(episodeId, function () {
				var src = container.dataset.epmSrc || '';
				if (!src) {
					return null;
				}
				return new PlaybackController(episodeId, makeAudio(src), {
					title: container.dataset.epmTitle || '',
					artwork: '',
					duration: 0
				});
			});
			if (!controller) {
				return;
			}
			controller.seekAbsolute(seconds);
			if (!controller.isPlaying()) {
				controller.play();
			}
		});
	}

	/* ------------------------------------------------------------------ */
	/* Sticky mini player: mirrors the active controller's real audio.     */
	/* ------------------------------------------------------------------ */

	var Sticky = {
		root: null,
		refs: null,
		controller: null,
		view: null,
		initialized: false,

		init: function () {
			if (this.initialized) {
				return;
			}
			this.initialized = true;

			var root = document.querySelector('[data-epm-sticky]');
			if (!root) {
				return;
			}
			this.root = root;

			var self = this;
			this.refs = {
				play: root.querySelector('[data-epm-play]'),
				speed: root.querySelector('[data-epm-speed]'),
				close: root.querySelector('[data-epm-sticky-close]'),
				timeline: root.querySelector('[data-epm-timeline]'),
				progress: root.querySelector('[data-epm-progress]'),
				current: root.querySelector('[data-epm-current]'),
				total: root.querySelector('[data-epm-total]'),
				title: root.querySelector('[data-epm-sticky-title]'),
				artwork: root.querySelector('[data-epm-sticky-artwork]')
			};

			if (this.refs.play) {
				this.refs.play.addEventListener('click', function () {
					if (self.controller) {
						self.controller.toggle();
					}
				});
			}
			if (this.refs.speed) {
				this.refs.speed.addEventListener('click', function () {
					if (self.controller) {
						self.controller.cycleSpeed();
					}
				});
			}
			if (this.refs.close) {
				this.refs.close.addEventListener('click', function () {
					if (self.controller) {
						self.controller.pause();
					}
					root.hidden = true;
				});
			}
			if (this.refs.timeline) {
				// Timeline controls bind lazily: the controller is only
				// known once playback starts (see attach()).
				this.refs.timeline.addEventListener('click', function (e) {
					if (self.controller) {
						self.controller.seekRatio(clickRatio(e, self.refs.timeline));
					}
				});
				this.refs.timeline.addEventListener('keydown', function (e) {
					if (self.controller) {
						sliderKeys(e, self.controller);
					}
				});
				var dragging = false;
				this.refs.timeline.addEventListener('pointerdown', function (e) {
					if (!self.controller) {
						return;
					}
					dragging = true;
					try {
						self.refs.timeline.setPointerCapture(e.pointerId);
					} catch (err) { /* optional */ }
					self.controller.seekRatio(clickRatio(e, self.refs.timeline));
				});
				this.refs.timeline.addEventListener('pointermove', function (e) {
					if (dragging && self.controller) {
						self.controller.seekRatio(clickRatio(e, self.refs.timeline));
					}
				});
				var endDrag = function () {
					dragging = false;
				};
				this.refs.timeline.addEventListener('pointerup', endDrag);
				this.refs.timeline.addEventListener('pointercancel', endDrag);
			}

			this.view = {
				el: root,
				onEvent: function (controller, eventName) {
					self.sync(controller, eventName);
				}
			};
		},

		attach: function (controller) {
			if (!this.root || !controller || !this.view) {
				return;
			}
			if (this.controller && this.controller !== controller) {
				this.controller.unsubscribe(this.view);
			}
			this.controller = controller;
			controller.subscribe(this.view);

			this.root.hidden = false;

			var meta = controller.meta || {};
			if (this.refs.title) {
				this.refs.title.textContent = meta.title || '';
			}
			if (this.refs.artwork) {
				var src = meta.artwork || '';
				this.refs.artwork.innerHTML = src
					? '<img src="' + String(src).replace(/"/g, '%22') + '" alt="" loading="lazy">'
					: '';
			}

			this.sync(controller, 'attach');
		},

		sync: function (controller, eventName) {
			if (!this.root || this.root.hidden || controller !== this.controller) {
				return;
			}
			var d = controller.getDuration();
			var t = controller.audio.currentTime || 0;
			var pct = d > 0 ? (t / d) * 100 : 0;

			if (this.refs.progress) {
				this.refs.progress.style.width = pct + '%';
			}
			if (this.refs.current) {
				this.refs.current.textContent = formatTime(t);
			}
			if (this.refs.total) {
				this.refs.total.textContent = formatTime(d);
			}
			if (this.refs.timeline) {
				this.refs.timeline.setAttribute('aria-valuemin', '0');
				this.refs.timeline.setAttribute('aria-valuemax', String(Math.floor(d)));
				this.refs.timeline.setAttribute('aria-valuenow', String(Math.floor(t)));
			}

			var playing = controller.isPlaying();
			if (this.refs.play) {
				this.refs.play.classList.toggle('is-playing', playing);
				this.refs.play.setAttribute('aria-label', playing ? STR.pause : STR.play);
			}
			if (this.refs.speed) {
				this.refs.speed.textContent = controller.getSpeedLabel();
			}
			if (eventName === 'speed') {
				announce(STR.speedChanged.replace('%s', controller.getSpeedLabel()));
			}
		}
	};

	/* ------------------------------------------------------------------ */
	/* Idempotent, scope-limited initialization.                           */
	/* ------------------------------------------------------------------ */

	function findAll(scope, selector) {
		var list = [];
		if (scope.nodeType === 1 && typeof scope.matches === 'function' && scope.matches(selector)) {
			list.push(scope);
		}
		var found = scope.querySelectorAll(selector);
		for (var i = 0; i < found.length; i++) {
			list.push(found[i]);
		}
		return list;
	}

	function init(scope) {
		if (!scope || (scope.nodeType !== 1 && scope.nodeType !== 9)) {
			return;
		}
		try {
			findAll(scope, '[data-epm-player]').forEach(bindFullPlayer);
			findAll(scope, '[data-epm-card-play]').forEach(bindCardButton);
			findAll(scope, '[data-epm-chapters]').forEach(bindChapters);
		} catch (e) {
			// Initialization must never break the page.
		}
		Sticky.init();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			init(document);
		});
	} else {
		init(document);
	}

	// Elementor: initialize per widget scope only. Never rebind document-wide.
	// This script usually loads before elementor-frontend.js, whose hooks
	// only exist after it fires "elementor/frontend/init" — so bind now if
	// possible, otherwise on that event.
	var elementorHooksBound = false;

	function bindElementorHooks() {
		var frontend = window.elementorFrontend;
		if (elementorHooksBound || !frontend || !frontend.hooks || typeof frontend.hooks.addAction !== 'function') {
			return elementorHooksBound;
		}
		elementorHooksBound = true;
		['epm-podcast-player', 'epm-episode-list', 'epm-latest-episode', 'epm-chapters'].forEach(function (widgetName) {
			frontend.hooks.addAction(
				'frontend/element_ready/' + widgetName,
				function ($scope) {
					init($scope && $scope[0] ? $scope[0] : $scope);
				}
			);
		});
		return true;
	}

	if (!bindElementorHooks() && window.jQuery) {
		window.jQuery(window).on('elementor/frontend/init', bindElementorHooks);
	}

	// Content inserted later (AJAX pagination, "load more", popups, page
	// builders' live previews) initializes too. init() is idempotent.
	var PLAYER_SELECTOR = '[data-epm-player], [data-epm-card-play], [data-epm-chapters]';

	if (typeof window.MutationObserver === 'function') {
		var pendingNodes = [];
		var flushScheduled = false;

		var flushPending = function () {
			flushScheduled = false;
			var nodes = pendingNodes;
			pendingNodes = [];
			nodes.forEach(function (node) {
				if (node.isConnected && (node.matches(PLAYER_SELECTOR) || node.querySelector(PLAYER_SELECTOR))) {
					init(node);
				}
			});
		};

		var observer = new window.MutationObserver(function (mutations) {
			for (var i = 0; i < mutations.length; i++) {
				var added = mutations[i].addedNodes;
				for (var j = 0; j < added.length; j++) {
					if (added[j].nodeType === 1) {
						pendingNodes.push(added[j]);
					}
				}
			}
			if (pendingNodes.length && !flushScheduled) {
				flushScheduled = true;
				window.setTimeout(flushPending, 0);
			}
		});

		var startObserving = function () {
			observer.observe(document.body || document.documentElement, { childList: true, subtree: true });
		};

		if (document.body) {
			startObserving();
		} else {
			document.addEventListener('DOMContentLoaded', startObserving);
		}
	}

	// Public API for integrations (e.g. custom AJAX loaders).
	window.epmPlayerEngine = {
		init: init,
		getController: function (episodeId) {
			return Registry.get(String(episodeId));
		}
	};
})();
