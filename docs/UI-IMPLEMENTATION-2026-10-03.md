# UI-Umsetzung — Phase 4, 3. Oktober 2026

Der Eigentümer autorisierte mit „alle zehn“ sämtliche Maßnahmen des
[Phase-3-Plans](UI-IMPLEMENTATION-PLAN-2026-10-03.md). Dieser Bericht beschreibt
die Umsetzung der 15 zusätzlichen UI-Befunde, keine erneute vollständige
Abnahme sämtlicher ursprünglicher 1.4.0-Findings und keine Freigabe für Produktion.

## Ergebnis

| Maßnahme | Audit-Befunde | Umgesetzt |
|---|---|---|
| 1. Wahrheitsgemäßer Status | UA-07/08 | Eingereicht und gelistet werden getrennt gezählt. Die Oberfläche kennzeichnet eigene Angaben als ungeprüft. Die technische Prüfanzahl steht hinter einem Aufklappbereich; Probleme und nächste Schritte bleiben sichtbar. |
| 2. Distribution | UA-06 | Feed kopieren ist sekundär. Bei Feed-Problemen führt die primäre Aktion zur Korrektur; andernfalls zur nächsten wesentlichen Plattform. Externe Einreichungen werden nicht zusätzlich gesperrt. |
| 3. Gruppierung/Symbole | UA-13/15 | Redundanter Setup-Kicker entfernt, SVG statt Textsymbol für erledigte Schritte, nur eine Details-Gruppe zugleich. Verborgene Gruppen bleiben Bestandteil des Speichervorgangs; ohne JavaScript sind alle erreichbar. |
| 4. Lesbarkeit | UA-12 | Entscheidungsrelevante sekundäre Admin-Texte mit 14 px und höherem Zeilenabstand, Player-Zeitangaben 13 px. Native Schriften und manuelle Elementor-Werte bleiben erhalten. |
| 5. Schmale Komponenten | UA-11 | Full-Player nutzt einen eigenen Query-Container. Cover und Controls passen sich an die tatsächliche Spaltenbreite an; kleine explizite Cover-Größen bleiben wirksam. |
| 6. Herkunft/Wirkung | UA-02/03 | Inheritable Stilregler zeigen Podcast-Wert oder ausdrücklich Theme/Vererbung. Einzelne Overrides lassen sich mit benanntem Button zurücksetzen und mit nativem Undo wiederherstellen. Globale Detail-Flags erklären Layout- und Datenabhängigkeit. |
| 7. Live-Vorschau | UA-01/14 | Tatsächlicher Renderer für ungespeicherte Details und Layouts, eine gewählte Komponente, natürliche Höhe auf Mobilgeräten. Debounce, Abbruch, Zeitlimit, Reihenfolgenprüfung und Retry erhalten den letzten gültigen Render bei Fehlern. |
| 8. Setup | UA-04 | Zuerst neue oder bestehende Show wählen, danach bei bestehender Show Hosting behalten oder umziehen. Die Wahl allein speichert nichts; Continue verwendet die bisherigen Backend-Pfade. |
| 9. Hosting-Aufgaben | UA-05 | Hosting/Sync, Import und Umzugsanleitung getrennt über native Navigation. Direktlink und Reload behalten die Aufgabe; aktive Jobs und Wiederaufnahme bleiben außerhalb der ausgeblendeten Bereiche sichtbar. |
| 10. Einstieg/Vorlagen | UA-09/10 | Audio → Beschreibung → Veröffentlichen im Episodeneditor, optionale Metadaten weiterhin native Boxen. Explizite Entwürfe für Show/Archiv, shortcodefähiger Fallback, drei editierbare Starter im Elementor-Editor mit Undo. Episoden-Starter nur im ursprünglichen Episodendokument. Alle zwölf Bibliotheks-Kacheln erklären ihre Aufgabe. |

Vorhandene Seiten werden nicht überschrieben. Keine Migration von GUIDs, URLs,
importierten lokalen Bearbeitungen oder gespeicherten Widget-Overrides.
Starter sind bearbeitbare Inhalte, keine automatisch veröffentlichte Website und
keine Pro-Theme-Builder-Vorlage. Wiederverwendung erfolgt über Elementors native
Abschnittsvorlagen. Speichern und Veröffentlichung bleiben explizit.

## Nachweise und Korrekturen während der Abnahme

Die neue Integration-Suite reproduzierte zunächst die fehlenden Verhaltensweisen;
die Browser-Suite prüft anschließend tatsächliche Interaktionen. Ein ungültiger
Preview-Farbwert als Array reproduzierte einen PHP-TypeError; der Renderer filtert
jetzt nichtskalare Token-Werte und fällt auf gültige Vorgaben zurück.

Der Einzelwert-Reset braucht eine eigene Elementor-History-Transaktion, damit
Undo auch unmittelbar nach einer Eingabe den vorherigen Wert wiederherstellt.
Unterschiedliche Bibliotheks-Hook-Signaturen alter/neuer Elementor-Versionen
wurden durch Beobachtung der nativen Kachelstruktur ersetzt. Die Tests prüfen
sichtbare Beschreibungen aller zwölf Widgets in beiden Versionen.

