# BUSINESS TUNING STUDIO: Einrichtung und Messgrenzen

Kandidat 1.5.0-rc.1, basierend auf main e7c51ce / Plugin 1.4.0. Keine Änderung an GUIDs, Audio-URLs, RSS oder bestehenden Podcast-Einstellungen. Messung standardmäßig aus. Kein rückwirkendes Erfinden von Analytics.

## Installation

1. WordPress-Dateien und Datenbank sichern. Das ZIP unter Plugins → Neues Plugin → Plugin hochladen installieren und die vorhandene Version ersetzen. Danach Seiten-/Script-Caches leeren.
2. Im BUSINESS TUNING STUDIO als Administrator unter Einstellungen → Podcast Manager · Website-Analytics einen Verbindungsschlüssel erstellen. Nur einmal angezeigt; sicher behandeln.
3. In WordPress unter Podcast → Studio-Analytics den Schlüssel einfügen, Messung aktivieren und speichern. „Jetzt senden / Verbindung testen“ prüft die serverseitige Verbindung auch ohne Messwerte. Bei Fehlern Schlüssel, Netzwerkzugriff und RSS-Import prüfen.
4. Auf businesstuning.at wurde am 19.10.2026 Borlabs Cookie 3.4.2.2 im öffentlichen HTML erkannt. In Borlabs einen eigenen Dienst **epm-studio-analytics** in der Statistik-Gruppe anlegen. Inhaltlich als eigene Podcast-Website-Statistik beschreiben. Keine Opt-in-/Opt-out-Scripts nötig: der Player fragt `BorlabsCookie.Consents.hasConsent('epm-studio-analytics')` vor jeder Messung und jedem Versand ab. Bestehende Google-/YouTube-Einwilligungen werden nicht umgedeutet. Die tatsächliche Borlabs-Konfiguration ist durch den Website-Betreiber vorzunehmen; keine Live-Konfiguration verändert.
5. Abgemeldet/privates Browserfenster: Statistikdienst erlauben, eine Episode mindestens 12 Sekunden tatsächlich abspielen und pausieren. Im Plugin „Jetzt senden“ wählen. Im Studio unter Analytics → Website erscheinen Wiedergaben und Wiedergabezeit, je Folge oder über die Show summiert. Ohne Freigabe dürfen keine `/epm/v1/analytics/playback`-Requests entstehen. Widerruf erneut prüfen.

Offizielle Borlabs-API: https://borlabs.io/docs/javascript-api-v3/ (am 19.10.2026 geprüft). Ohne Borlabs kann ein anderer Consent-Manager mit `window.dispatchEvent(new CustomEvent('epm:analytics-consent', {detail: true}))` freigeben und mit `false` widerrufen. Beim Seitenstart ohne Borlabs ist alternativ `window.epmAnalyticsConsent = true` möglich, ausschließlich nach erfolgter Einwilligung. Do Not Track und GPC verhindern die Messung zusätzlich.

## Was gemessen wird

Nur der native EPM-Audioplayer, inklusive seiner Karten-/Sticky-Steuerung. Eine Wiedergabe entspricht einer Browser-Seitensitzung pro Episode und UTC-Tag mit mindestens zehn beobachteten Wiedergabesekunden. Pause/Fortsetzen oder doppelte Widgets auf derselben Seite zählen nicht doppelt. Ein neuer Seitenbesuch/ein weiterer Tab kann erneut zählen. Wiedergabezeit ist beobachtete reale Abspielzeit, nicht die durch höhere Geschwindigkeit übersprungene Medienzeit. Seeking, Pausen und Stalls zählen nicht; große Hintergrund-Timerlücken werden konservativ ausgelassen. Sehr kurze Clips unter zehn Sekunden zählen nicht.

Keine eindeutigen Hörer, keine Spotify-/Apple-Werte, keine externen RSS-Downloads, keine nativen Video-/YouTube-/Vimeo-Statistiken. Blocker, deaktiviertes JavaScript, fehlende Einwilligung und verlorene Browserrequests können unterzählen. Es handelt sich um Website-Nutzungsmessung, nicht um IAB-zertifizierte Downloads oder botbereinigte Reichweite. Öffentliche Telemetrie ist trotz Origin-Prüfung und Rate-Limit nicht fälschungssicher.

## Datenfluss und Betrieb

