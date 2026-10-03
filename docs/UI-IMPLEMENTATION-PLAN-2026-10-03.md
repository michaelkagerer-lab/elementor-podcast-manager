# UI-Umsetzungsplan — Phase 3

Stand: 3. Oktober 2026. Ausgangspunkt: Commit `3adfcab` und die 15 Befunde
UA-01 bis UA-15 in `docs/UI-AUDIT-2026-10-03.md`.
Das Go nach Phase 2 autorisiert diesen Plan. Die Auswahl für Phase 4 steht noch
aus, entsprechend `docs/ui-audit-work-order.md`.

## Ziel und gemeinsame Vorgaben

Podcast-Betreiber sollen ihren nächsten Schritt erkennen; Website-Bauer sollen
sehen, welche Einstellung wirkt und warum. Die visuelle Richtung bleibt native
WordPress-/Elementor-Bedienung mit den vorhandenen Podcast-Tokens. Das prägende
Detail bleibt die sichtbare Wirkung einer Aktion und die Herkunft eines Werts.

Alle Maßnahmen erhalten GUIDs, URLs, importierte lokale Bearbeitungen, manuelle
Widget-Einstellungen und gewählte Presets. Vorhandene Seiten werden nicht
überschrieben. Es werden keine zusätzlichen Dependencies vorgeschlagen.

K = kompakter Design-Skill (`docs/design-skills.md`),
P = Projekt-Design-Skill (`.agents/skills/project-design/SKILL.md`),
L = Design-Learnings Teil 1 (`docs/design-learnings-und-regeln.md`).
Die Maßnahmen sind in der empfohlenen Umsetzungsreihenfolge nummeriert.
Klein/mittel/groß bezeichnet den relativen Implementierungs- und Prüfaufwand,
keine zugesagte Laufzeit.

## Maßnahmen

1. **Status verständlich benennen — klein.** Befunde UA-07, UA-08.
   Einreichung und tatsächliche Listung getrennt zusammenfassen: beispielsweise
   „2 eingereicht, 1 gelistet“. Selbst eingetragene Angaben als solche erläutern.
   Feed-Bereitschaft als verständliche Zusammenfassung mit getrennten Fehlern und
   Hinweisen anzeigen; technische Prüfanzahlen bleiben untergeordnet verfügbar.
   Regel: K Content-Disziplin, L handlungsorientierte Hierarchie.
   Gleich bleibt: gespeicherte Directory-Statuswerte, ihre manuelle Pflege und
   die tatsächlichen Feed-Prüfregeln. Es gibt keine neue externe Verifikation.
   Abnahme: gemischte, leere und vollständig eingetragene Zustände ergeben
   zutreffende, unterscheidbare Zusammenfassungen auch auf Deutsch.

2. **Primäraktion der Distribution ordnen — klein.** Befund UA-06.
   Die nächste nicht eingereichte wesentliche Plattform erhält die primäre
   Einreichungsaktion. Kopieren, Feed-Test und Tracking bleiben klar benannte
   sekundäre Aktionen. Bei blockierenden Feed-Problemen wird deren Behebung
   hervorgehoben, ohne einen externen Prüf-Erfolg vorzutäuschen.
   Regel: K eine klare Primäraktion, L Buttons nach Hierarchie.
   Gleich bleibt: externe Einreichungslinks, Clipboard-Fallback, Listing-Links
   und manuelles Tracking. Keine automatische Einreichung oder neue Sperre.
   Abnahme: erster sinnvoller Schritt stimmt für Fehler, bereit, eingereicht und
   gelistet; Statusänderungen aktualisieren die Aktionsgewichtung.

3. **Dekoration und Rahmenrauschen reduzieren — klein.** Befunde UA-13, UA-15.
   Den redundanten Setup-Kicker entfernen, Schritt-Erfolg mit dem bestehenden
   SVG/Icon-System darstellen und Detail-Kontexte über Überschrift, Abstand und
   native Fieldset-Semantik statt wiederholter Innenrahmen gruppieren.
   Regel: K Anti-Slop und kohärente Icons, L weniger Rahmen, P semantisches HTML.
   Gleich bleibt: Schrittfolge, Fokusübergabe, beschriftete Gruppen und Werte.
   Abnahme: Text trägt Status unabhängig von Farbe/Icon; Gruppen bleiben mit
   Tastatur und assistiver Technik benannt, auch bei langen deutschen Labels.

4. **Lesekomfort gezielt verbessern — mittel.** Befund UA-12.
   Entscheidungsrelevante Hilfetexte, Statusmeldungen und Player-Zeitangaben
   erhalten eine abgestimmte Schrift-/Abstandsskala. Anpassung ausschließlich
   an plugin-eigenen Flächen; explizite Elementor-Typografie bleibt wirksam.
   Regel: K Typografie/Accessibility, P native Konventionen und gewählte Werte.
   Gleich bleibt: Schriftfamilien, Presets und Benutzer-Typografie. Kein globales
   Hochskalieren des WordPress-Admins auf eine neue Design-Schrift.
   Abnahme: lesbare Texte ohne abgeschnittene Labels bei 320/390 px, schmalen
   Elementor-Spalten und vergrößerter Ansicht; Kontrast/Fokus weiterhin geprüft.

