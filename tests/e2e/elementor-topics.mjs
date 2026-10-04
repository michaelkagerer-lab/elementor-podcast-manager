/** Large topic catalogs: native search, saved labels, pagination and recovery. */
import AxeBuilder from '@axe-core/playwright';
import {BASE,php,assert,finish,launch,newPage,login} from './lib.mjs';
if(!php("echo wp_json_encode(is_file(dirname(rtrim(ABSPATH,'/\\\\')).'/.epm-test-site'));"))throw Error('Requires a marked disposable site.');
const browser=await launch();let data;
try{
 data=php(`$terms=[];for($i=0;$i<205;$i++){$t=wp_insert_term('Deep topic '.sprintf('%03d',$i),\\EPM\\Renderer::TOPIC_TAXONOMY,['slug'=>'epm-browser-deep-'.$i]);if(is_wp_error($t)){throw new RuntimeException($t->get_error_message());}$terms[]=$t['term_id'];}$id=wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>'Deep topics']);$elements=[['id'=>'topiccolumn','elType'=>'container','settings'=>[],'elements'=>[['id'=>'topicwidget','elType'=>'widget','widgetType'=>'epm-episode-list','settings'=>['topics'=>['epm-browser-deep-204']],'elements'=>[]]]]];update_post_meta($id,'_elementor_data',wp_slash(wp_json_encode($elements)));update_post_meta($id,'_elementor_edit_mode','builder');update_post_meta($id,'_elementor_version',ELEMENTOR_VERSION);echo wp_json_encode([$id,$terms]);`);
 const page=await newPage(browser);await login(page);await page.goto(`${BASE}/wp-admin/post.php?post=${data[0]}&action=elementor`);await page.waitForFunction(()=>window.elementor?.loaded&&window.epmTopicSelect,null,{timeout:45000});await page.keyboard.press('Escape');await page.waitForFunction(()=>{try{return !!elementor.getContainer('topicwidget')?.view?.el.isConnected;}catch{return false;}});
 await page.evaluate(()=>{window.epmSavedTopicLanguage=jQuery.fn.select2.defaults.defaults.language;jQuery.fn.select2.defaults.set('language',{noResults:()=> 'Native no-results sentinel'});const c=elementor.getContainer('topicwidget');$e.run('document/elements/select',{container:c});$e.route('panel/editor/content',{model:c.model,view:c.view});});
 const topics=page.locator('.elementor-control-topics');await topics.waitFor();
 await page.waitForFunction(()=>document.querySelector('.elementor-control-topics .select2-selection__choice')?.textContent.includes('Deep topic 204'),null,{timeout:5000});
 assert(await page.evaluate(()=>elementor.getContainer('topicwidget').settings.get('topics').join())==='epm-browser-deep-204','opening the editor preserves a saved topic outside the first 200');
 assert((await topics.textContent()).includes('Deep topic 204'),'saved topic is hydrated with its name');
 const panelAxe=await new AxeBuilder({page}).include('.elementor-control-topics .elementor-control-field-description').analyze();
 assert(!panelAxe.violations.length,'topic help has sufficient contrast: '+panelAxe.violations.map(v=>v.id).join(', '));
 const nonce=await page.evaluate(()=>epmTopicSelect.nonce);
 const second=await page.request.post(`${BASE}/wp-admin/admin-ajax.php`,{form:{action:'epm_topic_search',_ajax_nonce:nonce,s:'Deep topic',page:'2'}});
 const secondData=await second.json();assert(secondData.success&&secondData.data.results[0].id==='epm-browser-deep-30','the endpoint reaches the second page');
 const denied=await page.request.post(`${BASE}/wp-admin/admin-ajax.php`,{form:{action:'epm_topic_search',_ajax_nonce:'invalid'}});assert(denied.status()===403,'a missing or invalid nonce is rejected');
 const guestNonce=php("wp_set_current_user(0);echo wp_json_encode(wp_create_nonce('epm_topic_search'));");
 const guest=await browser.newContext();const guestResponse=await guest.request.post(`${BASE}/wp-admin/admin-ajax.php`,{form:{action:'epm_topic_search',_ajax_nonce:guestNonce}});assert(guestResponse.status()===400||guestResponse.status()===403,'unauthenticated topic search is rejected');await guest.close();
 await page.evaluate(()=>jQuery.fn.select2.defaults.set('language',window.epmSavedTopicLanguage));
 const search=topics.locator('.select2-search__field');await search.fill('epm-deep-no-matches');await page.locator('.select2-results__message').waitFor({timeout:5000});assert((await page.locator('.select2-results__message').textContent())==='Native no-results sentinel','the custom topic control preserves the native locale dictionary');await search.fill('Deep topic 203');await page.locator('.select2-results__option').filter({hasText:'Deep topic 203'}).click();
 assert(await page.evaluate(()=>elementor.getContainer('topicwidget').settings.get('topics').includes('epm-browser-deep-203')),'a topic beyond the initial options can be selected');
 await search.fill('Deep topic');await page.locator('.select2-results__option').filter({hasText:'Deep topic 000'}).waitFor();
 assert(await page.locator('.select2-results__option').count()<=31,'topic search is paginated rather than loading the whole catalog');
 await search.press('Escape');
 await page.route('**/admin-ajax.php',route=>{const q=new URLSearchParams(route.request().postData()||'');if(q.get('action')==='epm_topic_search'){return route.abort();}return route.continue();});
 await search.click();await search.fill('');await search.pressSequentially('network failure');await page.locator('.select2-results__message').waitFor({timeout:5000});
 assert((await page.locator('.select2-results__message').textContent()).includes('could not be loaded'),'network failure is explained rather than shown as no results');
 assert(await page.evaluate(()=>elementor.getContainer('topicwidget').settings.get('topics').length)===2,'failed search preserves both selected topics');
 await page.unroute('**/admin-ajax.php');await search.fill('Deep topic 202');await page.locator('.select2-results__option').filter({hasText:'Deep topic 202'}).waitFor();assert(true,'a new search recovers after failure');
 assert(!page.problems.length,'no plugin browser exceptions: '+page.problems.join(' | '));await page.context().close();
}finally{if(data)php(`wp_delete_post(${data[0]},true);foreach(${JSON.stringify(data[1])} as $id){wp_delete_term($id,\\EPM\\Renderer::TOPIC_TAXONOMY);}echo 1;`);await browser.close();}
finish('Elementor topics');
