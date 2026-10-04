<?php
/** Deep Elementor review: editorial controls, guidance, video parsing and scale. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! is_file( dirname( rtrim( ABSPATH, '/\\' ) ) . '/.epm-test-site' ) ) { throw new RuntimeException( 'Requires a marked disposable site.' ); }
require_once __DIR__ . '/lib.php';
wp_set_current_user( 1 );
$t = new EPM_Test_Runner();
$fx = get_option( 'epm_test_fixtures' );
$t->test( 'Distinct tasks for all twelve widgets', static function ( $t ) {
 $tasks=[];
 foreach ( \Elementor\Plugin::$instance->widgets_manager->get_widget_types() as $widget ) {
  if ( str_starts_with( $widget->get_name(), 'epm-' ) ) { $tasks[$widget->get_name()]=$widget->task_description(); }
 }
 $t->same(12,count(array_unique($tasks)), 'each widget explains its own task');
} );
$t->test( 'Title levels are editable without changing old defaults', static function ( $t ) use ($fx) {
 foreach ( ['epm-episode-header'=>'h1','epm-podcast-hero'=>'h2'] as $type=>$default ) {
  $c=epm_test_controls($type);
  $t->same($default,$c['title_tag']['default']??null,$type.': legacy title level is kept');
  $html=epm_test_widget($type,['source'=>'specific','episode_id'=>$fx['ep1'],'title_tag'=>'h3']);
  $t->assert((bool)preg_match('/<h3 class="epm-(?:episode-header|podcast-hero)__title"/',$html),$type.': chosen H3 renders');
  $html=epm_test_widget($type,['source'=>'specific','episode_id'=>$fx['ep1'],'title_tag'=>'script']);
  $t->assert(!str_contains($html,'<script'),$type.': invalid title tag cannot inject markup');
 }
} );
$t->test( 'An empty selected episode links directly to its editor', static function ($t) use ($fx) {
 $empty=wp_insert_post(['post_type'=>\EPM\EpisodePostType::CPT,'post_status'=>'publish','post_title'=>'Deep empty episode']);
 $editor=\Elementor\Plugin::$instance->editor; $editor->set_edit_mode(true);
 try {
  $html=epm_test_widget('epm-episode-video',['source'=>'specific','episode_id'=>$empty]);
  $t->assert(str_contains($html,'post='. $empty), 'missing video leads to this episode, not the general list');
  $html=epm_test_widget('epm-show-notes',['source'=>'specific','episode_id'=>0]);
  $t->assert(!str_contains($html,'edit.php?post_type='),'missing selection does not send the builder to an unrelated admin list');
 } finally {$editor->set_edit_mode(false);wp_delete_post($empty,true);}
} );
$t->test( 'Private Vimeo URL retains its access hash', static function ($t) {
 foreach (['https://vimeo.com/123456789/abcdef1234','https://player.vimeo.com/video/123456789?h=abcdef1234'] as $url) {
  $source=\EPM\Renderer::video_source($url);
  $t->same('abcdef1234',$source['hash']??'', 'Vimeo access hash parsed');
  $html=epm()->renderer->video(['title'=>'Private Vimeo','video_url'=>$url]);
  $t->assert(str_contains($html,'data-epm-video-hash="abcdef1234"'),'hash reaches the facade');
 }
} );
$t->test( 'Malformed video parameters degrade without PHP warnings', static function ($t) {
 $warnings=[];
 set_error_handler(static function($severity,$message) use (&$warnings){$warnings[]=$message;return true;});
 try {
  $source=\EPM\Renderer::video_source('https://youtube.com/watch?v[]=abcdefghi');
  $t->same(null,$source,'array-valued video ID is rejected');
  foreach(['ftp://example.com/movie.mp4','javascript://example.com/movie.mp4'] as $url){
   $t->same(null,\EPM\Renderer::video_source($url),'unsupported scheme is rejected');
  }
 } finally {restore_error_handler();}
 $t->same([],$warnings,'invalid input emits no warnings');
 $t->same('file',\EPM\Renderer::video_source('//example.com/movie.mp4')['kind']??null,'existing protocol-relative web videos stay valid');
 $t->same('youtube',\EPM\Renderer::video_source('https://youtube.com/watch?v=abcdefghi')['kind']??null,'ordinary YouTube URLs stay valid');
});
$t->test( 'Topics beyond the initial options are searchable', static function ($t) {
 $c=epm_test_controls('epm-episode-list');
 $t->same('epm_topic_select',$c['topics']['type']??'', 'topics use a paginated native search rather than a capped SELECT2');
 if (!class_exists(\EPM\Elementor\Controls\TopicSelectControl::class)) {return;}
 $ids=[];
 try {
  for($i=0;$i<205;$i++){$term=wp_insert_term('Deep review topic '.sprintf('%03d',$i),\EPM\Renderer::TOPIC_TAXONOMY,['slug'=>'epm-deep-topic-'.$i]);if(!is_wp_error($term)){$ids[]=$term['term_id'];}}
  $result=\EPM\Elementor\Controls\TopicSelectControl::search('Deep review topic 204',1,[]);
  $t->same('epm-deep-topic-204',$result['results'][0]['id']??null,'the last topic is reachable');
  $result=\EPM\Elementor\Controls\TopicSelectControl::search('Deep review topic',1,[]);
  $t->same(30,count($result['results']),'search results are bounded');
  $t->assert($result['pagination']['more'],'pagination reaches the remaining topics');
 } finally {foreach($ids as $id){wp_delete_term($id,\EPM\Renderer::TOPIC_TAXONOMY);}}
} );
$t->test( 'Inheritance explains provenance without deleting functional help', static function ($t) {
 $widget = new class extends \Elementor\Widget_Base {
  use \EPM\Elementor\Widgets\WidgetHelpers;
  public function get_name(){return 'epm-description-probe';}
  public function get_title(){return 'Description probe';}
  protected function register_controls(){
   $this->start_controls_section('probe',['label'=>'Probe']);
   $this->add_control('spacing',['label'=>'Spacing','type'=>\Elementor\Controls_Manager::SLIDER,'description'=>'Space between episode items.','selectors'=>['{{WRAPPER}}'=>'--epm-gap: {{SIZE}}{{UNIT}};']]);
   $this->end_controls_section();
  }
 };
 \Elementor\Plugin::$instance->widgets_manager->register($widget);
 $c=epm_test_controls('epm-description-probe');
 \Elementor\Plugin::$instance->widgets_manager->unregister('epm-description-probe');
 $t->assert(str_contains($c['spacing']['description'],'Space between episode items.'),'functional help remains readable');
 $t->assert(str_contains($c['spacing']['description'],'Podcast design:'),'provenance is also explained');
} );
$t->test( 'Hero prose uses the same accessible wide-content containment', static function ($t) {
 $saved=epm()->settings->all();
 $filter=static fn()=>array_replace($saved,['description'=>'<pre>'.str_repeat('wide-code-token ',40).'</pre><table><tr><td>Column</td></tr></table>']);
 add_filter('pre_option_'.\EPM\PodcastSettings::OPTION,$filter);
 try {
  $html=epm_test_widget('epm-podcast-hero',[]);
  $t->assert(str_contains($html,'epm-rich-scroll'),'hero contains wide formatted prose');
  $t->assert(str_contains($html,'role="region" tabindex="0"'),'the scroll area is named and keyboard accessible');
 } finally {remove_filter('pre_option_'.\EPM\PodcastSettings::OPTION,$filter);}
 $t->same($saved,epm()->settings->all(),'rendering never writes podcast settings');
});
$t->finish();
