/** UX-N11: resume from the menu, warn before losing edits, clear corrected errors. */
import { BASE, php, assert, finish, launch, newPage, login } from './lib.mjs';
const saved = php("echo wp_json_encode( base64_encode( serialize( [ EPM\\AdminPages::SETUP_OPTION => get_option( EPM\\AdminPages::SETUP_OPTION, [] ), EPM\\Hosting::OPTION => get_option( EPM\\Hosting::OPTION, [] ), EPM\\Hosting::STATE_OPTION => get_option( EPM\\Hosting::STATE_OPTION, [] ) ] ) ) )");
const browser = await launch();
try {
 php("delete_option( EPM\\AdminPages::SETUP_OPTION ); echo wp_json_encode( true )");
 const page = await newPage(browser);
 await login(page);
 await page.goto(`${BASE}/wp-admin/admin.php?page=epm-setup`);
 await page.check('[name="path"][value="new"]');
 await page.click('[data-step-form="path"] [type="submit"]');
 await page.waitForFunction(() => !document.querySelector('[data-panel="show"]').hidden);
 await page.waitForTimeout(300);
 await page.goto(`${BASE}/wp-admin/admin.php?page=epm-setup`);
 const resumed = await page.locator('[data-panel="show"]').isVisible();
 assert(resumed, 'returning through the menu resumes the next unfinished step');
 if (!resumed) await page.goto(`${BASE}/wp-admin/admin.php?page=epm-setup&step=show`);
 assert(await page.evaluate(() => document.activeElement?.getAttribute('data-panel') === 'show'), 'the resumed step receives keyboard focus');
 await page.fill('[data-step-form="show"] [name="title"]', '');
 await page.click('[data-step-form="show"] [type="submit"]');
 assert(await page.locator('[name="title"]').getAttribute('aria-invalid') === 'true', 'an empty title is marked invalid');
 await page.fill('[data-step-form="show"] [name="title"]', 'Unsaved setup title');
 assert(await page.locator('[name="title"]').getAttribute('aria-invalid') !== 'true', 'correcting the title clears its stale error');
 let warned = false;
 page.once('dialog', async dialog => { warned = dialog.type() === 'beforeunload'; await dialog.dismiss(); });
 await page.goto(`${BASE}/wp-admin/`).catch(() => {});
 assert(warned, 'leaving with unsaved details triggers the browser warning');
 assert(page.url().includes('page=epm-setup'), 'dismissing the warning keeps the entered details');
 assert(page.problems.length === 0, `no browser errors: ${page.problems.join('; ')}`);
 await page.context().close();
} finally {
 php(`foreach ( unserialize( base64_decode( '${saved}' ) ) as $name => $value ) { update_option( $name, $value ); } echo wp_json_encode( true )`);
 await browser.close();
}
finish('setup-resume');
