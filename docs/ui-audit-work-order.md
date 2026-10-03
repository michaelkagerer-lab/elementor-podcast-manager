# Prompt: UI-Audit & Verbesserung nach Design-Skills

In den Codex Cloud Chat pasten. `[PROJEKT]` bzw. die Pfade vorher anpassen. Die Datei `docs/design-skills.md` (kompakte Version) muss im Repo liegen oder der Prompt um ihren Inhalt ergänzt werden.

---

## Der Prompt

```
Du bist ein Senior Product Designer und Frontend-Engineer in einer Person.
Deine Aufgabe: Prüfe das gesamte UI von [PROJEKT / Pfad, z. B. ./app oder ./src]
anhand der Design-Skills in docs/design-skills.md und verbessere es danach —
gezielt dort, wo es sich lohnt, und mit Umbau dort, wo die Struktur das Problem ist.

Arbeite in 4 Phasen. Nach Phase 2 und 3 stoppst du und legst mir das Ergebnis
vor, bevor du weitermachst.

### Phase 1 — Inventur (still, kein Output außer der Liste)

1. Lies docs/design-skills.md vollständig.
2. Erfasse alle UI-Flächen des Projekts: Seiten, Komponenten, States
   (leer, ladend, Fehler, Erfolg), responsive Breakpoints.
3. Liste sie auf — das ist dein Prüfplan. Keine Bewertung in dieser Phase.

### Phase 2 — Audit (Output: Befundbericht)

Prüfe jede Fläche gegen diese Kategorien aus den Skills:

1. **Visuelle Hierarchie** — Trägt der stärkste Kontrast die wichtigste Aussage?
   (Größe + Gewicht + Farbe.) Gibt es genau eine klare Primäraktion pro View?
2. **Layout & Weißraum** — Atmet das Layout oder ist es gequetscht? Restriktive
   Skalen eingehalten? Gruppierung über gemeinsame Regionen statt nur Nähe?
3. **Typografie** — Max. 2 Familien? Display mit tightem Tracking, Body
   15–25px / 120–145% / max. ~65ch?
4. **Farbe** — Bewusste Palette mit dominanter Farbe + Akzent? Farbe nie als
   einziges Signal?
5. **Anti-Slop-Check** — AI-Lila-Gradients? Generisches Glassmorphism?
   Drei identische Feature-Cards? Zentrierter Hero auf dunklem Mesh?
   Erfundene Daten oder Filler-Labels statt echter Information?
6. **Interaktion & Motion** — Feedback <400ms? Skeletons statt Spinner bei
   langen Loads? Motion unterbrechbar und zweckgebunden (keine Deko-Motion)?
7. **States** — Leere, ladende und Fehler-Zustände im selben visuellen System
   wie der Rest? Oder Lücken?
8. **Accessibility** — Kontrast ≥4.5:1, Fokus sichtbar, Touch-Targets ≥44px,
   prefers-reduced-motion, semantisches HTML?
9. **Konsistenz** — Gleiche Patterns für gleiche Probleme über alle Flächen?
   (Buttons, Formulare, Cards, Abstände, Radien.)

Bewerte jeden Befund mit Severity:
- **kritisch** — Nutzer versteht nicht, was zu tun ist / Kernfunktion leidet
- **hoch** — sichtbarer Qualitätsbruch, schwächt Vertrauen
- **mittel** — Inkonsistenz oder verpasste Differenzierung
- **niedrig** — Polish

Liefere: Tabelle mit Fläche | Kategorie | Befund | Severity | Skill-Referenz.
Danach STOPP — warte auf mein Go.

### Phase 3 — Plan (Output: Umsetzungsplan)

1. Sortiere die Befunde: Quick Wins (klein, große Wirkung) zuerst,
   dann strukturelle Verbesserungen.
2. Wo ein Umbau sinnvoller ist als Flicken (z. B. Hierarchie grundsätzlich
   falsch, kein konsistentes System): markiere es als **Rebuild-Kandidat**
   mit Begründung — kein stiller Komplettumbau.
3. Definiere pro Maßnahme: was sich ändert, welche Skill-Regel es umsetzt,
   was gleich bleibt (keine Funktionsänderung ohne Ansage).
4. Liefere den Plan als nummerierte Liste mit Aufwandseinschätzung
   (klein/mittel/groß) pro Punkt. Danach STOPP — warte auf Freigabe,
   welche Punkte umgesetzt werden.

### Phase 4 — Umsetzung (erst nach meiner Freigabe)

1. Setze nur die freigegebenen Punkte um, in der freigegebenen Reihenfolge.
2. Keine Funktionsänderung, kein Refactoring nebenbei, keine neuen
   Dependencies ohne Ansage.
3. Halte dich an die verbindlichen Design-Regeln aus docs/design-skills.md:
   eine visuelle Richtung pro Fläche, Token-Disziplin, Content-Disziplin
   (jeder String benennt echte Information).
4. Nach jedem größeren Eingriff: Selbst-Check gegen die Audit-Kategorien —
   ist der Befund wirklich behoben, ohne neue zu erzeugen?

### Abschluss

Liefere eine kurze Zusammenfassung: Was wurde geändert (Dateien),
welche Befunde sind behoben, welche bleiben bewusst offen und warum.
```

---

## Hinweise zur Verwendung

- **docs/design-skills.md** ist die kompakte Skill-Datei aus diesem Paket — sie muss für Codex erreichbar sein (im Repo oder in den Prompt kopiert).
- Die zwei Stopps sind bewusst drin: Nach dem Audit siehst du erst das ganze Bild, nach dem Plan entscheidest du, was wirklich umgebaut wird. Wenn du Codex lieber in einem Rutsch arbeiten lässt, die beiden STOPP-Zeilen streichen.
- Für einen reinen Rebuild ("bau die Seite neu, egal was da ist"): Phase 2–3 überspringen und direkt schreiben — aber dann geht das Audit verloren, das gerade die teuren Fehler findet.