5. **Player nach verfügbarer Komponentenbreite anpassen — mittel.** Befund UA-11.
   Für Full die Artwork-/Controls-Gewichtung auch in schmalen Desktop-Spalten
   responsiv machen, mit einem geeigneten umgebenden Container. Prüfen, dass die
   zusätzliche Größenabfrage keine intrinsische Breitenmessung kollabieren lässt.
   Regel: K responsive Hierarchie, P Container statt ausschließlich Viewport.
   Gleich bleibt: bewusst bildbetontes Artwork-Layout, Wiedergabeverhalten,
   Sticky-Wahl und explizite Widget-Stile. Keine neue Standard-Layout-Auswahl.
   Abnahme: 320/390/480-px-Komponenten innerhalb eines breiten Viewports plus
   normale Desktop-/Telefonansicht; Play, Seek, Volume und Share bleiben bedienbar.

6. **Wirksame Details und Herkunft sichtbar machen — mittel.** Befunde UA-02, UA-03.
   Globale Details nennen ihre Wirkung im gewählten Vorschau-Kontext: verfügbar,
   durch Layout verborgen oder ohne passende Episodendaten. Verborgene Flags
   bleiben als Vorgaben für andere Layouts erhalten. Elementor-Stilkontrollen
   zeigen zuordenbare globale Vorgaben und eigene Abweichungen; eine gezielte
   Rückkehr zur Vererbung verwendet Elementors undo-fähige Einstellungsaktionen.
   Regel: K echte Information/Konsistenz, L Wiedererkennen statt Erinnern.
   Gleich bleibt: Vorrang gespeicherter manueller Werte, Legacy-Verhalten und
   Style-Source-Wahl. Eine Rücksetzung erfolgt nur durch explizite Nutzeraktion.
   Verhaltensänderung: gezieltes Zurücksetzen einzelner unterstützter Overrides,
   statt ausschließlich der bisherigen Rücksetzung aller Details.
   Abnahme: Herkunft und aktueller Effekt stimmen für neue/alte Widgets,
   globale/individuelle Werte, Preset-Wechsel und Undo. Theme-Fallback wird als
   solcher benannt, nicht als vermeintlich fest ermittelter CSS-Wert ausgegeben.

7. **Design-Vorschau als zusammenhängenden Ablauf umbauen — groß;
   Rebuild-Kandidat.** Befunde UA-01, UA-14.
   Begründung: Der bestehende Ablauf mischt sofortige Farb-/Layout-Vorschau mit
   erst nach dem Speichern sichtbaren Details und mehreren gestapelten Beispielen.
   Einzelne Textkorrekturen beheben diesen Widerspruch nicht.
   Eine native Komponenten-Auswahl zeigt jeweils Player, Episodenseite, Liste
   oder Abonnieren. Ungespeicherte Detailänderungen werden mit den vorhandenen
   Renderern als Vorschau dargestellt. Auf Telefonen erhält das ausgewählte
   Beispiel eine natürliche Höhe statt eines dauerhaften inneren Scrollbereichs.
   Regel: K direktes Feedback/States, P wahrheitsgemäße Vorschau und Fokus.
   Gleich bleibt: gemeinsames explizites Speichern, Preset-Bestätigung, echter
   Frontend-Renderer und vorhandene Kontrastprüfung. Keine Auto-Speicherung.
   Verhaltensänderung: Falls für fehlende HTML-Teile erforderlich, eine begrenzte,
   authentifizierte und nonce-geschützte AJAX-Vorschau ohne Datenbank-Schreibzugriff.
   Schnelle Eingaben werden gebündelt; überholte Antworten dürfen neuere Werte
   nicht überschreiben. Fehler behalten die letzte gültige Vorschau und nennen
   die notwendige Wiederholung. Bestehende Dateien werden nicht heruntergeladen.
   Abnahme: Farben, Layouts und Details ergeben vor dem Speichern dieselbe Ausgabe
   wie danach; verzögerte Antworten, Fehler, Reset, Preset-Preview und Fokus bleiben
   korrekt. Abhängigkeit: Maßnahme 6 für die Kontext-/Verfügbarkeitsanzeigen.

8. **Setup aus der Ausgangssituation erklären — mittel;
   Rebuild-Kandidat für den ersten Schritt.** Befund UA-04.
   Begründung: Die bisherige Startfrage setzt bereits ein Hosting-Modell voraus.
   Einstieg über „Ich starte einen Podcast“ / „Mein Podcast läuft bereits“;
   für bestehende Podcasts anschließend „Host behalten“ / „hierher umziehen“.
   Konsequenzen und die nächste Handlung stehen direkt bei der Wahl.
   Regel: K erster Viewport, L mentale Modelle/Progressive Disclosure.
   Gleich bleibt: gespeicherte Pfade `new`, `external`, `move`, vorhandene
   Import-/Hosting-Prüfungen und Wiederaufnahme. Kein stiller Host-Wechsel.
   Verhaltensänderung: zweistufige Orientierung innerhalb der Auswahloberfläche;
   der Import- und Umzugsablauf selbst wird nicht ersetzt.
   Abnahme: alle drei Wege sind per Tastatur verständlich wählbar; bestehende
   Sitzungen öffnen ihre bisherige Auswahl und verlieren keinen Fortschritt.

