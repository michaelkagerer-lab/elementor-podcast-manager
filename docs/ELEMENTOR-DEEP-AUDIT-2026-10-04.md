# Kritischer Elementor-Review — 2026-10-04

Ausgangspunkt: `bc57524`, Arbeitsbranch `codex/elementor-reliability-ux`.
Ziel: zuverlässige, verständliche und konsistente Widgets für Website-Bauer und
Hörer. Geprüft wurden alle zwölf Widget-Renderer, gemeinsame Controls,
Editor-Hilfen, Datenquellen, CSS und Player-Initialisierung. Die Projektregeln
und die Impeccable-Audit-/Critique-Anleitung wurden angewendet.

Die bisherige Verbesserung war nicht ausreichend: verständliche Auswahlfelder
allein lösen keine falschen Reparaturwege, unlesbaren Hilfetexte oder
unbeherrschbaren Inhalte. Der erneute Review hat 13 belegte Schwächen gefunden.
Die folgenden Punkte wurden mit reproduzierbaren Prüfungen bearbeitet. Ein
Verdacht ohne Nachweis wird nicht als behobener Bug ausgegeben.

## Befunde und Entscheidungen

| ID | Priorität | Scharfe Kritik am bisherigen Verhalten | Korrektur und Nachweis |
|---|---|---|---|
| ED-01 | P2 | Sieben Widgets teilen einen austauschbaren Hilfetext. Damit erklärt die Bibliothek weder ihren Unterschied noch ihren Zweck. | Zwölf eigene Aufgabenbeschreibungen; PHP prüft zwölf unterschiedliche Texte. Vorher sechs unterschiedliche Texte. |
| ED-02 | P2 | Die Design-Vererbung löscht vorhandene funktionale Beschreibungen. Herkunftsinformation ersetzt die Erklärung des Controls. | Funktionale Hilfe bleibt erhalten; Herkunft wird ergänzt. Isolierte Regression: vorher 18/19, danach 19/19 Assertions. |
| ED-03 | P2 | Gedimmte, kursive Hilfetexte sind im schmalen Panel zu schwer lesbar. Der Themenhinweis fällt durch den axe-Kontrastcheck. | Normale Editor-Textfarbe, aufrechte Schrift und ausreichende Zeilenhöhe, auf EPM-Controls begrenzt. Scoped axe besteht auf beiden Versionen. |
| ED-04 | P2 | Ein Widget ohne Video schickt zur allgemeinen Episodenliste. Eine fehlende Auswahl erhält denselben irrelevanten Reparaturweg. | Bei vorhandener Episode direkt zu deren Editor, mit Berechtigungsprüfung; ohne Auswahl keine falsche Verwaltungsaktion. Live-Seiten erhalten keine Editor-Platzhalter. Eigene leere Testepisode vermeidet Abhängigkeiten von veränderten Fixtures. |
| ED-05 | P2 | Header und Hero erzwingen H1/H2. Website-Bauer können die Überschriftenstruktur ihrer Seite nicht passend einstellen. | H1–H6 auswählbar, strikt erlaubte Tags. Alte Defaults H1/H2 bleiben; gewähltes H3 und ungültiges `script` werden geprüft. |
| ED-06 | P2 | Die Themenauswahl endet nach 200 Einträgen. Bestehende Filter außerhalb dieser Liste verlieren ihre verständlichen Labels. | Native Select2-Mehrfachauswahl mit 30 Ergebnissen pro Seite und erhaltener nativer Sprachkonfiguration; gespeicherte Slugs unverändert, Label-Nachladen in begrenzten Paketen. 205 Themen reproduzieren die Grenze. Browser prüft Auswahl, Labels, zweite Seite, Fehler, Wiederherstellung, Nonce und anonymen Zugriff. |
| ED-07 | P2 | Wortumbruch allein genügt nicht: Code und Tabellen laufen aus Shownotes und Transkripten. Zu aggressive Umbrüche machen Tabellenspalten zu Buchstabenstapeln. Auch Hero-Prosa hat keinen zuverlässigen Rahmen. | Gemeinsamer Renderer für breite Inhalte, benannte Tastatur-Scrollregion, Tabellen mit normalen Wortumbrüchen, Hero-Inhalt auf seine Breite begrenzt. Originales Tabellen-Markup und gespeicherte Inhalte bleiben erhalten. Browser prüft drei Breiten, lesbare Spalten, Hero-Prosa und ArrowRight-Scroll. |
| ED-08 | P2 | Nach einem Video-Fehler bleibt ein defekter Player ohne Erklärung oder Wiederholungsweg. Bei gesperrten Plattform-Embeds fehlt ein Ausweg. | Native Video-Fehler mit Erklärung und Retry; Originalvideo-Link bleibt verfügbar. Fokus wechselt nur, wenn er im fehlgeschlagenen Video war. Für fremde iframes wird keine zuverlässige Fehlererkennung behauptet. |
| ED-09 | P1 | Unlisted Vimeo-Links verlieren ihren Zugriffsschlüssel. Der erzeugte Embed kann die hinterlegte Episode dadurch nicht abspielen. | Hash aus Pfad oder `h`-Query erhalten, geprüftes Datenattribut und Embed-Parameter. PHP prüft beide URL-Formen; kein externer Plattformaufruf für die Tests. |
| ED-10 | P2 | Ein quadratisches Hero-Cover wird mit nativer Lazy-Loading-Größencontainment 1.500 Pixel hoch dargestellt. Der Hero verdrängt den restlichen Seiteninhalt. | Reserviertes Cover-Seitenverhältnis mit intrinsischem Fallback. Gemessene Bildproportionen statt bloßer Existenzprüfung; vorher drei fehlerhafte Breiten, danach passend. |
| ED-11 | P2 | Die Fokus-Regel schützt nur einen Teil der Komponenten. Auf der Mindestversion verschwinden Shownotes-Linkringe unter einem Theme-Reset. | Gemeinsame Fokus-Regel für die weiteren EPM-Komponenten; der Video-Facade-Ring bleibt eigenständig. Derselbe Browser-Test besteht auf beiden Versionen. Auf der aktuellen Version allein war der Verdacht zunächst nicht reproduzierbar. |
| ED-12 | P2 | Die Hook-Anmeldung hängt von bereits geladenem jQuery/Elementor ab. Auf Elementor 3.12 läuft das Player-Skript davor; spätere Widgets verlassen sich ausschließlich auf den MutationObserver. | Anmeldung zusätzlich bei DOM-/Fenster-Laden und beim Elementor-Init; wiederholtes Binden desselben Hook-Objekts verhindert. Ohne Observer vorher null Bindungen, danach genau eine. Vollständige Player-Suite besteht auf beiden Versionen. |
| ED-13 | P2 | Der Stil-Audit greift ungeprüft auf eine Klasse neuer Elementor-Versionen zu und bricht auf der Mindestversion ab. Die Dokumentation vermischt zusätzlich versionsabhängige Geometrie. | Reflection nur bei verfügbarer Klasse. Der Design-Test fordert keine auf 3.12.2 nicht vorhandene Elementor-Promotion an; sämtliche Legacy-Save-/Reset-Prüfungen bleiben erhalten. Zusätzlich gemessene Mindestversions-Tabelle mit eigenem exaktem Abgleich; die aktuelle `CONTROL-AUDIT.md` bleibt unverändert. Alle 88 Control-Funktionsprüfungen bleiben verpflichtend. |

