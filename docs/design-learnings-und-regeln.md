# Design-Learnings & Regeln

**Die große Referenz-Sammlung — destilliert aus YouTube, GitHub, Essays, Büchern und Talks.**
Stand: 2. Oktober 2026.

## Was ist das?

Kein Tool-Verzeichnis, keine Linkliste. Dieses Dokument sammelt **konkrete, umsetzbare Design-Regeln und Learnings** — das *Warum* und *Wie* guter Interfaces, destilliert aus den besten Quellen im Netz. Jede Regel ist mit ihrer Quelle attribuiert, damit du bei Bedarf ins Original gehen kannst.

**Teil 1** ist die kuratierte Regel-Sammlung (~90 Regeln in 10 Themen) — das Herzstück zum Nachschlagen.
**Teil 2** dokumentiert die Quellen mit ihren destillierten Learnings.
**Teil 3** ist das vollständige Quellenverzeichnis.

## Nutzungshinweise

- Regeln sind kontextabhängig (siehe Malewicz: Regelbruch mit Daten und Absicht ist legitim). Nutze sie als Ausgangspunkt, nicht als Dogma.
- Für Codex & Claude: Die Regeln aus Abschnitt 9 (Anti-Slop) und 10 (KI-Design) eignen sich direkt als System-Prompt-/Skill-Material.
- Verifikationsstufen: `✓ gelesen` = Quelle wurde im Browser vollständig oder großteils gelesen · `Recherche` = per Websuche/Auszug erschlossen, nicht vollständig gelesen.

---

# Teil 1 — Die Regel-Sammlung

## 1. Layout & Komposition