9. **Hosting nach Aufgaben strukturieren — groß;
   Rebuild-Kandidat für die Verwaltungsoberfläche.** Befund UA-05.
   Begründung: Laufenden Betrieb, Feed-Import und Hosting-Umzug gleichzeitig
   darzustellen verlangt unnötige Orientierung; ein reiner CSS-Umbau reicht nicht.
   Aufgabenbereiche „Hosting und Synchronisation“, „Episoden importieren“ und
   „Podcast umziehen“ innerhalb der vorhandenen Seite anbieten. Ein aktiver Job
   bleibt sichtbar und führt zu seinem Fortschritt und seiner Wiederaufnahme.
   Risikoinformationen bleiben unmittelbar bei der jeweiligen Umzugsaktion.
   Regel: K Hierarchie/Gruppierung, P sichere Wiederaufnahme/Fehlerbehebung.
   Gleich bleibt: Feed-Validierung, Redirect-Schutz, Import-Locks, Medienkopie,
   GUIDs und lokale Bearbeitungen. Die Import-Engine wird nicht neu geschrieben.
   Verhaltensänderung: aufgabenbezogene Navigation und Kontext-Erhalt bei Reload,
   direkten Links und laufenden Jobs; keine neuen automatischen Hosting-Aktionen.
   Abnahme: Self/extern, unvollständiger Umzug, laufend/wartend/gestoppt/Fehler/fertig,
   direkte Links und Rechteprüfung; Fortschritt wird beim Bereichswechsel erhalten.

10. **Episoden-Erstellung und Elementor-Einstieg an Aufgaben ausrichten — groß.**
    Befunde UA-09, UA-10.
    Im Episodeneditor den Basisweg „Audio → Beschreibung → Veröffentlichen“
    hervorheben; zusätzliche Metadaten, Gast, Kapitel und Transkript bleiben
    eindeutig auffindbar. Native Metaboxen und Benutzer-Anordnung werden erhalten.
    Die Elementor-Bibliothek erhält verständliche Aufgabenbeschreibungen und
    bewusst eingefügte Starter für Show-Seite und Archiv. Ein wiederverwendbares
    Episoden-Layout bleibt an passende Einzel-Episoden-Kontexte gebunden.
    Regel: K States/erster Viewport, L Gruppierung, P native Konventionen.
    Gleich bleibt: Editor, gespeicherte Metadaten, bestehende Seiten und Widgets.
    Verhaltensänderung: zusätzliche, ausdrücklich gewählte Vorlagen; keine
    automatische Erzeugung weiterer veröffentlichter Seiten. Eine Theme-Builder-
    Episodenvorlage wird nur angeboten, wenn die installierte Elementor-Ausgabe
    sie unterstützt; Elementor Pro wird weder vorausgesetzt noch installiert.
    Abnahme: erste Episode mit unvollständigen Daten, bereits angeordnete Metaboxen,
    Elementor Free/aus, neue/alte Seiten, Vorlagen mit und ohne Episodenkontext.
    Episodenkontext und Layoutauswahl aus Maßnahmen 6 und 8 werden berücksichtigt.

## Prüf- und Abschlussrahmen für Phase 4

Vor jeder bestätigten Verhaltenskorrektur eine relevante fehlschlagende
Reproduktion ergänzen. Für reine reversible Darstellungsänderungen genügen
passende Sicht-/Interaktionsprüfungen; keine Tests, die nur CSS-Zeilen nachbauen.
Gezielte Tests laufen nur auf markierten Testseiten, mit Zeitlimits. Ein gebündelter
Desktop-/Mobile-Sichtdurchgang, anschließend höchstens ein Bestätigungsdurchgang
für die dort gefundenen Korrekturen; weitere Tests nur bei neuen begründeten
Fehlern. Prozesse und Testcontainer werden danach gestoppt.

Deutsche Texte, leere/Fehler-/Ladezustände, Tastatur, sichtbarer Fokus, Kontrast,
Reduced Motion und schmale Container gehören in die jeweiligen Abnahmen.
Alte Daten und explizite Widget-Werte erhalten Regressionstests. Die
Mindestversions-/Datenbank-/Browser-Matrix folgt nach den betroffenen Änderungen;
vorherige CI-Ergebnisse werden nicht für neue Commits übernommen.

Dieser Plan ändert noch keinen Plugin-Code. Die Auswahl kann über die Nummern
1–10 erfolgen; empfohlen sind alle Maßnahmen in dieser Reihenfolge. Ein Merge,
Release, Deployment oder eine Produktionsaktion ist kein Bestandteil von Phase 4.
