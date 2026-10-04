/** Real Elementor panel: safe search states, keyboard selection and responsive resets. */
import AxeBuilder from '@axe-core/playwright';
import {BASE, php, assert, finish, launch, newPage, login, section} from './lib.mjs';
if (!php("echo wp_json_encode(is_file(dirname(rtrim(ABSPATH, '/\\\\')).'/.epm-test-site'));")) throw new Error('Requires a marked disposable test site.');
const browser = await launch();
let id;
const screenshotTag=process.env.EPM_TEST_LANGUAGE||'en_US';
const locale=php("echo wp_json_encode([get_option('WPLANG'),get_user_meta(1,'locale',true)]);");
if(process.env.EPM_TEST_LANGUAGE)php(`update_option('WPLANG',${JSON.stringify(process.env.EPM_TEST_LANGUAGE)});update_user_meta(1,'locale',${JSON.stringify(process.env.EPM_TEST_LANGUAGE)});echo 1;`);
try {
 id = php(`$id=wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>'Elementor reliability test']); $data=\\EPM\\AdminPages::starter_content('episode')['elements']; update_post_meta($id,'_elementor_data',wp_slash(wp_json_encode($data))); update_post_meta($id,'_elementor_edit_mode','builder'); update_post_meta($id,'_elementor_version',ELEMENTOR_VERSION); echo wp_json_encode($id);`);
 const page = await newPage(browser);
 await login(page);
 await page.goto(`${BASE}/wp-admin/post.php?post=${id}&action=elementor`);
 await page.waitForFunction(()=>window.elementor?.loaded&&window.epmEditor,null,{timeout:45000});
 await page.waitForFunction(()=>{try{return !!elementor.getContainer('epmstart1')?.view?.el.isConnected;}catch{return false;}});
 await page.keyboard.press('Escape');
 await page.evaluate(()=>{const c=elementor.getContainer('epmstart1'); $e.run('document/elements/settings',{container:c,settings:{source:'specific',episode_id:'101'},options:{external:true}}); $e.run('document/elements/select',{container:c}); $e.route('panel/editor/content',{model:c.model,view:c.view});});
 const search=page.locator('.epm-episode-select__search');
 await page.locator('.elementor-control-source select').waitFor();
 await page.locator('.elementor-control-source select').selectOption('specific');
 await search.waitFor();
 let mode='loading', deadRequests=0;
 await page.route('**/admin-ajax.php',async route=>{
  const data=new URLSearchParams(route.request().postData()||'');
  if(data.get('action')!=='epm_episode_search')return route.continue();
  if(mode==='bad-items')return route.fulfill({json:{success:true,data:{items:[null]}}});
  if(data.has('include')){
   if(mode==='current-error')return route.abort();
   if(mode==='current-missing')return route.fulfill({json:{success:true,data:{items:[]}}});
   if(mode==='current-race' && data.get('include')==='202')await new Promise(resolve=>setTimeout(resolve,600));
   return route.fulfill({json:{success:true,data:{items:[{id:Number(data.get('include')),title:'Selected episode '+data.get('include')}]}}});
  }
  if(data.get('s')==='destroyed-view')deadRequests++;
  if(mode==='invalid')return route.fulfill({json:{success:false,data:{message:'Denied'}}});
  if(mode==='loading') {await new Promise(resolve=>setTimeout(resolve,800));}
  if(mode==='error')return route.abort();
  return route.fulfill({json:{success:true,data:{items:mode==='empty'?[]:data.get('page')==='2'?[{id:204,title:'Page two episode'}]:[{id:202,title:'A very long episode title '.repeat(8)},{id:203,title:'Second episode'}],more:mode==='pagination'&&data.get('page')==='1'}}});
 });
 await section('Loading and empty states preserve the saved episode',async()=>{
  await search.fill('loading-state');
  const loading=page.locator('.epm-episode-select__loading');await loading.waitFor();await loading.evaluate(el=>el.click());
  assert(await page.evaluate(()=>String(elementor.getContainer('epmstart1').settings.get('episode_id')))==='101','clicking Loading cannot clear the episode');
  mode='empty';await search.fill('no-match');await page.locator('.epm-episode-select__empty').waitFor();await page.locator('.epm-episode-select__empty').evaluate(el=>el.click());
  assert(await page.evaluate(()=>String(elementor.getContainer('epmstart1').settings.get('episode_id')))==='101','clicking No results cannot clear the episode');
 });
 await section('Search error has a retry and keeps selection',async()=>{
  mode='error';await search.fill('network-failure');
  await page.waitForTimeout(700);
  const retry=page.locator('.epm-episode-select__retry');
  assert(await retry.count()===1,'network failure offers Retry instead of No results');
  if(await retry.count()){mode='results';await retry.click();await page.locator('[role="option"]').first().waitFor();assert(await page.locator('[role="option"]').count()===2,'retry recovers the same search');}
 });
 await section('Keyboard and accessible combobox',async()=>{
  mode='results';await search.fill('keyboard');await page.locator('.epm-episode-select [role="option"]').first().waitFor();
  assert(!!await search.getAttribute('aria-labelledby'),'search input is connected to its visible label');
  assert(!!await search.getAttribute('aria-controls'),'search input names its listbox');
  await search.press('ArrowDown');
  assert(!!await search.getAttribute('aria-activedescendant'),'keyboard focus announces an active episode');
  await search.press('Enter');
  assert(await page.evaluate(()=>String(elementor.getContainer('epmstart1').settings.get('episode_id')))==='202','Enter selects the active episode');
  await search.focus();await search.press('Escape');
  assert(await search.getAttribute('aria-expanded')==='false','Escape closes the list');
 });
 await section('Selected episode and failed responses stay honest',async()=>{
  mode='invalid';await search.fill('permission-error');await page.locator('.epm-episode-select__retry').waitFor();
  assert(await page.locator('.epm-episode-select__empty').count()===0,'an unsuccessful response is an error, not an empty catalog');
  mode='bad-items';await search.fill('malformed-items');await page.waitForTimeout(700);
  assert(await page.locator('.epm-episode-select__retry').isVisible(),'malformed result items produce a recoverable error');
  await page.evaluate(()=>elementor.getPanelView().getCurrentPageView().getControlViewByName('episode_id').renderCurrent());await page.waitForTimeout(300);
  assert(await page.locator('.epm-episode-select__current-retry').isVisible(),'malformed selected-episode data produces a recoverable error');
  mode='current-race';
  await page.evaluate(()=>{const v=elementor.getPanelView().getCurrentPageView().getControlViewByName('episode_id');v.setValue(202);v.renderCurrent();v.setValue(203);v.renderCurrent();});
  await page.waitForTimeout(800);
  assert((await page.locator('.epm-episode-select__current').textContent()).includes('203'),'a delayed selected-episode response cannot replace the current label');
  mode='current-error';await page.evaluate(()=>elementor.getPanelView().getCurrentPageView().getControlViewByName('episode_id').renderCurrent());
  await page.locator('.epm-episode-select__current-retry').waitFor();
  assert(await page.evaluate(()=>String(elementor.getContainer('epmstart1').settings.get('episode_id')))==='203','loading a selected episode can fail without changing its ID');
  mode='results';await page.locator('.epm-episode-select__current-retry').click();
  await page.waitForFunction(()=>document.querySelector('.epm-episode-select__current').textContent.includes('203'));
  assert(await page.locator('.epm-episode-select__current-retry').isHidden(),'selected episode retry recovers');
  mode='current-missing';await page.evaluate(()=>elementor.getPanelView().getCurrentPageView().getControlViewByName('episode_id').renderCurrent());
  await page.waitForFunction(()=>document.querySelector('.epm-episode-select__current').textContent===epmEpisodeSelect.unavailable);
  assert(await page.evaluate(()=>String(elementor.getContainer('epmstart1').settings.get('episode_id')))==='203','an unavailable episode keeps its ID until the user chooses otherwise');
 });
 await section('Pagination and long results fit the editor',async()=>{
  mode='pagination';await search.fill('paged');await page.locator('.epm-episode-select__more').waitFor();
  assert(await page.locator('.epm-episode-select__results').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'long result titles wrap inside the panel');
  const accessibility=await new AxeBuilder({page}).include('.epm-episode-select').analyze();
  assert(accessibility.violations.length===0,`search control has no axe violations: ${accessibility.violations.flatMap(v=>v.nodes.map(n=>v.id+': '+n.target.join(' ')+' '+n.failureSummary)).join(' | ')}`);
  await page.screenshot({path:`screenshots/elementor-reliability-${screenshotTag}-results.png`});
  await search.press('Tab');
  assert(await page.locator('.epm-episode-select__more').evaluate(el=>el===document.activeElement),'Load more is reachable with Tab');
  await page.keyboard.press('Enter');
  await page.waitForFunction(()=>document.querySelectorAll('.epm-episode-select [role="option"]').length===3);
  assert(await page.locator('.epm-episode-select [role="option"]').count()===3,'Load more appends episodes without replacing page one');
  await search.focus();await search.press('ArrowDown');await search.press('ArrowDown');await search.press('ArrowDown');await search.press('Enter');
  assert(await page.evaluate(()=>String(elementor.getContainer('epmstart1').settings.get('episode_id')))==='204','page two is reachable with the keyboard');
  await page.locator('.epm-episode-select__clear').click();
  assert(await page.evaluate(()=>elementor.getContainer('epmstart1').settings.get('episode_id'))==='','only Clear selection explicitly removes the ID');
 });
 await section('Responsive inheritance keeps other devices and supports Undo',async()=>{
  const result=await page.evaluate(async()=>{
   const c=elementor.getContainer('epmstart1');
   $e.run('document/elements/settings',{container:c,settings:{style_source:'custom',container_gap:{size:24,unit:'px'},container_gap_mobile:{size:9,unit:'px'}}});
   $e.internal('document/history/end-transaction');
   elementor.changeDeviceMode('mobile');
   elementor.channels.editor.trigger('epm:style:inherit:container_gap',{container:c});
   const desktop=c.settings.get('container_gap'),mobile=c.settings.get('container_gap_mobile');
   $e.run('document/history/undo');await new Promise(resolve=>setTimeout(resolve,200));
   return {desktop,mobile,undone:elementor.getContainer('epmstart1').settings.get('container_gap_mobile')};
  });
  assert(result.desktop.size===24,'mobile reset keeps the desktop gap');
  assert(result.mobile.size==='', 'mobile reset clears the mobile gap');
  assert(result.undone.size===9,'Undo restores the mobile override');
 });
 await section('Visible responsive reset button matches its device',async()=>{
  await page.locator('.elementor-panel-navigation-tab[data-tab="style"]').click();
  const buttons=page.locator('[class*="elementor-control-container_gap"][class*="_inherit"] button:visible');
  await buttons.first().waitFor();
  assert(await buttons.count()===1,'mobile shows exactly one gap reset button');
  await buttons.first().click();
  const values=await page.evaluate(()=>{const s=elementor.getContainer('epmstart1').settings;return {desktop:s.get('container_gap'),mobile:s.get('container_gap_mobile')};});
  assert(values.desktop.size===24&&values.mobile.size==='','the visible button resets mobile and keeps desktop');
  await page.screenshot({path:`screenshots/elementor-reliability-${screenshotTag}-mobile-reset.png`});
  await page.evaluate(()=>elementor.changeDeviceMode('desktop'));
  assert(await buttons.count()===1,'desktop shows exactly one gap reset button');
  await page.locator('.elementor-panel-navigation-tab[data-tab="content"]').click();
 });
 await section('Leaving the widget cancels debounced search',async()=>{
  mode='results';await search.fill('destroyed-view');
  await page.evaluate(()=>{const c=elementor.getContainer('epmstart2');$e.run('document/elements/select',{container:c});$e.route('panel/editor/content',{model:c.model,view:c.view});});
  await page.waitForTimeout(500);
  assert(deadRequests===0,'destroyed control does not send its pending search');
 });
 assert(page.problems.length===0,`no plugin browser errors: ${page.problems.join(' | ')}`);
 await page.screenshot({path:`screenshots/elementor-reliability-${screenshotTag}-editor.png`});
 await page.context().close();
} finally {if(process.env.EPM_TEST_LANGUAGE)php(`${locale[0]===false?"delete_option('WPLANG')":"update_option('WPLANG',"+JSON.stringify(locale[0])+")"};update_user_meta(1,'locale',${JSON.stringify(locale[1])});echo 1;`);if(id)php(`wp_delete_post(${id},true); echo 1;`);await browser.close();}
finish('Elementor reliability');
