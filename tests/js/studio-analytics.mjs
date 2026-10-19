import {readFileSync} from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {webcrypto} from 'node:crypto';
const code=readFileSync(new URL('../../assets/js/epm-analytics.js',import.meta.url),'utf8');
function harness(){
 let now=0;const events={},calls=[];const window={epmPlayer:{analytics:{enabled:true,endpoint:'/analytics'}},crypto:webcrypto,addEventListener:(k,f)=>events[k]=f};
 const navigator={};const document={addEventListener:(k,f)=>events[k]=f};
 vm.runInNewContext(code,{window,document,navigator,crypto:webcrypto,performance:{now:()=>now},fetch:async(url,args)=>{calls.push(JSON.parse(args.body));return {ok:true}}});
 const audio={currentTime:0,paused:false,seeking:false,readyState:4,playbackRate:1};
 const tick=(advance=1,event='time')=>{now+=1000;audio.currentTime+=advance;events['epm:playback']({detail:{episodeId:12,event,audio}})};
 return {window,navigator,audio,calls,tick};
}
let h=harness();for(let i=0;i<20;i++)h.tick();assert.equal(h.calls.length,0);
h.window.epmAnalyticsConsent=true;for(let i=0;i<12;i++)h.tick();assert.equal(h.calls.length,1);assert.equal(h.calls[0].seconds,10);
h=harness();let consent=false;h.window.epmAnalyticsConsent=true;h.window.BorlabsCookie={Consents:{hasConsent:id=>id==='epm-studio-analytics'&&consent}};
for(let i=0;i<20;i++)h.tick();assert.equal(h.calls.length,0);consent=true;for(let i=0;i<12;i++)h.tick();assert.equal(h.calls.length,1);
h=harness();h.window.epmAnalyticsConsent=true;h.navigator.globalPrivacyControl=true;for(let i=0;i<20;i++)h.tick();assert.equal(h.calls.length,0);
h=harness();h.window.epmAnalyticsConsent=true;for(let i=0;i<20;i++)h.tick(500);assert.equal(h.calls.length,0);
h=harness();h.window.epmAnalyticsConsent=true;h.audio.paused=true;for(let i=0;i<20;i++)h.tick();assert.equal(h.calls.length,0);
console.log('PASS no-consent, Borlabs service gating, GPC, seek exclusion, paused exclusion, qualified play');
