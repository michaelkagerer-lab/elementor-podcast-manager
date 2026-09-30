/**
 * Shared helpers for the browser suites (not a suite itself: run-all.sh
 * skips lib.mjs).
 *
 *   import { BASE, wp, php, assert, finish, launch, newPage, login } from './lib.mjs';
 */
import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';

export const BASE = process.env.WP_URL || 'http://localhost:8889';
const WP = process.env.WP_CLI || 'wp';

/** WP-CLI; other plugins may print notices, so callers pick lines out. */
export const wp = (args, env = {}) =>
	execFileSync(WP, args, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], env: { ...process.env, ...env } });

/** First output line starting with a prefix. */
export const line = (output, prefix) => output.split('\n').find((l) => l.startsWith(prefix)) || '';

/**
 * Run PHP inside WordPress and return what it echoes as JSON
 * (`echo wp_json_encode( … )` in the code). Marked so notices cannot
 * garble it.
 */
export const php = (code) => {
	const out = wp(['eval', `echo "\\nEPMJSON:"; ${code}; echo "\\n";`]);
	const found = line(out, 'EPMJSON:').slice(8);
	return found ? JSON.parse(found) : null;
};

export const fixtures = () => JSON.parse(line(wp(['option', 'get', 'epm_test_fixtures', '--format=json']), '{'));

fs.mkdirSync('screenshots', { recursive: true });

let failures = 0;

/** One checked expectation, printed like the other suites. */
export const assert = (condition, message) => {
	console.log(`  ${condition ? '✓' : '✗'} ${message}`);
	if (!condition) {
		failures++;
	}
};

export const launch = () =>
	chromium.launch({
		headless: true,
		...(process.env.CHROMIUM_PATH ? { executablePath: process.env.CHROMIUM_PATH } : {}),
	});

/**
 * A page in its own context. Third-party requests are blocked (hermetic);
 * page errors and the plugin's console errors are collected in
 * page.problems.
 */
export async function newPage(browser, viewport = { width: 1280, height: 900 }) {
	const context = await browser.newContext({ viewport });
	await context.route((url) => url.origin !== new URL(BASE).origin, (route) => route.abort('blockedbyclient'));
	const page = await context.newPage();
	page.problems = [];
	page.on('pageerror', (e) => page.problems.push(`pageerror: ${e.message}`));
	page.on('console', (m) => {
		if (m.type() !== 'error') {
			return;
		}
		const where = `${m.text()} ${(m.location() && m.location().url) || ''}`;
		// Blocked third-party requests are expected; WordPress core logs
		// its own development warnings.
		if (/ERR_BLOCKED_BY_CLIENT|net::ERR_FAILED/.test(where) || !/elementor-podcast-manager|epm/i.test(where)) {
			return;
		}
		page.problems.push(`console: ${where}`);
	});
	return page;
}

/**
 * Sign in as admin. The login form occasionally swallows the first submit
 * on a busy machine (no request reaches the server), so it is tried again.
 */
export async function login(page) {
	for (let attempt = 1; ; attempt++) {
		await page.goto(`${BASE}/wp-login.php`);
		await page.fill('#user_login', 'admin');
		await page.fill('#user_pass', 'admin');
		try {
			await Promise.all([page.waitForURL(/\/wp-admin\//, { timeout: 20000 }), page.click('#wp-submit')]);
			return;
		} catch (error) {
			const state = await page
				.evaluate(() => ({ url: location.href, user: document.querySelector('#user_login')?.value, pass: (document.querySelector('#user_pass')?.value || '').length }))
				.catch(() => ({}));
			console.log(`  (login attempt ${attempt} did not finish: ${JSON.stringify(state)})`);
			if (attempt >= 3) {
				throw error;
			}
		}
	}
}

/** No horizontal scrolling. */
export const noOverflow = (page) => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth);

/** Description of the focused element, for messages. */
export const focused = (page) =>
	page.evaluate(() => {
		const el = document.activeElement;
		if (!el || el === document.body) {
			return 'body';
		}
		return `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${el.name ? `[name=${el.name}]` : ''}${el.getAttribute('data-panel') ? `[data-panel=${el.getAttribute('data-panel')}]` : ''}`;
	});

/**
 * Whether the focused element shows a focus indicator (outline or box
 * shadow), as keyboard users need.
 */
export const focusRingVisible = (page) =>
	page.evaluate(() => {
		const el = document.activeElement;
		if (!el || el === document.body) {
			return false;
		}
		const ring = (node) => {
			const style = getComputedStyle(node);
			const transparent = /^(transparent|rgba\(\d+, \d+, \d+, 0\))$/.test(style.outlineColor);
			const outline = style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0 && !transparent;
			return outline || (style.boxShadow && style.boxShadow !== 'none');
		};
		// Radio and checkbox cards draw the ring on their label.
		const label = el.closest('label');
		return ring(el) || (!!label && ring(label));
	});

/**
 * Press Tab (or Shift+Tab with back: true) until the predicate holds for
 * the focused element: keyboard-only navigation.
 */
export async function tabTo(page, predicate, { back = false, max = 80 } = {}) {
	for (let i = 0; i < max; i++) {
		await page.keyboard.press(back ? 'Shift+Tab' : 'Tab');
		if (await page.evaluate(predicate)) {
			return true;
		}
	}
	return false;
}

export function finish(name) {
	console.log(failures ? `\n${failures} ${name} check(s) failed.` : `\nAll ${name} checks passed.`);
	process.exit(failures ? 1 : 0);
}