Browser → eigener WordPress-REST-Endpunkt: zufällige flüchtige Sitzungskennung, Episode, UTC-Tag und kumulative Sekunden. Keine Analytics-Cookies/LocalStorage; keine Browser-ID über Seitenaufrufe hinweg. WordPress speichert nur den Hash dieser Kennung pro Episode/Tag. Roh-IP wird nicht in den Analytics-Tabellen gespeichert; zum Missbrauchsschutz gibt es einen mit WordPress-Salt gehashten, minutenbezogenen IP-Schlüssel für maximal zwei Minuten. Normale Webserver-/Hostinglogs sind davon unabhängig.

WordPress → feste HTTPS-Studio-Adresse: ausschließlich GUID, UTC-Tag, Revision, Anzahl und Sekunden. Keine Sitzungskennungen/IPs. Der Schlüssel verlässt WordPress nur im Authorization-Header; er erscheint nicht im Player oder in Logs. Keine Redirects. Ein Studio-Schlüssel akzeptiert ausschließlich den BUSINESS-TUNING-Feed. Nur eine produktive WordPress-Quelle anbinden; Staging darf nicht mit demselben Schlüssel senden.

Pro WordPress-Stunde werden maximal 100 ausstehende Tagesdatensätze gesendet. WP-Cron ist besucherabhängig; bei wenig Zugriffen kann der Versand später erfolgen. Für garantierte Regelmäßigkeit im Hosting einen echten Cron für WordPress einrichten. Status, letzter Versuch und ausstehende Tageswerte stehen im Plugin. „Erfolgreich“ gilt erst nach passender Studio-Bestätigung. Bei Netzwerk-/Schlüsselfehlern bleiben Tageswerte erhalten. Unbekannte GUIDs verhindern die Bestätigung des Pakets: zuerst Studio-RSS synchronisieren. Alte Pakete können neuere Werte nicht überschreiben; identische Pakete werden nicht addiert. Gleichzeitige Messung während Versand bleibt offen.

Tagesaggregate bleiben bestehen. Sitzungs-Hashes älter als 35 Tage werden nach erfolgreichem Versand gelöscht. Deaktivierung stoppt Cron, Daten bleiben erhalten. Deinstallation behält Daten standardmäßig; nur vorhandene explizite EPM_DELETE_DATA-Option löscht auch Analytics-Tabellen/Optionen. Schlüssel im Studio widerrufen, um sofort weitere Eingänge zu sperren. Neuer Schlüssel ersetzt den alten, erhält aber bisherige Messwerte. Nach Wiederherstellung einer älteren WordPress-Datenbank den Zählerstand prüfen: ältere Revisionen werden zum Schutz der vorhandenen Studio-Daten nicht übernommen.

## Ausgeführte Prüfungen

- PHP-/JS-Syntax und reproduzierbares installierbares ZIP; deutsche PO/MO-Katalogprüfung.
- Echter WordPress-7.1.2-Teststand mit SQLite und Elementor 4.3.3: acht Assertions zu kumulativen Zählern, Retry, gleichzeitiger Messung beim Versand, Origin-/Episodenprüfung und Geheimnisabgrenzung.
- Native Audiowiedergabe in Chromium: ohne Einwilligung keine Telemetrie, nach zehn Sekunden ein Event, Wiederholung akzeptiert, Widerruf stoppt Messung.
- Zusätzliche JS-Prüfungen: Borlabs-Servicefreigabe, GPC, Seeking, Pause. Borlabs-Laufzeit wird dabei mit seinem dokumentierten API-Vertrag simuliert; kein echter lizenzierter Borlabs-Teststand.
- Tatsächlicher HTTP-Versand WordPress → lokales Next.js → PostgreSQL, mit Testadapter nur für Transportziel/Feedorigin: exakt eine Wiedergabe und 42 Sekunden nach zweimaligem Versand.
- Studio: Idempotenz, ältere/gleichzeitige Pakete, Konflikt-Rollback, unbekannte GUID, Widerruf, Quellbindung; bestehende 35 Integrationsprüfungen weiterhin erfolgreich.
- Desktop/mobile WordPress- und Studio-Einstellungen, Schlüsselerstellung/-widerruf und Website-Filter im Browser geprüft.

Nicht ausgeführt: Installation auf businesstuning.at, tatsächliche dortige MySQL-/Borlabs-Abnahme oder Messung echter Besucher. PHP 8.5 meldet bestehende Elementor-/WP-CLI-Deprecations im Teststand; keine Änderung an diesen fremden Abhängigkeiten. Kandidat zuerst auf einer Kopie/Staging installieren, danach oben beschriebenen Live-Test durchführen.