Bestehende Browser-Suites wechseln nun ausdrücklich zwischen Hosting-Aufgaben
und öffnen optionale Verzeichnisgruppen vor der Bedienung ihrer Formulare.
Die Admin-Attachment-Fixture wird direkt geschrieben: `wp_upload_bits` wendet
entgegen der bisherigen Testbeschreibung MIME-Beschränkungen an. Produktions-
Upload-Regeln werden dadurch nicht erweitert oder umgangen.

Die mobile Sichtprüfung führte zur statischen Savebar ohne überlagernde Leiste
und zur Auswahl einer einzigen Details-Gruppe. Sichtprüfung der gerenderten
390-px-Design- und Hosting-Seiten; zusätzliche Desktop-/Mobile-Screenshots und
semantische/Contrast-Scans entstehen im Accessibility-Lauf.

## Verifikation

Lokale Profile: WordPress 7.1.2 / Elementor 4.3.3 / SQLite und WordPress
6.2 / Elementor 3.12.2 / MariaDB 11.4 (zusätzlich Multisite), beide PHP 8.3.

| Prüfung | Ergebnis |
|---|---|
| PHP: UI-Phase 4 / Admin / Design / Widgets / Hosting / Frontend / Feed / UX clarity | 75 / 361 / 228 / 131 / 1193 / 222 / 147 / 79 Assertions bestanden; beide Profile |
| Neue UI-Browser-Suite | 27 Checks bestanden; beide Elementor-Versionen |
| Bestehende Browser-Suites, aktuelles Profil | Design 53, Setup 160, Resume 7, Import-Recovery 29, UX clarity 17, Accessibility 51, lange übersetzte Labels 12, Widgets 34, Sticky-Fokus 2 Checks bestanden |
| Elementor inaktiv, Mindestprofil | 13 Assertions bestanden einschließlich Preview und Starter-Fallbacks |
| Syntax / Übersetzung | PHP-/JavaScript-Lint und 2 Katalogtests bestanden |

Die Accessibility-Suite scannt sechs Admin-Flächen und fünf Player-Layouts bei
1280 und 390 px (22 Scans, keine gemeldeten WCAG-Verstöße) und prüft zusätzlich
Full-Player in 320/390/480-px-Desktop-Spalten. Browserprofil dieses Laufs:
Chromium. Firefox/WebKit, Concurrency, große Performance- und komplette Media-
Transfersuites wurden in diesem UI-Lauf nicht erneut ausgeführt.
Alle Tests verwenden markierte, wegwerfbare Sites. Browserläufe haben je
180 Sekunden Zeitlimit, PHP-Aufrufe 150 Sekunden. Keine neuen Dependencies.

Screenshots liegen lokal unter `tests/e2e/screenshots/`, sind nicht eingecheckt.
Die neuen Suites und Ausführung stehen in [tests/README.md](../tests/README.md).

## Zusätzliche Merge-Abnahme

Die erste vollständige CI auf dem neuen UI-Stand fand drei veraltete
Abnahmeannahmen: `e2e/run.mjs` öffnete Presets und Dateiaktionen nicht,
`e2e/feed-errors.mjs` öffnete die Import-Aufgabe nicht, und die generierte
Stil-Tabelle benannte bei neun Größen-/Typografie-Reglern noch den Player statt
des neuen äußersten Player-Containers als erstes verändertes Element.

Die Browser bedienen jetzt die sichtbaren Aufklappbereiche und die Import-
Navigation. Export/Import und Fehler-Recovery wurden lokal erneut vollständig
geprüft. Die Audit-Tabelle wurde aus 88 tatsächlichen Computed-Style-Messungen
regeneriert: alle Regler wirken, die harte Vergleichsprüfung bleibt bestehen.
Die Follow-up-Korrektur verändert Tests und den Audit-Nachweis, keinen
Produktcode. Vor dem Merge muss die CI auf der endgültigen Commit-ID bestehen.

## Grenzen

Automatisierte Accessibility-Scans ersetzen keine Screenreader-Nutzerprüfung.
Keine Prüfung auf physischen Mobilgeräten oder Elementor Pro in diesem Lauf.
Manuell abweichende Widget-Stile können von der globalen Design-Vorschau
abweichen; die Herkunftsbeschreibung macht diesen Unterschied ausdrücklich.
Der tatsächliche Upload von VTT/SRT hängt von der jeweiligen MIME-Allowlist ab;
Multisite schließt diese Typen standardmäßig in der Netzwerk-Allowlist aus; direkt angelegte Attachments testen die
Zuordnung/Verarbeitung, nicht die Upload-Policy. Erfolgreiche Caption-Importtests
erweitern ausschließlich für den Lauf die Allowlist der markierten Test-Site
und stellen den ursprünglichen Netzwerkwert danach wieder her. Der Uninstall-
Probelauf besucht ausschließlich die Site, deren Daten er vorher sichert; die
separate Netzwerk-Lifecycle-Suite bleibt für networkweite Abnahmen zuständig.

Die grünen CI-Ergebnisse des früheren Commits `1dc0814` decken diesen neuen
Stand nicht ab. Kein Merge, Release, Deployment, Produktionsimport,
Hosting-Wechsel oder Verzeichnis-Submission wurde durchgeführt. Nach der
Abnahme wurden die drei lokalen Testcontainer einschließlich Webserver und
Datenbank gestoppt. Keine Browser- oder Testprozesse bleiben aktiv.