P1 bezeichnet hier eine tatsächlich verlorene Wiedergabemöglichkeit. P2 umfasst
Aufgabenverständnis, Bedienung, Robustheit und Prüfzuverlässigkeit. Dieser Lauf
hat keinen neuen P0 nachgewiesen; das ist keine erneute Bewertung sämtlicher
106 historischer Findings.

## Prüfung je Widget

| Widget | Schwerpunkt dieses Reviews | Ergebnis/Abgrenzung |
|---|---|---|
| Podcast Player | Quelle, dynamische Initialisierung, Mehrfachbindung, Geräte-Reset, Fehler, Sticky, Tastatur | Bestehende komplette Player-Suite und Editor-Regressionen; späte Hook-Anmeldung korrigiert. Explizite Sticky-Einstellungen bleiben erhalten. |
| Latest Episode | Quellenauflösung, Details, CTA-Leerzustand, Stil-Vererbung | Renderer geprüft, echte Ausgabe und Stil-Controls gemessen. „Latest“ behält die bestehende Auswahl einer veröffentlichten Episode mit Audio. |
| Episode List | Themen, Begrenzungen, Pagination, Listen-/Kartenstile | Vollständige Themensuche ergänzt; bestehende Pagination-/Widget-Prüfungen und alle zugehörigen Stil-Controls bestehen. |
| Podcast Hero | Cover, Überschrift, Inhalt, CTA und Plattform-Links | Coverhöhe, Überschriftenwahl und breite Prosa korrigiert; Settings werden nicht geschrieben. |
| Episode Header | Überschrift, Metadaten, Quelle, leere Episode | Überschriftenwahl ergänzt; H1 bleibt Default, Ausgabe strikt erlaubt. |
| Episode Metadata | Feldwahl, Separator, leere Felder, Aufgabe | Eigene Anleitung und passender Reparaturweg; vorhandene Feldwerte und Separator-Verhalten bleiben erhalten. |
| Guest | Biografie/Metadaten, Bild, Quelle, Stil-Controls | Eigene Anleitung, Reparaturweg und gemeinsame Inhalts-/Fokusregeln. Die reguläre Gastbiografie wird im Datenmodell als Text behandelt; kein Nachweis beliebiger Rich-HTML-Gastfelder behauptet. |
| Subscribe Links | Native Darstellungen, zugängliche Namen, Settings-Aktion | Ausgabe/Stile geprüft; vorhandene Plattform-Einstellungen und Display-Modi bleiben erhalten. |
| Transcript | Breiter Inhalt, Zusammenklappen, Überschrift, Quelle | Zugängliche Scrollregion, sichtbarer Fokus und konkrete Anleitung. |
| Show Notes | Breiter Inhalt, Links, Überschrift, Quelle | Scrollregion, Spaltenlesbarkeit, Fokus und konkrete Anleitung. |
| Chapters | Quelle, Sprungziele, Hook-/Player-Anbindung | Bestehende Player-/Widget-Regressionen und eigene Aufgabenbeschreibung. |
| Episode Video | Privacy-Facade, Vimeo, Fehler, Retry, Ausweichlink | Zugriffsschlüssel erhalten; native Fehler mit Wiederholungsweg, Plattformen mit Original-Link. |

