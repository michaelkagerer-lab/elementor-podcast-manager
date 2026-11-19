<?php
/** Local regression checks for the externally supplied Argon report. */
if (!defined('ABSPATH')) { exit; }
if (!is_file(dirname(rtrim(ABSPATH, '/')).'/.epm-test-site') || 'production' === wp_get_environment_type()) { WP_CLI::error('Disposable test site required.'); }
require_once __DIR__.'/lib.php';
$t = new EPM_Test_Runner();
$saved = [EPM\Hosting::OPTION=>get_option(EPM\Hosting::OPTION), EPM\ImportJob::OPTION=>get_option(EPM\ImportJob::OPTION)];
wp_set_current_user((int)get_user_by('login','admin')->ID);
$id=wp_insert_post(['post_type'=>'podcast_episode','post_status'=>'draft','post_title'=>'Argon regression']);
try {
 update_option(EPM\Hosting::OPTION, ['mode'=>'external','feed_url'=>'https://example.org/feed.xml','redirect'=>false]);
 $job=['token'=>'argon-preserve','status'=>'done_with_problems','options'=>['purpose'=>'move'],'touched'=>time()];
 update_option(EPM\ImportJob::OPTION,$job);
 $t->test('Unfinished move readiness renders',static function($t){$t->assert(is_array(EPM\Readiness::report()));});
 $t->test('Unfinished move blocks new preview before fetching',static function($t)use($job){
  $result=EPM\ImportJob::preview('https://example.org/new-feed.xml');
  $t->assert(is_wp_error($result) && 'epm_import_move_unfinished'===$result->get_error_code(),'specific recovery error required');
  $t->same($job,EPM\ImportJob::get(),'recovery state preserved');
 });
 update_option(EPM\Hosting::OPTION,['mode'=>'external','feed_url'=>'https://example.org/feed.xml?token=private','redirect'=>false]);
 $t->test('Private feed never creates an empty subscription link',static function($t){$t->same('',(new EPM\Renderer())->subscribe_links([]));});
 $route='/wp/v2/'.get_post_type_object('podcast_episode')->rest_base;
 if ('/wp/v2/'===$route) {$route.='podcast_episode';}
 $send=static function($meta)use($id,$route){$r=new WP_REST_Request('POST',$route.'/'.$id);$r->set_param('meta',$meta);return rest_do_request($r);};
 foreach(['duration'=>'nonsense','episode_type'=>'arbitrary','explicit'=>'arbitrary','audio_url'=>'ftp://example.org/audio.mp3'] as $field=>$value){
  $t->test('REST rejects invalid '.$field,static function($t)use($send,$field,$value){$t->same(400,$send(['_epm_'.$field=>$value])->get_status());});
 }
 $t->test('REST synchronizes duration',static function($t)use($send,$id){$t->same(200,$send(['_epm_duration'=>'12:34'])->get_status());$t->same(754,(int)get_post_meta($id,'_epm_duration_seconds',true));});
 $t->test('REST audio replacement clears stale enclosure metadata',static function($t)use($send,$id){update_post_meta($id,'_epm_audio_url','https://example.org/old.mp3');update_post_meta($id,'_epm_audio_length',123);update_post_meta($id,'_epm_audio_type','audio/mpeg');$t->same(200,$send(['_epm_audio_url'=>'https://example.org/new.m4a'])->get_status());$t->same(0,(int)get_post_meta($id,'_epm_audio_length',true));$t->same('',get_post_meta($id,'_epm_audio_type',true));});
 $t->test('REST attachment replacement refreshes derived audio values',static function($t)use($send,$id){
  $fx=get_option('epm_test_fixtures');$audio=(int)$fx['audio_1'];$detected=EPM\AudioMetadata::detect($audio);
  update_post_meta($id,'_epm_audio_size',999999);update_post_meta($id,'_epm_duration','99:59');
  $t->same(200,$send(['_epm_audio_id'=>$audio])->get_status());
  $t->same($detected['duration'],get_post_meta($id,'_epm_duration',true));$t->same($detected['size'],(int)get_post_meta($id,'_epm_audio_size',true));
  $t->same(200,$send(['_epm_audio_id'=>0])->get_status());$t->same('',get_post_meta($id,'_epm_duration',true));$t->same(0,(int)get_post_meta($id,'_epm_audio_size',true));
 });
 $t->test('Download warnings reach the existing error handler',static function($t){
  $seen=false;
  $previous=set_error_handler(static function($severity,$message)use(&$seen){if(strpos($message,'argon-missing-file')!==false){$seen=true;}return true;});
  $mock=static function($pre,$args,$url){if('https://example.org/argon-audio.mp3'!==$url){return $pre;}fopen('/tmp/argon-missing-file/never','rb');return new WP_Error('test_stop','Test stopped');};
  add_filter('pre_http_request',$mock,999,3);
  try {$result=EPM\MediaDownload::run(EPM\MediaDownload::fresh('https://example.org/argon-audio.mp3','audio'),microtime(true)+5);if(!empty($result['file']) && is_file($result['file'])){unlink($result['file']);}$t->assert($seen,'previous handler receives unrelated warning');}
  finally {remove_filter('pre_http_request',$mock,999);restore_error_handler();}
 });
 $t->test('Failed media move preserves its source',static function($t){
  $from=wp_tempnam('argon-source');file_put_contents($from,'original audio');
  try {$method=new ReflectionMethod(EPM\MediaCopy::class,'move');$t->same(false,$method->invoke(null,$from,$from.'/missing/target'));$t->same('original audio',file_get_contents($from));}
  finally {if(is_file($from)){unlink($from);}}
 });
 $t->test('WordPress rejects IPv6 loopback literal',static function($t){$t->same(false,wp_http_validate_url('http://[::1]/'));});
} finally {
 wp_delete_post($id,true);
 foreach($saved as $key=>$value){false===$value?delete_option($key):update_option($key,$value);}
 EPM\Feed::flush_cache();
}
$t->finish();