1. **Starte mit einem Feature, nicht mit dem Layout.** Baue erst die Kernfunktion (z. B. das Buchungsformular), die Hülle (Nav, Sidebar, Footer) kommt danach. — *Warum:* Die Hülle dient dem Inhalt; wer sie zuerst baut, gestaltet für Unbekanntes. *(Refactoring UI — Wathan/Schoger)*
2. **Beginne mit zu viel Weißraum.** Lieber großzügig starten und gezielt verdichten als umgekehrt. — *Warum:* Enge entsteht von allein durch Hinzufügen; Großzügigkeit muss man aktiv verteidigen. *(Refactoring UI)*
3. **Gruppiere durch Nähe und Abstand, nicht durch Rahmen.** Zusammengehöriges rückt zusammen; Trennung entsteht durch Abstand, Hintergründe oder Schatten — Rahmen nur als letztes Mittel. — *Warum:* Rahmen erzeugen visuelles Rauschen; Abstand gruppiert ruhiger (Gestalt: Nähe, Common Region). *(Robin Williams via Gerharz; Refactoring UI „use fewer borders")*
4. **Vermeide mehrdeutige Abstände:** große Lücken *zwischen* Gruppen, kleine *innerhalb*. — *Warum:* Einheitliche Abstände überall lassen die Hierarchie verschwimmen; das Auge braucht klare Gruppensignale. *(Refactoring UI)*
5. **Der erste Viewport beantwortet: was / für wen / was tun.** — *Warum:* Ohne diese Antwort scrollt niemand weiter; alles andere ist Deko. *(forgeloop Anti-Slop-Checks)*
6. **Grids sind Werkzeug, keine Pflicht; der Screen muss nicht gefüllt werden.** — *Warum:* Dogmatische Grid-Treue erzeugt sterile Layouts; bewusste Brüche schaffen Spannung. *(Refactoring UI)*
7. **Symmetrie für Vertrauen, Asymmetrie für Spannung — bewusst wählen.** Auf Smartphones: klar getrennte, gestapelte Module. — *Warum:* Unterschiedliche Viewports brauchen unterschiedliche Kompositionslogik. *(Raidboxes)*
8. **Definiere erst die Key Messages, dann das Layout.** — *Warum:* Klarheit im Marketingkonzept erzeugt Klarheit im Layout; Hervorhebung (Farbe/Typo/Bild) braucht ein Ziel. *(Raidboxes)*

## 2. Typografie

1. **Die vier Stellschrauben für Body-Text: 15–25px Größe, 120–145% Zeilenabstand, 45–90 Zeichen Zeilenlänge, professionelle Schrift.** — *Warum:* Diese vier Entscheidungen bestimmen, wie Body-Text wirkt — alles andere ist Feinschliff. *(Butterick, Practical Typography)*
2. **Nie unter Weight 400 in UI-Text; max. ~2–3 Textfarben pro Fläche und ≤2 Weights.** — *Warum:* Leichte Weights werden bei 14–16px unleserlich; jede zusätzliche Farbe/Gewicht verwässert die Hierarchie. *(Refactoring UI)*
3. **Betonung in Headlines mit italic/fett *derselben* Familie** — nie ein zufälliges Serif-Wort in eine Sans-Headline injizieren. — *Warum:* Familien-Mix zur „Aufpeppung" wirkt amateurhaft; echte Betonung bleibt im System. *(drastrong/design-taste-frontend)*
4. **Versalien nur unter einer Zeile, mit 5–12% Laufweite; zentrierten Text sparsam; nie unterstreichen (außer Links); geschweifte Anführungszeichen.** — *Warum:* Kleinschreibung ohne diese Details verrät sofort den Laien — Typografie-Details sind Vertrauenssignale. *(Butterick)*
5. **Einzüge ODER Absatzabstände, nie beides.** — *Warum:* Doppelte Absatzmarkierung ist redundant und unruhig. *(Butterick)*
6. **Fließtext linksbündig, an der Baseline ausrichten (nicht optisch zentrieren).** — *Warum:* Das Auge braucht eine stabile linke Kante (F-Pattern); zentrierte Titel über linksbündigem Text brechen das Lesemuster. *(Refactoring UI; Malewicz Deathloop-Critique)*
7. **Display-Headlines: groß, enges Tracking, kein Zeilenabstand** (z. B. `text-4xl md:text-6xl tracking-tighter leading-none`); **Body max. ~65ch.** — *Warum:* Große, dichte Headlines wirken selbstbewusst; begrenzte Measure schützt die Lesbarkeit. *(drastrong; Butterick)*

## 3. Farbe

1. **In HSL denken, nicht in Hex; Shades vorab definieren.** — *Warum:* HSL macht Helligkeit/Sättigung steuerbar; vordefinierte Shades verhindern beliebiges „Hineinklicken" von Farbtönen. *(Refactoring UI)*
2. **Grau muss nicht grau sein** — getönte Graus (leicht warm/kalt) wirken edler als neutrales Grau. — *Warum:* Reines Grau wirkt steril; getönte Graus binden die Palette zusammen. *(Refactoring UI)*
3. **Auf farbigen Flächen mit gleichfarbigen Abstufungen de-emphasieren, nicht mit Grau.** — *Warum:* Grau auf Farbe wird schlammig; ein hellerer/dunklerer Ton derselben Hue bleibt harmonisch. *(Refactoring UI)*
4. **Default: monochrome Basis + ein gedeckter Akzent.** — *Warum:* Eine Akzentfarbe diszipliniert den Farbeinsatz und ist die schnellste Anti-Slop-Maßnahme. *(saurav-codes/frontend-design)*
5. **Farbe nie als einziges Signal** (immer Icon/Text/Form ergänzen); **Kontraste ≥4.5:1 (Text) / 3:1 (groß/UI).** — *Warum:* Farbenblindheit und schlechte Displays machen reine Farbcodierung unlesbar; Kontrast ist messbar, Geschmack nicht. *(WCAG 2.2; rishi168-Checkliste)*
6. **Sättigung vor Helligkeit schützen** („don't let lightness kill your saturation"). — *Warum:* Helle Töne verlieren schnell ihre Farbigkeit und wirken dann ausgewaschen statt leicht. *(Refactoring UI)*

## 4. Visuelle Hierarchie

1. **Wichtigkeit mit Größe + Gewicht + Farbe kodieren, nicht mit Größe allein.** — *Warum:* Größe ist das lauteste und meistmissbrauchte Mittel; Gewicht und Farbe erzeugen Kontrast *bei gleicher Größe*. *(Refactoring UI)*
2. **Jedem Element vor dem Stylen eine Stufe zuweisen (primär/sekundär/tertiär).** — *Warum:* Betonung ist relativ und endlich — wenn alles fett ist, ist nichts fett. *(Refactoring UI)*
3. **Betone durch De-Emphase:** Wenn der Held nicht strahlt, mache die Nachbarn leiser statt ihn lauter. — *Warum:* Kontrast ist relativ; De-Emphase hält den Screen ruhig. *(Refactoring UI)*
4. **Buttons nach Hierarchie, nicht nach Semantik:** ein solider Primary, Secondary als Outline/Soft-Fill, Tertiary wie ein Link; zwei solide Buttons konkurrieren. — *Warum:* Nutzer folgen dem stärksten Signal; gleichwertige Buttons erzeugen Entscheidungslast. *(Refactoring UI)*
5. **Labels in Werte falten** („$19/mo", „12 verfügbar" statt „Preis: $19/mo"). — *Warum:* Das Label ist meist inferierbar und stiehlt dem Wert die Betonung. *(Refactoring UI)*
6. **Visuelle Hierarchie ≠ DOM-Hierarchie:** Die H1 muss nicht das Prominenteste sein — oft ist der *Inhalt* der Star. — *Warum:* Hierarchie folgt der Nutzerabsicht, nicht der Dokumentstruktur. *(Refactoring UI)*
7. **Von-Restorff-Effekt: Das Wichtige anders machen** — das Abweichende wird erinnert. — *Warum:* Isolation im Gedächtnis lenkt Aufmerksamkeit ohne Lautstärke. *(Laws of UX)*
8. **Serial-Position-Effekt: Wichtiges an Anfang und Ende von Listen.** — *Warum:* Erste und letzte Items werden am besten erinnert. *(Laws of UX)*

## 5. Interaktion & Motion

1. **Feedback innerhalb von 100ms auf jede Eingabe** (spätestens <400ms echte Reaktion). — *Warum:* Stille nach Input ist das stärkste Billig-Signal; unter 400ms wartet niemand auf niemanden (Doherty). *(Rauno Freiberg; Laws of UX)*
2. **Jede Animation braucht einen Zweck** (Transition, Supplement, Feedback, Demonstration) — reine Dekoration ist die riskanteste, wertloseste Kategorie. — *Warum:* Unbegründete Motion ist Rauschen, das Vertrauen kostet. *(Rachel Nabors via fluke-motion)*
3. **Nichts teleportiert:** Jedes Erscheinen/Verschwinden beantwortet „woher/wohin" — schon ein 120ms Fade-and-Shift genügt. — *Warum:* Räumliche Kontinuität macht Software begreifbar (physikalische Metapher). *(Rauno Freiberg)*
4. **Hochfrequente Interaktionen nicht animieren** (Command-Menüs, Kontextmenüs). — *Warum:* Nach dem hundertsten Mal wird dieselbe Animation zur kognitiven Steuer; Neuheit ist aufgebraucht. *(Rauno Freiberg)*
5. **Leichtgewichtiges während der Geste triggern, Destruktives erst am Gesten-Ende.** — *Warum:* Overlays dürfen sofort erscheinen (kein Commitment); Löschen/Schließen braucht Intent-Schutz inkl. Meinungsänderung. *(Rauno Freiberg)*
6. **Motion etabliert räumliche Beziehungen** (eine App startet aus der Dynamic Island *oder* ihrem Icon — und kommuniziert damit, wo sie „wohnt"). — *Warum:* Bewegung ist Information über Struktur, nicht Deko. *(Rauno Freiberg)*
7. **Microinteraction-Anatomie: Trigger → Regeln → Feedback → Loops & Modes.** — *Warum:* Die Struktur zwingt, jeden Moment vollständig zu designen statt nur „eine Animation draufzulegen". *(Dan Saffer)*
8. **Skeletons schlagen Spinner bei Loads >1s; Statusmeldungen spezifisch** („Suche Ersatzzutaten" statt „Verarbeitung…"). — *Warum:* Spezifisches Feedback reduziert Unsicherheit; Warten fühlt sich zweckvoll an. *(fluke-motion; Apple HIG)*
9. **Gesten responsiv ab dem ersten Pixel, Animationen unterbrechbar.** — *Warum:* Nicht-unterbrechbare Animationen sperren den Nutzer aus und zerstören das Gefühl direkter Manipulation. *(Rauno Freiberg)*
10. **States sind die Designfläche** (default/hover/active/focus/selected/disabled/loading/error/empty/overflow) — und `prefers-reduced-motion` respektieren. — *Warum:* Die meisten „kopierten" UIs zeigen nur den Default-State; billig wirkt, was keine Zustände kennt. *(Rauno Freiberg; WCAG)*

## 6. UX-Psychologie

1. **Hick's Law: Optionen kappen, Progressive Disclosure.** — *Warum:* Entscheidungszeit wächst mit Anzahl und Komplexität der Wahl. *(Laws of UX)*
2. **Miller's Law: Inhalte in 3–5er-Chunks gruppieren.** — *Warum:* Das Arbeitsgedächtnis fasst nur wenige Einheiten — Chunking entlastet. *(Laws of UX)*
3. **Jakob's Law: Plattform- und Branchen-Konventionen übernehmen.** — *Warum:* Nutzer verbringen ihre Zeit auf *anderen* Seiten; Neulernen ist kognitive Last. *(Laws of UX; NN/g)*
4. **Fitts's Law: große Ziele, kurze Wege** — primäre CTAs groß und in Daumenreichweite; Touch-Targets ≥44×44px mit ≥8px Abstand. — *Warum:* Zeit zum Ziel = f(Distanz, Größe). *(Laws of UX; forgeplan-Patterns)*
5. **Zeigarnik-Effekt: Unvollendetes sichtbar machen** (Fortschritt, Streaks, unvollständige Sammlungen). — *Warum:* Unerledigtes bleibt im Kopf und zieht zurück. *(Laws of UX)*
6. **Peak-End-Rule: Peak- und End-Momente bewusst designen.** — *Warum:* Erlebnisse werden nach Höhepunkt und Ende beurteilt, nicht nach Durchschnitt. *(Laws of UX)*
7. **Paradox of the Active User: selbsterklärend bauen — niemand liest Manuals.** — *Warum:* Nutzer starten sofort; jede nötige Erklärung ist ein Designfehler. *(Laws of UX)*
8. **Recognition > Recall:** Optionen und Aktionen sichtbar machen statt erinnern lassen. — *Warum:* Wiedererkennen entlastet das Gedächtnis; Erinnern belastet. *(NN/g)*
9. **Krug: Don't make me think** — für Scannen designen (Hierarchie, Konventionen, offensichtliche Klickbarkeit, Rauschen minimieren); Worte halbieren, dann nochmal; Klicks sind nicht das Problem, Aufwand ist es. — *Warum:* Nutzer scannen und „satisficen" — sie wählen die erste brauchbare Option. *(Steve Krug)*
10. **Error Prevention schlägt Error Messages;** Slips (Unaufmerksamkeit) vs. Mistakes (falsches mentales Modell) unterscheiden und jeweils anders adressieren. — *Warum:* Der beste Fehler ist der verhinderte; falsche Fehlerbehandlung frustriert. *(NN/g)*
11. **Tesler's Law: Komplexität lässt sich nur verschieben, nicht vernichten** — also beim System halten, nicht auf den Nutzer abwälzen. — *Warum:* „Einfach" für den Nutzer heißt Arbeit für das Produkt. *(Laws of UX)*
12. **Postel's Law: großzügig akzeptieren, konservativ senden** — fehlertolerante Inputs, saubere Outputs. — *Warum:* Menschen machen Eingabefehler; das System sollte sie auffangen, nicht bestrafen. *(Laws of UX)*
13. **Selective Attention: für das Ziel designen — alles andere ist Rauschen.** — *Warum:* Aufmerksamkeit ist begrenzt und zielgebunden; jedes irrelevante Element konkurriert mit dem Wesentlichen. *(Laws of UX)*

## 7. Accessibility

1. **Barrierefreiheit ist der Boden, nicht die Politur** — von Anfang an mitbauen; Retrofit ist um ein Vielfaches teurer. — *Warum:* Semantisches HTML und Tokens kosten anfangs fast nichts, nachträglich alles. *(districtsync-Standard)*
2. **Kontrast ≥4.5:1 (Text) / 3:1 (großer Text, UI-Komponenten); Fokus sichtbar und nie verdeckt; alles per Tastatur bedienbar, keine Fallen.** — *Warum:* Messbare Mindeststandards statt Bauchgefühl; ~15% der Nutzer sind direkt betroffen. *(WCAG 2.2 AA)*
3. **Touch-/Klickflächen ≥24×24 CSS-px (AA), besser 44×44; für Dragging immer eine Alternative anbieten.** — *Warum:* Kleine Ziele schließen motorisch eingeschränkte Nutzer aus — und alle Mobilnutzer. *(WCAG 2.2: 2.5.8, 2.5.7)*
4. **Semantisches HTML zuerst, ARIA nur als Ergänzung; async Updates via Live-Regions ansagen.** — *Warum:* Native Elemente bringen Tastatur, Rollen und Namen gratis mit; ARIA repariert nur, was HTML nicht kann. *(WCAG; rishi168)*
5. **Fehler als Anweisungen formulieren** („Gib eine gültige E-Mail ein"), nie als Codes; Fehler präzise benennen + Lösung anbieten + visuell auffällig. — *Warum:* Nutzer müssen erkennen, diagnostizieren und *sich erholen* können. *(NN/g Heuristik 9; saurav-codes)*
6. **Bewegung respektieren:** `prefers-reduced-motion` umsetzen; keine Zeitlimits fürs Review von KI-Output; „Stop generating" per Tastatur erreichbar. — *Warum:* Autonomie über das eigene Tempo ist Barrierefreiheit. *(WCAG; rishi168)*

## 8. Design-Systeme

1. **Ein Design-System = vernetzte Patterns + geteilte Praktiken im Dienst des Produktzwecks.** — *Warum:* Ohne Produktzweck ist ein System Selbstzweck; Effektivität misst sich am Beitrag zum Ziel. *(Alla Kholmatova)*
2. **Die Pattern-Library ist ein Werkzeug, nicht das System.** — *Warum:* Eine Komponentensammlung ohne geteilte Praktiken erzeugt Patchwork statt Kohärenz. *(Kholmatova)*
3. **Baue kein System, bevor du eins brauchst** — die Fundamente existieren meist schon; Systeme evolvieren mit dem Produkt. — *Warum:* Verfrühte Systeme erzeugen Bürokratie für Probleme, die noch niemand hat. *(ui_ux-Kapitel 10; Kholmatova)*
4. **Geteilte Sprache heißt geteilter Gebrauch:** Alle wissen, was ein Element ist UND warum/wann man es nutzt. — *Warum:* Ein „Button", den Designer, Entwickler und Nutzer je anders verstehen, ist keine Sprache. *(Kholmatova)*
5. **Funktionale Patterns sind Nomen/Verben, perzeptuelle sind Adjektive** (Ton, Typo, Farbe, Motion). — *Warum:* Die Trennung klärt, was ein Element *tut* vs. wie es sich *anfühlt* — und wo Marken-Differenzierung stattfindet. *(Kholmatova)*
6. **„Appropriate over consistent"** — Angemessenheit schlägt Einheitlichkeit. — *Warum:* Blinde Konsistenz opfert die bessere Lösung für die einheitliche. *(Kholmatova)*
7. **Ein System pro Projekt; offizielle Pakete nutzen statt CSS nachzubauen.** — *Warum:* Gemischte Systeme und nachgebaute Tokens verrotten bei jedem Update. *(drastrong)*
8. **Distinctiveness kommt aus Ausführung und Verknüpfung, nicht aus Pattern-Neuheit.** — *Warum:* Neue Patterns müssen erst gelernt werden (selten gut); Exzellenz in bekannten Patterns gewinnt. *(Kholmatova)*

## 9. Geschmack / Anti-Slop-Ästhetik

1. **Der AI-Slop-Test: Wenn man sofort erkennt „das hat eine KI gemacht", ist es gescheitert.** — *Warum:* Generisches Design entsteht durch Entscheidungsvermeidung; Geschmack ist ein Standpunkt. *(ai-trading design-taste)*
2. **The Iron Law: Nie die erste Version shippen** — Version 1 ist ein Entwurf zur Kritik; die Politur lebt in Durchgang 2 und 3. — *Warum:* Der Unterschied zwischen generisch und premium entsteht in den Überarbeitungsrunden. *(ai-trading)*
3. **Unsichtbare Details summieren sich** — Korrektheit, die niemand bewusst bemerkt, ist genau das, was man liebt, ohne zu wissen warum. — *Warum:* Qualität ist die Summe unsichtbarer Richtigkeit (Hover-States, Fokus-Ringe, Timings). *(ai-trading; Rauno Freiberg)*
4. **Guardrails statt Vibes:** Akzeptanzkriterien (benanntes Nutzerziel; echte Inhalte + echte States; A11y-Boden; rückverfolgbare Entscheidungen; Fehlerbehandlung; System-Fit) schlagen Geschmack, weil sie skalieren. — *Warum:* Geschmack lebt in einem Kopf; Standards lassen sich übergeben und prüfen. *(Strategic Studio)*
5. **Geschmack ist trainierbar:** echte, ausgelieferte Produkte studieren (Stripe, Linear, GitHub, Airbnb — besonders Error States und volle Screens); Basics meistern; immer „Warum?" fragen statt „Sieht es cool aus?". — *Warum:* Wer die Prinzipien kennt, erkennt, wann die Maschine falsch liegt. *(Balogun)*
6. **Harte Verbote für Slop-Patterns:** AI-Lila-Gradients, zentrierter Hero auf dunklem Mesh, drei gleiche Feature-Cards, generelles Glassmorphism, Endlos-Micro-Animationen, Inter + slate-900, Fraunces/Instrument-Serif-Defaults. — *Warum:* Das sind die statistischen Defaults von LLMs — wer sie nutzt, sieht aus wie alle. *(drastrong; saurav-codes)*
7. **Deko konkurriert nie mit Inhalt, Controls oder Fehlerzuständen; Claims und Testimonials echt oder als repräsentativ markiert.** — *Warum:* Fabrizierter Proof zerstört Vertrauen; Deko, die Funktion stört, ist kein Geschmack, sondern ein Bug. *(forgeloop)*
8. **Alle States gehören zum selben visuellen System** (empty, loading, error, focus, hover, pressed, disabled). — *Warum:* Inkonsistente States verraten Template-Denke sofort. *(forgeloop; Rauno)*
9. **„Weniger, aber besser"** — ehrlich, unaufdringlich, bis ins letzte Detail durchdacht. — *Warum:* Zurückhaltung ist die am schwersten zu kopierende Ästhetik. *(Dieter Rams)*
10. **Gutes Design fühlt sich offensichtlich an** — wertvoll, einfach, sorgfältig; die Offensichtlichkeit ist das Ergebnis langer Iteration. — *Warum:* „Oh, ja klar" ist das höchste Lob — es bedeutet, die Lösung wirkt unvermeidlich. *(Julie Zhuo)*

## 10. Zusammenarbeit mit KI beim Designen

1. **Mit ML designt man, wie das Produkt *funktioniert*, nicht nur wie es aussieht** — Modell-Entscheidungen sind Design-Entscheidungen (Daten, Metriken, Failure Paths). — *Warum:* Das Modell *ist* die Experience. *(kevingutowski; Apple ML Patterns)*
2. **Human-Opinion-Gesetz: Ohne menschliche Meinung produziert KI Slop** — Standpunkt, Referenzen und redaktionelle Entscheidungen einbringen. — *Warum:* Das Modell optimiert auf Durchschnitt; Differenzierung kommt vom Menschen. *(Ryo Lu via kevingutowski)*
3. **Confidence als Aktionen, nicht als Prozentzahlen** („warten vs. jetzt kaufen" statt „85% Match"). — *Warum:* Prozentzahlen sind Jargon; Aktionen sind entscheidbar. *(Apple ML Patterns)*
4. **Erklärungen mit objektiven Fakten, nie Geschmacks-Profiling** („Weil du NYT Cooking geladen hast", nie „weil du Kochen liebst"); Quellen zeigen. — *Warum:* Profiling-Sprache zerstört Vertrauen sofort. *(Apple ML Patterns)*
5. **Nie eine leere Chatbox shippen** — Beispiele, Templates und Fortsetzungen zeigen die Bandbreite des Systems. — *Warum:* Eine leere Eingabe ist kein Minimalismus, sondern ein unfertiges Feature. *(Julie Zhuo)*
6. **Chat mit direkter Manipulation verheiraten** (editierbare Canvas, Varianten, Buttons für endliche Auswahl) — nicht alles in Text zwingen. — *Warum:* Der offensichtlichste Modus pro Intent gewinnt („brauchst du ein Datum, nimm einen Date-Picker"). *(Zhuo)*
7. **Proaktive KI gehört in den Workflow** (Suggestion → Action → Question+Action), nicht als aufgeklebter Chat. — *Warum:* Der Kontext, in dem der Nutzer arbeitet, ist das Interface; Timing ist die Innovation. *(Evil Martians)*
8. **Linears Workbench-Modell:** strukturierter Kontext, native Metadaten, Review-Punkte; **Autonomie-Leiter** L1 (vorschlagen) → L2 (erster Durchlauf, korrigierbar) → L3 (begrenzt autonom, Mensch entscheidet final) — jede Stufe mit Sichtbarkeit, Reversibilität, Opt-in. — *Warum:* Autonomie ohne Leiter erzeugt Kontrollverlust oder Bestätigungsmüdigkeit. *(Linear via kevingutowski)*
9. **Korrekturen durch vertraute UI, nicht neues Chrome** — Edit/Undo/Retry direkt am generierten Inhalt; jede Anpassung sichtbar bestätigen. — *Warum:* Neues Chrome für Korrekturen erhöht die Lernlast; Vertrautes schafft Vertrauen. *(Apple HIG Generative AI)*
10. **Bestätigungen nur für Folgenschweres/Irreversibles** — jeder Dialog sagt, was wem mit welchem Risiko gewährt wird, plus sichtbarer Widerruf. — *Warum:* Gewohnheitsmäßige Approves trainieren einen Reflex, der informierte Zustimmung zerstört (Confirmation Fatigue). *(Paul Stamatiou)*
11. **Direkte Manipulation muss billiger bleiben als Prompten für Kleinst-Edits.** — *Warum:* Sonst wird das mächtige Werkzeug für die häufigsten Aufgaben zum umständlichsten. *(MDS via kevingutowski)*
12. **Wahrnehmung lesbar halten:** alte Daten nicht still für neues Reasoning nutzen (Offer-and-Consent); Personalisierung im Tempo der Beziehungsdauer. — *Warum:* Ein Assistent, der Dinge kommentiert, die er nie hätte sehen dürfen, zerstört Vertrauen instantan. *(IDEO via kevingutowski)*
13. **Bulletproof Stencils:** Layouts, die gut aussehen, egal was das Modell ausspuckt; das Belastende automatisieren, das Lohnende schützen. — *Warum:* Generative Outputs sind unvorhersehbar — das Gerüst muss jede Füllung tragen. *(Config via kevingutowski)*
14. **Evaluieren wie ein System:** Datensätze inkl. adversarial prompts, Unhappy Path testen, bei jedem Prompt-/Modellwechsel erneut. — *Warum:* Was nicht gemessen wird, wird geopfert. *(kevingutowski)*

---

# Teil 2 — Quellen mit destillierten Learnings

## A. YouTube: Kanäle, Talks, Video-Essays

### Michał Malewicz — Design-Critiques / UI-Roasts
- *Typ:* YouTube-Channel (Design-Kritik) · *Recherche*
- *Weiterführend:* [Medium-Artikel zum Squareblack-Redesign](https://michalmalewicz.medium.com/breaking-design-rules-for-higher-conversion-0d3c272aedbf) · [Deathloop-Critique-Zusammenfassung](https://gameworldobserver.com/2021/10/13/ux-designer-michal-malewicz-discusses-the-ui-of-deathloop)
- **Learnings:**
  - Lesbarkeit entsteht durch **Innenabstände (Padding) und konsistente Ausrichtung**, nicht durch neues Styling — die meisten UI-Probleme im Deathloop-Critique löste allein ein Margin-/Padding-Fix.
  - **Zentrierte Titel über linksbündigem Fließtext** brechen das F-Lesemuster und wirken unruhig — Titel linksbündig setzen.
  - Regeln sind kontextabhängig: Beim Squareblack-Redesign brach Malewicz bewusst eigene Design-Regeln → **3× mehr gebuchte Kundencalls, 1,5× mehr Abschlüsse**. Lektion: Regeln dienen Outcomes; wer sie bricht, braucht Daten und Absicht.
  - Gute Kritik begründet immer das *Warum* (visuelle Hierarchie, Farbtheorie) statt nur „gefällt mir nicht".

### Jesse Showalter
- *Typ:* YouTube-Channel (Micro-Interactions, Accessibility, Design Systems) · *Recherche*
- **Learnings:**
  - Leitgedanke: *„Good UX doesn't feel designed. It feels inevitable."* → Ziel ist die **Unsichtbarkeit des Interfaces** hinter der Aufgabe.
  - Micro-Interactions und Accessibility werden ruhig und systematisch erklärt — gutes Vorbild für das Erklären von „Warum".

### Mike Monteiro — „13 Ways Designers Screw Up Client Presentations" (IxDA-Keynote)
- *Weiterführend:* [Seite mit eingebettetem Video](https://github.com/ulfschneider/ulf.website/blob/HEAD/content/posts/2020-07-27-13-ways-designers-screw-up-presentations.md)
- **Learnings:**
  - **Präsentieren ist eine Kern-Designfähigkeit**, keine Zusatzfähigkeit: „Work that can't be sold is as useless as the designer who can't sell it."
  - Nie mit einer **Entschuldigung** beginnen — jede Entschuldigung gibt dem Kunden einen Grund zu misstrauen.
  - **Selbstbewusstsein überträgt sich:** Deine Confidence ist die Confidence des Kunden.
  - Man ist nicht da, um der Freund des Kunden zu sein — man löst ein Problem; **Konfrontation zu vermeiden wird mit der Zeit teurer**.

### Tony Fadell — „The first secret of design is… noticing" (TED Talk)
- **Learnings:**
  - Gutes Design beginnt mit **Bemerken**: Gewöhnung (Habituation) macht blind für Probleme.
  - Mit frischen Augen hinschauen — die Details, die man bemerkt, werden zum eigentlichen Design-Brief.

### Julie Zhuo — „How a Facebook Designer Thinks" (Talk)
- **Learnings:**
  - Designer-Denken auf Facebook-Skala: Entscheidungen über Nutzerpsychologie statt über Pixel.
  - Ergänzt ihr Essay „Good Design": wertvoll + einfach + sorgfältig.

### Joe Gebbia — „How Airbnb designs for trust" (TED Talk)
- **Learnings:**
  - Vertrauen wird in **kleinen Momenten** designed — Microcopy, User Flows und Microinteractions überwinden den „Stranger Bias".
  - Design-Ziel kann eine Emotion (Vertrauen zwischen Fremden) sein, nicht nur eine Aufgabe.

### Margaret Gould Stewart — „How giant websites design for you" (TED Talk)
- **Learnings:**
  - Bei Milliarden-Reichweite (Like-/Share-Buttons) sind **winzige Änderungen riesig** — Gründlichkeit skaliert mit Reichweite.
  - Für die Extreme/Ränder designen, schrittweise ausrollen, messen.

### Adrian Zumbrunnen — „Meaningful Motion in Design" (WebExpo Prague)
- **Learnings:**
  - Delight allein reicht nicht — **Motion braucht Intent**: sanftes Feedback, Navigationshilfe, Unterstützung der UI-Story.
  - Details schwitzen, die etwas *bedeuten*, nicht Deko-Animation.

### Don Norman — „The three ways that good design makes you happy" (TED Talk)
- **Learnings:**
  - Design wirkt auf drei Ebenen: **visceral** (Aussehen/Gefühl), **behavioral** (Funktion/Usability), **reflective** (Bedeutung, Selbstbild).
  - Gute Produkte müssen alle drei Ebenen bedienen — Schönheit ist kein Luxus, sondern eine Wirkungsebene.

### Ethan Marcotte — „Rolling up Our Responsive Sleeves"
- **Learnings:**
  - Responsive Design entmystifiziert: **Content-first, fluide Systeme** statt starre Breakpoint-Denke.
  - Storytelling als Mittel, technische Konzepte vermittelbar zu machen.

### TeachMeToDesign — „Graphic Design Theory" (16-teilige Playlist)
- *Weiterführend:* [YouTube-Playlist](https://www.youtube.com/playlist?list=PL0EqspLrOAxRX_IeXFX1jcaqHKCc45IJx)
- **Learnings (als Vokabelkurs):**
  - Farbe (2 Teile), Kontrast (2 Teile), Typografie: Lesbarkeit/Leserlichkeit + Font-Pairing, Gestalt-Prinzipien, Hierarchie mit Fonts, Grids, Negative Space, Focal Point.
  - Klassische Gestaltungslehre systematisch auf UI übertragbar — ein strukturierter Grundlagenkurs.

### Caler Edwards — Button-Tipps & „5 Quick Tips"
- *Weiterführend:* [Video 1](https://www.youtube.com/watch?v=Ia2bZCjgsco) · [Video 2](https://www.youtube.com/watch?v=D4W0BIRF41Y)
- **Learnings:**
  - **Hierarchie zuerst planen**, dann Details.
  - Zugängliche Navigation, klare Dialoge/Copy, **Feedback auf jede Aktion**, User Testing — die fünf Basics, die 80% der UI-Probleme verhindern.

### Weitere empfehlenswerte Kanäle
- **DesignCourse** (Gary Simon) — „Design and Build"-Serien Figma→Code, projektbasiert
- **Mizko** — Advanced Figma, Design Systems, erklärt das „Warum"
- **AJ&Smart** — Design-Sprint-Methodik, UX-Strategie
- **Flux Academy** — Business-Seite des Designs
- **The Futur** (Chris Do) — Design-Philosophie: „Design is not about making things pretty. It's about making things work for people."
- **CharliMarieTV** — ehrliche Projekt-Walkthroughs
- **UX Crash Course** — Usability-Testing mentorhaft

---

## B. GitHub: Guides, Styleguides, Design-System-Dokus, Awesome-Listen, Agent-Skills

### dariusz-klibisz/ui_ux — UI/UX-Prinzipien-Repository
- [github.com/dariusz-klibisz/ui_ux](https://github.com/dariusz-klibisz/ui_ux) · *Recherche*
- Strukturiert Designwissen in 10 Kapiteln: Kern-Prinzipien (Nielsen, Norman, Gestalt, Laws of UX, Shneiderman, ISO 9241-110), kognitive Grundlagen (Cognitive Load, Recognition vs. Recall, Progressive Disclosure, Slips vs. Mistakes, Flow), Visual Design (Hierarchie, Typo, Farbe inkl. OKLCH, 8pt-Grid, Elevation, Dark Mode), Interaction (States, Response-Zeiten, Motion, Loading, Optimistic UI, Undo vs. Confirmation), Accessibility (WCAG 2.2 mit Nummern), ARIA-Widgets, Forms (Validation-Timing, Fehler-Design, Autosave, Passkeys), Navigation/IA, UX-Writing, Design-Systeme (**wann man KEIN System baut**, Tokens, Governance). Wertvoll als Checkliste/Referenz.

### kevingutowski/skills — ai-experience-design/SKILL.md ✓ gelesen
- [Skill ansehen](https://github.com/kevingutowski/skills/blob/HEAD/ai-experience-design/SKILL.md)
- **Learnings:**
  - *„With ML, you design how the product works, not just how it looks — machine learning decisions are all design decisions."*
  - **Confidence als Aktionen, nicht Prozentzahlen**; Erklärungen mit **objektiven Fakten, nie Geschmacks-Profiling**.
  - **Linears Workbench-Modell + Autonomie-Leiter** (L1 vorschlagen → L2 erster Durchlauf mit Korrektur → L3 begrenzt autonom mit menschlichem Final-Judgment; jede Stufe braucht Sichtbarkeit, Reversibilität, Opt-in).
  - **Confirmation Fatigue**: Bestätigungen nur für Folgenschweres/Irreversibles.
  - **Ryo Lu (Cursor):** *„If you don't put in that opinion, it will just produce AI slop"* (Human-Opinion-Gesetz); direkte Manipulation muss billiger bleiben als Prompten für Kleinst-Edits.

### drastrong/design-taste-frontend — Anti-Slop Frontend Skill ✓ teilweise gelesen
- [Skill ansehen](https://github.com/drastrong/design-taste-frontend/blob/HEAD/plugins/design-taste-frontend/skills/design-taste-frontend/SKILL.md)
- **Learnings:**
  - **Brief-Inferenz zuerst:** Page-Art, Vibe-Wörter, Referenzsignale, Zielgruppe, Marken-Assets, stille Constraints lesen; einzeilige „Design Read" formulieren. *Die Zielgruppe wählt die Ästhetik, nicht dein Geschmack.*
  - **Anti-Default-Disziplin:** nie defaulten auf AI-Lila-Gradients, zentrierten Hero auf dunklem Mesh, drei gleiche Feature-Cards, generelles Glassmorphism, Endlos-Micro-Animationen, Inter + slate-900.
  - **Typografie-Disziplin:** Display groß mit tightem Tracking; Body max ~65ch; Inter als Default ist ein „AI tell".

### ai-trading/design-taste — Design-Philosophie
- [Skill ansehen](https://github.com/1239890829/ai-trading/blob/HEAD/skills/design-taste/SKILL.md) · *Recherche*
- **Learnings:**
  - *„Taste is trained, not innate"* — Geschmack entsteht durch Studium und Reverse-Engineering der besten Interfaces.
  - **Unsichtbare Details summieren sich:** Was niemand bewusst bemerkt, ist genau das, was man liebt, ohne zu wissen warum.
  - **Der AI-Slop-Test:** Wenn man sofort erkennt „das hat eine KI gemacht", ist es gescheitert.
  - **The Iron Law: never ship the first version** — die Politur lebt in Durchgang 2 und 3.

### forgeloop/taste-frontend — Anti-Slop-Checks
- [Guide ansehen](https://github.com/cassiomc1/forgeloop/blob/HEAD/ENG/taste-frontend-eng.md) · *Recherche*
- **Learnings:** Erster Viewport beantwortet was/für wen/was tun · stärkster Kontrast/Weißraum stützt die Hierarchie · Bilder/Testimonials echt oder als repräsentativ markiert · **alle States gehören zum selben visuellen System**.

### saurav-codes/frontend-design — minimalistische Design-Taste-Skill
- [Repo ansehen](https://github.com/saurav-codes/frontend-design) · *Recherche*
- **Learnings:** Utilitaristischer Minimalismus; harte Verbote für Slop-Patterns; warmes Monochrom + ein gedeckter Akzent; UX-Gesetze als harte Limits (Feedback <400ms, Fitts, Jakob); Copy-Regeln (Fehler als Anweisungen, kein Fülltext).

### Refactoring-UI-Destillate (Wathan/Schoger)
- [tactics.md](https://github.com/flagrare/agent-skills/blob/HEAD/plugins/flagrare/skills/design-review/references/tactics.md) · [design-philosophy.md](https://github.com/howells/arc/blob/HEAD/references/design-philosophy.md) · [refactoring_ui.md](https://github.com/hughbien/notebook/blob/HEAD/refactoring_ui.md) · *Recherche (drei übereinstimmende Destillate)*
- Höchste Dichte an direkt anwendbaren visuellen Regeln aller Quellen — siehe Regeln in Teil 1 (Abschnitte 1–4).

### Edward-Tufte-Destillate (Datenvisualisierung)
- [SKILL 1](https://github.com/mrfentmen/skills-2/blob/HEAD/edward-tufte/SKILL.md) · [SKILL 2](https://github.com/sethmblack/paks-skills/blob/HEAD/edward-tufte/SKILL.md) · [SKILL 3](https://github.com/chinnj1/tufte-claude-skill) · *Recherche*
- **Learnings:** **Above all else show the data** · **Data-Ink-Ratio maximieren** (redundantes Data-Ink löschen) · **Chartjunk verbannen** · **grafische Integrität** (Lie Factor = 1.0, Balken bei Null) · **Small Multiples** statt überladener Grafiken · immer fragen: **„Compared to what?"**

### Gestalt-Prinzipien für UI
- [gestalt-principles.md](https://github.com/alpham8/claude-webdev/blob/HEAD/skills/wondelai/ux-design-principles/references/gestalt-principles.md) · *Recherche*
- **Learnings:** **Common Region schlägt Proximity** · **Uniform Connectedness ist das stärkste Gruppierungsprinzip** · Figure/Ground für CTAs und Modals · Closure/Continuity für Steps und Breadcrumbs.

### Motion-Grundlagen
- [fundamentals.md](https://github.com/luthfierlmbang/fluke-motion/blob/HEAD/skills/fluke-motion/references/fundamentals.md) · *Recherche*
- **Learnings:** Taxonomie nach Zweck (Transitions, Supplements, Feedback, Demonstrations, **Decorations** = höchstes Risiko) · Press-Feedback bei ~100ms · **Skeletons schlagen Spinner** bei Loads >1s · Realtime/interruptible Motion.

### Rauno-Freiberg-Destillat (Web-UI)
- [rauno-freiberg.md](https://github.com/samuraizac/web-ui-mastery/blob/HEAD/skills/web-ui-mastery/references/designers/rauno-freiberg.md) · *Recherche*
- **Learnings:** Interfaces sind physische Systeme (nichts teleportiert) · **Details sind kein Polish, sie sind das Design** · **Feedback innerhalb 100ms, immer** · **States sind die Designfläche** · **Plattform-Reflexe respektieren** (Cmd-Klick, Back-Button, Zoom nie deaktivieren).

### Laws-of-UX-Skills & UI-UX-Patterns
- [laws-of-ux](https://github.com/forgeplan/marketplace/blob/HEAD/plugins/laws-of-ux/README.md) · [ui-ux-patterns](https://github.com/abhishek-mittal/jellow-app/blob/HEAD/.github/skills/ui-ux-patterns/SKILL.md) · *Recherche*
- **Learnings:** 30 Gesetze in 4 Kategorien + 9 Code-Patterns: Touch-Targets ≥44×44px · max. 7 Navi-Punkte + Progressive Disclosure · 400ms-Response-Schwelle · CTA-Abhebung via Kontrast · Fortschrittsbalken (Zeigarnik, Peak-End) · Smart Defaults.

### Nielsen-Heuristiken-Audit-Checklisten
- [nielsen-heuristics.md](https://github.com/silviaare95/wayworks/blob/HEAD/plugins/design/skills/heuristic-eval/references/nielsen-heuristics.md) · [nielsen-heuristics-audit](https://github.com/farenravirar/artificio/blob/HEAD/.agents/skills/nielsen-heuristics-audit/SKILL.md) · *Recherche*
- **Learnings:** Severity-Skala (4 katastrophal → 0 kein Problem); Heuristik-Evaluation findet ~75% der Issues · Quick Wins: Loading-Indikatoren, Fehlermeldungen, Tooltips, konsistente Buttons, Bestätigungsdialoge.

### Accessibility-Checklisten (WCAG 2.2)
- [UI-UX Designer Skill](https://github.com/rishi168/ai-product-lifecycle/blob/HEAD/AI%20Product%20Deployment%20Agent%20Team/Planning%20And%20Architecture/UI-UX%20Designer%20Agent%20_%20SKILL.md) · [product-ux.md](https://github.com/myblueprint-spaces/districtsync/blob/HEAD/docs/claugentic-standards/product-ux.md) · *Recherche*
- **Learnings:** Kontrast ≥4.5:1/3:1 · Targets ≥24×24 CSS-px · Fokus sichtbar + nicht verdeckt · Dragging-Alternativen · `prefers-reduced-motion` · semantisches HTML zuerst · Farbe nie als einziges Signal.

---

## C. Blogs, Essays, Bücher

### Matthew Butterick — „Butterick's Practical Typography" ✓ gelesen
- [Summary of Key Rules](https://practicaltypography.com/summary-of-key-rules.html)
- **Learnings:** Vier Stellschrauben (15–25px, 120–145%, 45–90 Zeichen, professionelle Schrift) · geschweifte Anführungszeichen · fett ODER kursiv, nie beides · nie unterstreichen (außer Links) · Einzüge ODER Absatzabstände · Kerning immer an.

### Adam Wathan & Steve Schoger — „Refactoring UI" (Buch, 2018)
- *Recherche (drei übereinstimmende GitHub-Destillate, siehe B)*
- Die höchste Dichte an direkt anwendbaren visuellen Regeln aller Quellen: Feature-first, Grayscale-first, restriktive Skalen, Hierarchie-Mechanik, HSL-Farbsysteme, Lichtquelle-konsistente Tiefe, „use fewer borders", Empty States.

### Dieter Rams — „Ten Principles for Good Design" (1977)
- **Learnings:** Innovativ, nützlich, ästhetisch, verständlich, unaufdringlich, ehrlich, langlebig, durchdacht, umweltfreundlich — und **so wenig Design wie möglich** (*„Weniger, aber besser"*).

### Julie Zhuo — „Good Design" (Essay)
- [Essay lesen](https://medium.com/the-year-of-the-looking-glass/good-design-a89c15136ba6) · *Recherche*
- **Learnings:** *„You know you have a good design when you show it to people and they say 'oh, yeah, of course,' like the solution was obvious."* · Gutes Design = **wertvoll + einfach + sorgfältig**.

### Rauno Freiberg — „Invisible Details of Interaction Design" ✓ teilweise gelesen
- [Essay lesen](https://rauno.me/craft/interaction-design)
- **Learnings:** Interaktionen modellieren reale Physik (Unterbrechbarkeit, Momentum) · Metaphern sind lernbar und komponierbar · destruktive Aktionen erst am Gesten-Ende · hochfrequente Interaktionen nicht animieren · Motion etabliert räumliche Beziehungen.

### Jon Yablonski — „Laws of UX" ✓ gelesen
- [lawsofux.com](https://lawsofux.com/)
- **Learnings:** Aesthetic-Usability Effect · Doherty Threshold (<400ms) · Fitts's Law · Hick's Law · Jakob's Law · Peak-End Rule · Tesler's Law · Von-Restorff-Effekt · Zeigarnik-Effekt · Paradox of the Active User · Postel's Law.

### Nielsen Norman Group — „10 Usability Heuristics" ✓ gelesen
- [Artikel lesen](https://www.nngroup.com/articles/ten-usability-heuristics/)
- **Learnings:** Systemstatus sichtbar · Sprache der Nutzer · Notausgang/Undo · Error Prevention · Recognition statt Recall · *jede zusätzliche Informationseinheit konkurriert mit den relevanten* · Fehler in einfacher Sprache mit Lösung. Seit 1994 unverändert gültig.

### Steve Krug — „Don't Make Me Think" (Buch, destilliert)
- **Learnings:** Regel #1: **Don't make me think** · niemand liest, alle scannen · Satisficing (erste brauchbare Option gewinnt) · Konventionen sind Freunde · **Klicks sind nicht das Problem, Aufwand ist es** · Worte halbieren, dann nochmal · Zurück-Button = 30–40% aller Klicks.

### Dan Saffer — „Microinteractions" (Buch, destilliert)
- **Learnings:** Jede Microinteraction = **Trigger → Regeln → Feedback → Loops & Modes** · *„Bring the data forward"* · *„Don't start from zero"* (Smart Defaults) · menschliche Eingaben verzeihen.

### Alla Kholmatova — „Design Systems" (Buch, 2017) ✓ teilweise gelesen
- [Reading Notes](https://raw.githubusercontent.com/schalkventer/reading-notes/2cb1aac1d220e0ba64f3ec108aa3048168863966/design-systems.md)
- **Learnings:** System = vernetzte Patterns + geteilte Praktiken · Pattern-Library ≠ System · Systeme evolvieren · funktionale vs. perzeptuelle Patterns · **„Appropriate over consistent"**.

### Design-Taste-Essays (AI-Ära)
- [Gegen-These: Geschmack vs. Guardrails](https://medium.com/@uxsurvivalguide/the-obsession-with-taste-is-setting-designers-back-b92ba47119c6) · [Taste aufbauen](https://medium.com/@olamidebalogun56/how-to-build-real-design-taste-in-the-era-of-ai-slop-b2838cf4c729) · *Recherche*
- **Learnings:** Guardrails (Akzeptanzkriterien) skalieren besser als Geschmack · Taste trainieren durch Studium echter Produkte (Stripe, Linear, GitHub, Airbnb — besonders Error States) · immer „Warum?" fragen.

---

## D. Case Studies (Redesigns mit Begründung)

### Malewicz — Squareblack-Redesign
- [Case Study](https://michalmalewicz.medium.com/breaking-design-rules-for-higher-conversion-0d3c272aedbf) · *Recherche*
- Vereinfachtes Logo + rechtsbündiges Menü verlagerten das visuelle Gewicht auf Headline/CTA → **3× mehr gebuchte Calls, 1,5× mehr Abschlüsse**. *„Times change. While a lot of design stays universal, new challenges may require unusual approaches."*

### Ivana Jíleková — Bitcoin-ATM-Usability-Studie (WebExpo Prague)
- **Learnings:** Billiges, informelles Usability-Testing deckt überraschend wichtige Probleme auf; billige Fixes auf Basis einfacher Tests verbessern die Experience massiv.

### Jan Kvasnička — „Mobile and Responsive Web Design Mistakes" (WebExpo Prague)
- **Learnings:** Mobile-first für kleine Screens optimieren; bewährte Mobile-Patterns nutzen; Optimierung primär auf **User Testing und echte Verhaltensdaten** stützen.

---

## E. Deutschsprachige Quellen

### Michael Gerharz — „Die vier Prinzipien professionellen Designs"
- [Artikel lesen](https://michaelgerharz.com/die_vier_prinzipien_professionellen_designs/) · *Recherche*
- Nach Robin Williams: **Nähe, Ausrichtung, Wiederholung, Kontrast** — allein durch Umgruppierung nach Nähe wirkt ein chaotisches Layout geordnet.

### Raidboxes Magazin — „Grundprinzipien für harmonisches Webdesign"
- *Recherche*
- Symmetrie/Asymmetrie bewusst entscheiden · großzügiger Weißraum · **erst Key Messages definieren, dann gestalten** · Hervorhebung via Farbe/Typografie/Bild.

### Dieter Rams
- *„Weniger, aber besser"* — die prägnanteste Anti-Slop-Regel überhaupt, auf Deutsch.

---

## F. Bewusst ausgelassen

Generische „Top-Prinzipien"-Listicles und reine Kanal-Listen ohne Kritik wurden ausgelassen: Sie wiederholen „Konsistenz, Einfachheit, Nutzerzentrierung" ohne eine einzige konkrete, überprüfbare Regel — kein Erkenntnisgewinn gegenüber NN/g oder Laws of UX. Tool-Tutorials ohne Gestaltungslehre ebenfalls.

---

# Teil 3 — Quellenverzeichnis (vollständig)

**Verifiziert gelesen:**
- https://lawsofux.com/
- https://practicaltypography.com/summary-of-key-rules.html
- https://www.nngroup.com/articles/ten-usability-heuristics/
- https://rauno.me/craft/interaction-design
- https://github.com/kevingutowski/skills/blob/HEAD/ai-experience-design/SKILL.md
- https://github.com/drastrong/design-taste-frontend/blob/HEAD/plugins/design-taste-frontend/skills/design-taste-frontend/SKILL.md
- https://raw.githubusercontent.com/schalkventer/reading-notes/2cb1aac1d220e0ba64f3ec108aa3048168863966/design-systems.md

**Per Recherche erschlossen:**
- https://medium.com/design-ninjas/top-youtube-channels-to-learn-ux-ui-design-beginner-to-advanced-2edf99ad3311
- https://medium.com/@atnoforuiuxdesigning/youtube-channels-podcasts-and-newsletters-that-actually-make-you-a-better-designer-771bd6af2ea6
- https://www.youtube.com/playlist?list=PL0EqspLrOAxRX_IeXFX1jcaqHKCc45IJx
- https://www.youtube.com/watch?v=Ia2bZCjgsco · https://www.youtube.com/watch?v=D4W0BIRF41Y
- https://michalmalewicz.medium.com/breaking-design-rules-for-higher-conversion-0d3c272aedbf
- https://gameworldobserver.com/2021/10/13/ux-designer-michal-malewicz-discusses-the-ui-of-deathloop
- https://github.com/dariusz-klibisz/ui_ux
- https://github.com/1239890829/ai-trading/blob/HEAD/skills/design-taste/SKILL.md
- https://github.com/cassiomc1/forgeloop/blob/HEAD/ENG/taste-frontend-eng.md
- https://github.com/saurav-codes/frontend-design
- https://github.com/flagrare/agent-skills/blob/HEAD/plugins/flagrare/skills/design-review/references/tactics.md
- https://github.com/howells/arc/blob/HEAD/references/design-philosophy.md
- https://github.com/hughbien/notebook/blob/HEAD/refactoring_ui.md
- https://github.com/mrfentmen/skills-2/blob/HEAD/edward-tufte/SKILL.md
- https://github.com/alpham8/claude-webdev/blob/HEAD/skills/wondelai/ux-design-principles/references/gestalt-principles.md
- https://github.com/luthfierlmbang/fluke-motion/blob/HEAD/skills/fluke-motion/references/fundamentals.md
- https://github.com/samuraizac/web-ui-mastery/blob/HEAD/skills/web-ui-mastery/references/designers/rauno-freiberg.md
- https://github.com/forgeplan/marketplace/blob/HEAD/plugins/laws-of-ux/README.md
- https://github.com/rishi168/ai-product-lifecycle/blob/HEAD/AI%20Product%20Deployment%20Agent%20Team/Planning%20And%20Architecture/UI-UX%20Designer%20Agent%20_%20SKILL.md
- https://github.com/myblueprint-spaces/districtsync/blob/HEAD/docs/claugentic-standards/product-ux.md
- https://medium.com/the-year-of-the-looking-glass/good-design-a89c15136ba6
- https://medium.com/@uxsurvivalguide/the-obsession-with-taste-is-setting-designers-back-b92ba47119c6
- https://medium.com/@olamidebalogun56/how-to-build-real-design-taste-in-the-era-of-ai-slop-b2838cf4c729
- https://michaelgerharz.com/die_vier_prinzipien_professionellen_designs/

---

## Hinweise zur Verifikation

- 7 Quellen wurden vollständig oder großteils im Browser gelesen (oben als „✓ gelesen" markiert); der Rest wurde per Websuche und Auszügen erschlossen.
- YouTube-Videos wurden nicht selbst angesehen — Learnings stammen aus Zusammenfassungen, Artikeln und Transkript-Auszügen.
- Das Buch „Refactoring UI" (kostenpflichtig) wurde nicht im Original gelesen; die Regeln stützen sich auf drei unabhängige, übereinstimmende GitHub-Destillate.
- Der AI-Slop-Diskurs ist schnelllebig; einige Thesen sind meinungsstark und nicht empirisch belegt.
- Alle Quellen wurden am 2. Oktober 2026 geprüft; YouTube-Inhalte und GitHub-Repos ändern sich laufend.