Die Seite mit allen zwölf Widgets ist ein Stresstest, keine empfohlene
Startervorlage. Wiederholte Player und Metadaten dieser künstlichen Kombination
werden deshalb nicht als reale Informationsarchitektur ausgegeben.

## Reproduzierbarkeit und Tests

Nur markierte, vorhandene Wegwerf-Sites wurden verwendet. Keine Produktions-
Imports, Hosting-Wechsel oder Directory-Aufrufe. Neue Tests erstellen eigene
Posts/Topics und entfernen sie; Hero-Settings werden nur innerhalb eines
PHP-Requests durch einen Filter ersetzt. Die ursprünglichen GUIDs, URLs,
Einstellungen und manuellen Widget-Werte werden nicht migriert.

- Aktuell: WordPress 7.1.2, Elementor 4.3.3, PHP 8.3, SQLite.
- Minimum: WordPress 6.2 Multisite, Elementor 3.12.2, PHP 8.3, MariaDB 11.4.
- PHP je Site: `elementor-deep` 22, `widgets` 131, `design` 228,
  `frontend` 223, `ui-phase4` 75, `ux-clarity` 79, `independent-audit` 33.
- Browser je Site: Deep-Review 65, Topics 13, Player 121,
  Editor-Reliability 30, Stil-Audit 88 Controls plus exakter Tabellenabgleich,
  Design 53 (aktuell) bzw. 52 (Minimum, ohne dort nicht vorhandene Promotion). Der aktuelle Editor-Reliability-Lauf verwendet `de_DE`.
- Lint, deutsche Kataloge und reproduzierbares Runtime-Paket werden geprüft.

Neue Reproduktionsdateien: `tests/integration/elementor-deep.php`,
`tests/e2e/elementor-deep.mjs`, `tests/e2e/elementor-topics.mjs`.
Bestehende `player.mjs` reproduzierte die Mindestversions-Hook-Schwäche.
Der Abschluss wurde gegen die Logs geprüft: Alle oben aufgeführten
Prüfungen bestehen. Der Pakettest verlangt auch die neuen Runtime-Dateien. Jeder Lauf hat ein festes Zeitlimit; Browser-Suites laufen seriell je
Site, weil temporäre Episoden die Daten/Geometrie eines Listen-Audits verändern.
Die Testcontainer werden nach Abschluss gestoppt.

## Bewusste Grenzen

Dieser Review ist keine Aussage „100 % perfekt“. Chromium, die genannten
Versionen, drei Viewportbreiten und die dokumentierten Zustände sind belegt.
Firefox/WebKit, echte Assistive-Technology-Nutzung, Nutzerstudien,
alle fremden Themes und alle individuell gewählten Farb-/Typografiekombinationen
wurden in diesem Lauf nicht vollständig überprüft. Die Plattform-Embeds bleiben
von den jeweiligen Anbietern, deren Berechtigungen und Erreichbarkeit abhängig;
ihre URLs wurden hermetisch geprüft, nicht gegen echte Vimeo-/YouTube-Konten.

Der komplette Import-/Sync-/Hosting-/Lifecycle- und Performance-Audit wird durch
diese Elementor-Prüfungen nicht ersetzt. `FINDINGS-1.4.0.md` und dessen JSON
werden deshalb nicht pauschal auf „erneut verifiziert“ gesetzt. Ein Merge oder
Release ist ein eigener Schritt mit Prüfung des konkreten Zielstands.
