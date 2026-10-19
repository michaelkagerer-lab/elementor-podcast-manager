/* First-party playback measurement. No cookies, localStorage or persistent IDs. */
(function () {
 'use strict';
 var sessions = new Map();
 function consent() {
  try {
   if (window.BorlabsCookie && window.BorlabsCookie.Consents && typeof window.BorlabsCookie.Consents.hasConsent === 'function') return window.BorlabsCookie.Consents.hasConsent('epm-studio-analytics') === true;
  } catch (e) { return false; }
  return window.epmAnalyticsConsent === true;
 }
 function allowed() {
  return window.epmPlayer && window.epmPlayer.analytics && window.epmPlayer.analytics.enabled &&
   consent() && navigator.doNotTrack !== '1' && !navigator.globalPrivacyControl;
 }
 function send(s) {
  if (!allowed() || s.total < 10 || s.pending || Math.floor(s.total) <= s.sent) return;
  var seconds = Math.min(86400, Math.floor(s.total));
  s.pending = true;
  fetch(window.epmPlayer.analytics.endpoint, { method: 'POST', credentials: 'omit', keepalive: true,
   headers: { 'Content-Type': 'application/json' },
   body: JSON.stringify({ episodeId: s.id, session: s.session, day: s.day, seconds: seconds, consent: true })
  }).then(function (r) { if (r.ok) s.sent = seconds; }).catch(function () {}).finally(function () { s.pending = false; });
 }
 document.addEventListener('epm:playback', function (event) {
  if (!allowed()) { sessions.clear(); return; }
  var d = event.detail, day = new Date().toISOString().slice(0, 10), id = Number(d.episodeId);
  if (!Number.isInteger(id) || id < 1 || !window.crypto || !crypto.getRandomValues) return;
  var s = sessions.get(id), now = performance.now(), position = d.audio.currentTime || 0;
  if (!s || s.day !== day) {
   var random = new Uint8Array(16); crypto.getRandomValues(random);
   s = { id: id, day: day, session: Array.from(random, function (n) { return n.toString(16).padStart(2,'0'); }).join(''), total: 0, sent: 0, wall: now, position: position, pending: false };
   sessions.set(id, s);
  }
  var elapsed = (now - s.wall) / 1000, advanced = position - s.position;
  // Seeking, stalled media and background timer gaps are not listening time.
  if (d.event === 'time' && !d.audio.paused && !d.audio.seeking && d.audio.readyState >= 2 && elapsed > 0 && elapsed <= 5 && advanced > 0 && advanced <= elapsed * (d.audio.playbackRate || 1) + 0.5) {
   s.total += Math.min(elapsed, advanced / (d.audio.playbackRate || 1));
  }
  s.wall = now; s.position = position;
  if ((s.sent === 0 && s.total >= 10) || s.total - s.sent >= 30 || ['pause','ended'].indexOf(d.event) >= 0) send(s);
 });
 window.addEventListener('pagehide', function () { sessions.forEach(send); });
 document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') sessions.forEach(send); });
 window.addEventListener('epm:analytics-consent', function (event) { window.epmAnalyticsConsent = event.detail === true; if (!allowed()) sessions.clear(); });
})();
