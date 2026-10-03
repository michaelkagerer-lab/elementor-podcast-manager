# UI-Inventur — 3. Oktober 2026

Phase 1 des Auftrags in `docs/ui-audit-work-order.md`. Dies ist die Liste der
Prüfflächen, ohne Bewertung. Referenz: `docs/design-skills.md` und die
Projektregeln. Der Review umfasst Bedienung und Gestaltung des WordPress-Plugins.

| Fläche | Komponenten und Zustände | Quelle / Prüfgrößen |
|---|---|---|
| Dashboard | Einrichtung fehlt, eingerichtet, Episodenzahlen, nächster Schritt, Hosting, Sync, Verbreitung, letzte Episode, Feed-Warnungen/Fehler | `admin/views/dashboard.php`; 1280/390 px |
| Podcast-Einstellungen | Angaben, Sprache/Kategorie, Moderation, Content-Rating, Cover-Auswahl/Entfernung, Veröffentlichungsreihenfolge, Feed, Plattformlinks, speichern/Fehler/Erfolg | `admin/views/settings.php`; 1280/390 px |
| Setup: Auswahl | Neu hier hosten, vorhandenen Podcast umziehen, bisherigen Host behalten; ungewählt/gewählt/Fehler | `admin/views/setup.php`, Panel `path` |
| Setup: Verbindung | Provider, Feed-Adresse, Prüfung, geschützter Feed, Eigentümer-Zustimmung, Fehler/Warten | Panel `connect` |
| Setup: Import | läuft, wartet, gestoppt, fehlgeschlagen, teilweise importiert, fertig, Medien fehlen | Panel `import`; `partials/import-result.php` |
| Setup: Angaben | übernommen/eigene Angaben, Pflichtfelder, Cover, speichern, wiederaufnehmen | Panel `show` |
| Setup: Gestaltung/Seite | Preset, neue Starter-Seite, bestehende Seite wiederverwenden, Fehler/Erfolg | Panel `look`; `AdminPages::create_podcast_page()` |
| Setup: Abschluss | Self-Hosting, extern, Umzug; fehlende Medien, Redirect-Anweisungen, Distribution | Panel `done` |
| Hosting | Self/extern, Feed-Auswahl, Validierung, 301-Einstellung, Synchronisation an/aus/veraltet/Fehler | `admin/views/hosting.php` |
| Import und Umzug | Feed-Vorschau, Teilabruf, Start, Fortschritt, Retry, Stopp, Fortsetzung, Protokoll, Medienprobleme | `hosting.php`, `epm-hosting.js`, `epm-import-result.js` |
| Distribution | Feed-Adresse, Kopieren/Fallback/Fehler, Auslieferungstest, Feed-Probleme, Pflicht/empfohlen/optional/automatisch | `admin/views/distribution.php`; 1280/390 px |
| Verzeichniseintrag | nicht eingereicht/eingereicht/gelistet, Einreichungslink, Tracking, URL-Feld, speichern/Fehler/Erfolg | `epm-distribution.js`; direkte Fragmentlinks |
| Design | Presets/Bestätigung, Upgrade-Vorschläge, Farben/Kontrast, Form, Schrift, Layout, Details, Speichern/Verwerfen, Vorschau, Abweichungen, Export/Import | `admin/views/design.php`; 1280/390 px |
| Episodenliste | Spalten, Titel, Audio-Status, Themen, Quick Edit, gespeichert/Fehler | `includes/Admin.php`, `EpisodeMeta.php` |
| Episodeneditor | Audio-Auswahl/Upload/Austausch, Metadaten, Datum, Gast, Kapitel, Shownotes, Video, Transkript; leer/lädt/Fehler/Erfolg | `includes/EpisodeMeta.php`, `admin/js/epm-admin-ui.js` |
| Medienauswahl | Bibliothek, Upload, Auswahl, Schließen, Fokus-Rückkehr, verzögerte Metadaten | WordPress-Medienmodal; Plugin-Anbindung |
| Elementor-Bibliothek | Kategorie, Suche, Einfügen, leeres Dokument, Vorschau | `includes/Elementor/Integration.php` |
| Elementor-Einstellungen | aktuell/bestimmt/neueste, Episode-Picker, Layout, Details, Vererbung, eigene Stile, Default-Reset, Vorschauhinweis, leere Zustände | `Widgets/WidgetHelpers.php`, `epm-elementor-editor.js` |
| Podcast Player | Minimal, Compact, Editorial, Artwork, Full; laden/spielen/pausieren/seek/Fehler/resume, Speed, Volume, Download, Share | `PodcastPlayerWidget.php`, `Renderer.php`, `epm-player.js`; 1280/390 px |
| Latest Episode | Player/Karte, Layout, CTA, Sticky, fehlende Episode/Audio/CTA | `LatestEpisodeWidget.php` |
| Episode List | List, Editorial rows, Cards, Grid, Minimal; leer/Pagination/mehrere Listen, Play/Share/Sticky | `EpisodeListWidget.php`; 320/390 px und schmale Spalten |
| Podcast Hero | Cover, Text, Links, CTA; unvollständige Angaben | `PodcastHeroWidget.php` |
| Episode Header/Metadata/Guest | Quellwahl, sichtbare Felder, Cover/Gastfoto; fehlende Episode/Felder/Gast | jeweilige Widget-Dateien |
| Show Notes/Chapters/Transcript | Inhalt, Heading-Level, Seek, aktive Kapitel, Upload-/Download-Link, fehlende Daten | jeweilige Widget-Dateien |
| Video | Facade, Play, Einbettung/Datei, Datenschutz, fehlendes Video, Fokus | `EpisodeVideoWidget.php`, `Renderer.php` |
| Subscribe | Plattformlinks, Icons/Text, fehlende Links | `SubscribeLinksWidget.php` |
| Automatische Episodenseite | Kopf, Player, Inhalte, Kapitel, Transkript, Gast, Video, Verweise | `EpisodeTemplate.php` |
| Sticky/Share/Embed | verfügbar/verborgen, Fokus, Seek, Copy/Fallback, Link mit Zeit, Embed-Code, iframe-Höhe | `Renderer.php`, `Embed.php`, Frontend-JS |
| Übergreifend | deutsche Labels, Tastatur/Focus, Kontrast, schmale Breiten, Container-Queries, Reduced Motion, fremde Theme-/Elementor-Stile | Admin: 782/1200 px; Frontend: 340/480/520/768 px und Container 440/560 px |

Die Größen nennen vorhandene oder gezielt geprüfte Fälle, keine Garantie für
jedes Gerät. Die genaue Prüfdeckung steht im anschließenden Befundbericht.
