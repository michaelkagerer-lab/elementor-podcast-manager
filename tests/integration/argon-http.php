<?php
/** Deterministic DNS and cURL-option regression, no external requests. */
namespace EPM {
 function gethostbyname($host) { return $host==='rebind.example.test' ? ($GLOBALS['argon_dns']??'93.184.216.34') : \gethostbyname($host); }
 function curl_setopt($handle,$option,$value) {$GLOBALS['argon_curl_options'][$option]=$value;return \curl_setopt($handle,$option,$value);}
}
namespace {
 if(!defined('ABSPATH') || !is_file(dirname(rtrim(ABSPATH,'/')).'/.epm-test-site') || wp_get_environment_type()==='production'){WP_CLI::error('Disposable site required.');}
 require_once __DIR__.'/lib.php';$t=new EPM_Test_Runner();
 $mock=static function($pre,$args,$url){
  if(strpos($url,'rebind.example.test')===false){return $pre;}
  $GLOBALS['argon_curl_options']=[];
  $handle=curl_init($url);
  try {do_action('requests-curl.before_send',$handle);} finally {unset($handle);}
  return ['headers'=>[],'body'=>'ok','response'=>['code'=>200,'message'=>'OK']];
 };
 add_filter('pre_http_request',$mock,999,3);
 try {
  $t->test('Socket is pinned to the validated IPv4 address',static function($t){
   $GLOBALS['argon_dns']='93.184.216.34';$r=EPM\SafeHttp::get('https://rebind.example.test/audio.mp3');
   $t->assert(!is_wp_error($r));$o=$GLOBALS['argon_curl_options'];
   $t->same(['rebind.example.test:443:93.184.216.34'],$o[CURLOPT_RESOLVE]??null);
   $t->same(CURL_IPRESOLVE_V4,$o[CURLOPT_IPRESOLVE]??null);
  });
  $t->test('Rebinding to private address is rejected at connection time',static function($t){
   $GLOBALS['argon_dns']='127.0.0.1';$r=EPM\SafeHttp::get('https://rebind.example.test/audio.mp3');
   $t->assert(is_wp_error($r) && 'epm_unsafe_url'===$r->get_error_code());
  });
 } finally {remove_filter('pre_http_request',$mock,999);}
 $t->finish();
}
