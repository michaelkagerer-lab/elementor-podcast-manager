/** Deep review: all widgets, wide rich content, keyboard focus and video recovery. */
import AxeBuilder from '@axe-core/playwright';
import {php, fixtures, assert, finish, launch, newPage, noOverflow} from './lib.mjs';
if(!php("echo wp_json_encode(is_file(dirname(rtrim(ABSPATH,'/\\\\')).'/.epm-test-site'));"))throw Error('Requires a marked disposable site.');
const browser=await launch();let ids=[];
try{
 const fx=fixtures();
 const rich='<pre>'+('wide-code-token '.repeat(40))+'</pre><table><tbody><tr>'+Array.from({length:15},(_,i)=>'<th>Column '+i+'</th>').join('')+'</tr><tr>'+Array.from({length:15},()=>'<td>Long imported value</td>').join('')+'</tr></tbody></table><p><a href="#content-target">Show notes link</a></p>';
 const data=php(`$ep=wp_insert_post(['post_type'=>'podcast_episode','post_status'=>'publish','post_title'=>'Deep widget review']); foreach(get_post_meta(${fx.ep1}) as $key=>$values){if(str_starts_with($key,'_epm_')){update_post_meta($ep,$key,maybe_unserialize($values[0]));}} foreach(['show_notes'=>${JSON.stringify(rich)},'transcript'=>${JSON.stringify(rich)},'guest_bio'=>${JSON.stringify(rich)},'guest_url'=>'https://example.invalid/','video_url'=>home_url('/missing-deep-video.mp4'),'youtube_url'=>''] as $key=>$value){update_post_meta($ep,'_epm_'.$key,$value);} $id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Deep widgets']);$widgets=[];$types=[];foreach(\\Elementor\\Plugin::$instance->widgets_manager->get_widget_types() as $widget){if(str_starts_with($widget->get_name(),'epm-')){$types[]=$widget->get_name();$widgets[]=['id'=>'deep'.count($widgets),'elType'=>'widget','widgetType'=>$widget->get_name(),'settings'=>['source'=>'specific','episode_id'=>$ep,'show_bio'=>'yes','cta_text'=>'Listen to the show','cta_url'=>['url'=>'#content-target']],'elements'=>[]];}}$elements=[['id'=>'deepcolumn','elType'=>'container','settings'=>['content_width'=>'boxed','boxed_width'=>['unit'=>'px','size'=>320],'width'=>['unit'=>'px','size'=>320]],'elements'=>$widgets]];update_post_meta($id,'_elementor_data',wp_slash(wp_json_encode($elements)));update_post_meta($id,'_elementor_edit_mode','builder');update_post_meta($id,'_elementor_version',ELEMENTOR_VERSION);echo wp_json_encode([$ep,$id,get_permalink($id),$types]);`);
 ids=data.slice(0,2);const page=await newPage(browser);
 for(const width of [1280,390,320]){
  await page.setViewportSize({width,height:900});await page.goto(data[2]);
  for(const type of data[3])assert(await page.locator(`[data-widget_type="${type}.default"]`).count()===1,`${width}: ${type} renders`);
  const art=await page.locator('.epm-podcast-hero__artwork img').evaluate(el=>({width:el.clientWidth,height:el.clientHeight,nw:el.naturalWidth,nh:el.naturalHeight,css:getComputedStyle(el).height,html:el.outerHTML}));
  assert(Math.abs(art.height/art.width-art.nh/art.nw)<0.1,`${width}: hero artwork keeps its intrinsic aspect ratio ${JSON.stringify(art)}`);
  for(const selector of ['.epm-show-notes','.epm-transcript','.epm-guest'])assert(await page.locator(selector).evaluate(el=>el.scrollWidth<=el.clientWidth+1),`${width}: ${selector} contains tables and code`);
  assert(await page.locator('.epm-show-notes th').first().evaluate(el=>el.getBoundingClientRect().width>=45),`${width}: table columns keep words readable`);
  assert(await noOverflow(page),`${width}: all widgets fit the page`);
  await page.screenshot({path:`screenshots/elementor-deep-${width}.png`,fullPage:true});
 }
 const heroHtml=php(`require_once getcwd().'/tests/integration/lib.php';$saved=epm()->settings->all();$filter=static fn()=>array_replace($saved,['description'=>${JSON.stringify(rich)}]);add_filter('pre_option_'.\\EPM\\PodcastSettings::OPTION,$filter);try{echo wp_json_encode(epm_test_widget('epm-podcast-hero',[]));}finally{remove_filter('pre_option_'.\\EPM\\PodcastSettings::OPTION,$filter);}`);
 await page.locator('.epm-podcast-hero').evaluate((el,html)=>{el.outerHTML=html;},heroHtml);
 assert(await page.locator('.epm-podcast-hero').evaluate(el=>el.scrollWidth<=el.clientWidth+1),'hero prose contains wide tables and code');
 const region=page.locator('.epm-show-notes .epm-rich-scroll');await region.focus();await page.keyboard.press('ArrowRight');await page.waitForTimeout(200);
 assert(await region.evaluate(el=>el.scrollLeft>0),'wide imported content can be scrolled with the keyboard');
 await page.addStyleTag({content:':focus {outline:0;box-shadow:none}'});
 const notesLink=page.locator('.epm-show-notes__content a').first();
 await page.keyboard.press('Tab');await notesLink.focus();
 assert(await notesLink.evaluate(el=>{const c=getComputedStyle(el);return c.outlineStyle!=='none'&&parseFloat(c.outlineWidth)>=2;}),'show notes retain a visible keyboard focus under a theme reset');
 // Hold this owned request so the explicit error event tests focus deterministically.
 await page.route('**/missing-deep-video.mp4',()=>{});
 const video=page.locator('[data-epm-video]').first();await video.locator('[data-epm-video-play]').click();
 await page.waitForTimeout(500);await video.locator('video').evaluate(el=>{el.focus();el.dispatchEvent(new Event('error'));});
 assert(await video.locator('[data-epm-video-error]').isVisible().catch(()=>false),'failed video shows an explanation');
 assert(await video.locator('[data-epm-video-retry]').isVisible().catch(()=>false),'failed video offers an explicit retry');
 assert(await video.locator('.epm-video__link').count()===1,'video has an original-source fallback');
 assert(await video.locator('[data-epm-video-retry]').evaluate(el=>document.activeElement===el),'a failed focused video transfers focus to Retry');
 const retryState=await video.evaluate(root=>{const previous=root.querySelector('video');root.querySelector('[data-epm-video-retry]').click();return root.querySelectorAll('video').length===1&&root.querySelector('video')!==previous&&root.querySelector('[data-epm-video-error]').hidden;});
 assert(retryState,'Retry creates one fresh video and clears the error');
 const axe=await new AxeBuilder({page}).include('.epm-show-notes').include('.epm-guest').include('.epm-video').analyze();
 assert(!axe.violations.length,'rich content and video have no axe violations: '+axe.violations.map(v=>v.id).join(', '));
 const vimeoHtml=php("echo wp_json_encode(epm()->renderer->video(['title'=>'Unlisted test','video_url'=>'https://vimeo.com/123456789/abcdef1234']));");
 await page.evaluate(html=>{const box=document.createElement('div');box.id='epm-deep-vimeo';box.innerHTML=html;document.body.appendChild(box);epmPlayerEngine.init(box);},vimeoHtml);
 await page.locator('#epm-deep-vimeo [data-epm-video-play]').click();
 assert((await page.locator('#epm-deep-vimeo iframe').getAttribute('src')).includes('&h=abcdef1234'),'the unlisted Vimeo access hash reaches the actual iframe');
 assert(!page.problems.length,'no plugin browser exceptions: '+page.problems.join(' | '));await page.context().close();
}finally{for(const id of ids)php(`wp_delete_post(${id},true);echo 1;`);await browser.close();}
finish('Elementor deep review');
