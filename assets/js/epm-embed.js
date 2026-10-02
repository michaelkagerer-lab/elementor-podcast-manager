/**
 * EPM embed bridge — loads only inside /podcast/{slug}/embed/.
 *
 * Speaks the WordPress embed protocol with the embedding site (its
 * wp-embed.js adds "#?secret=…" to the frame address):
 * - "height": the card's height, so the frame fits it;
 * - "link": links open in the embedding window. WordPress sandboxes the
 *   frame, so a plain target="_top" link would be blocked there.
 *
 * Without a secret (an <iframe> copied with "Copy embed code", or the page
 * opened directly) nothing is intercepted and links work natively.
 *
 * Vanilla JS, no dependencies.
 */
(function () {
	'use strict';

	if (window.self === window.top) {
		return;
	}

	var secret = '';
	var ready = false;
	var resizeTimer = 0;

	function readSecret() {
		// WordPress may append a second secret to a previously embedded URL.
		var match = /.*secret=([\w]{10})/.exec(window.location.hash || '');
		var nextSecret = match ? match[1] : '';
		if (secret !== nextSecret) {
			ready = false;
		}
		secret = nextSecret;
	}

	function send(message, value) {
		if (!secret) {
			return;
		}
		try {
			window.parent.postMessage({ message: message, value: value, secret: secret }, '*');
		} catch (e) { /* the embedding site may be gone */ }
	}

	function sendHeight() {
		if (document.body) {
			send('height', Math.ceil(document.body.getBoundingClientRect().height));
		}
	}

	function onResize() {
		window.clearTimeout(resizeTimer);
		resizeTimer = window.setTimeout(sendHeight, 100);
	}

	function onClick(e) {
		if (!secret || !ready || e.defaultPrevented || e.button !== 0 || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) {
			return;
		}
		var target = e.target instanceof Element ? e.target.closest('a[href]') : null;
		if (!target) {
			return;
		}
		var href = target.getAttribute('href') || '';
		if (!/^https?:\/\//i.test(href)) {
			return;
		}
		send('link', href);
		e.preventDefault();
	}

	function onMessage(e) {
		var data = e.data;
		if (!secret || !data || e.source !== window.parent || data.secret !== secret) {
			return;
		}
		if (data.message === 'ready') {
			ready = true;
			sendHeight();
		}
	}

	readSecret();
	window.addEventListener('hashchange', readSecret);
	window.addEventListener('message', onMessage);
	window.addEventListener('resize', onResize);
	document.addEventListener('click', onClick);

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', sendHeight);
	} else {
		sendHeight();
	}
	window.addEventListener('load', sendHeight);

	if (typeof window.ResizeObserver === 'function') {
		try {
			new window.ResizeObserver(onResize).observe(document.documentElement);
		} catch (e) { /* the resize event covers it */ }
	}
})();
