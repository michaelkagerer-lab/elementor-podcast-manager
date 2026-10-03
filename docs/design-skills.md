# Design-Skills für Codex (kompakt — direkt in den Chat pasten)

Lege diese Datei ins Repo (z. B. `docs/design-skills.md`) oder paste sie in den Task. Bei jedem UI-Task zuerst lesen.

## Die 7 wichtigsten Skills

1. **frontend-design** (https://github.com/Ilm-Alan/frontend-design) — 8 Ästhetik-Anker (Swiss, Industrial, Brutalist, Aurora, Chaotic, Retro-Futurism, Organic, Lo-Fi), jeder mit festen CSS-Tokens. Einen Anker wählen, Tokens exakt einhalten, kein Hybrid-Stil.
2. **design-taste-frontend** (https://github.com/drastrong/design-taste-frontend) — Anti-Slop: Brief-Inferenz zuerst, keine AI-Lila-Gradients, kein zentrierter Hero auf dunklem Mesh, keine drei identischen Feature-Cards.
3. **frontend-design-audit** (https://github.com/mistyhx/frontend-design-audit) — 15 Usability-Prinzipien, Severity 0–4, konkrete Code-Fixes. Zum Prüfen des eigenen Outputs.
4. **apple-design-skill** (https://github.com/dickwu/apple-design-skill) — Apple HIG als Review-Referenz (123 Seiten), plus Design-Craft-Linse gegen Template-Look.
5. **taste-skill** (https://github.com/senlindesign/taste-skill) — Design-DNA einer Website extrahieren (echte Tokens: px/hex), statt "clean and modern".
6. **ai-experience-design** (https://github.com/kevingutowski/skills) — KI-UX: Confidence als Aktionen, Erklärungen mit Fakten, keine Confirmation Fatigue.
7. **opencode-power-pack** (https://github.com/waybarrios/opencode-power-pack) — 54 Workflows inkl. Codex-Plugin (Reviews, Audits).

## Verbindliche Design-Regeln

**Vor dem Coden:** Kontext in einem Satz → eine visuelle Richtung wählen und durchhalten → ein einprägsames Signature-Detail definieren.

**Verboten:** AI-Lila-Gradients, zentrierter Hero auf dunklem Mesh, drei identische Feature-Cards, generelles Glassmorphism, erfundene Daten, Filler-Labels, Unicode-Glyphen als Icons, Standard-Aktionen mit "kreativer" Copy.

**Hierarchie & Layout:** Stärkster Kontrast trägt die Hierarchie (Größe + Gewicht + Farbe). Großzügiger Weißraum, restriktive Skalen. Gruppierung über gemeinsame Regionen/Verbindung, nicht nur Nähe. Erster Viewport beantwortet: was / für wen / was tun.

**Typografie:** Display groß, tightes Tracking. Body 15–25px, 120–145% Zeilenhöhe, max. ~65 Zeichen. Max. 2 Font-Familien.

**Interaktion:** Feedback <400ms (Press ~100ms). Skeletons statt Spinner bei Loads >1s. Motion unterbrechbar, nichts teleportiert. Touch-Targets ≥44×44px, max. 7 Navigationspunkte.

**Accessibility:** Kontrast ≥4.5:1, Fokus sichtbar, Farbe nie als einziges Signal, prefers-reduced-motion, semantisches HTML zuerst.

**Vor dem Shippen:** Passt jedes Token zur gewählten Richtung? Benennt jeder String echte Information? Leere States, Fehler-States und Loading-States im selben visuellen System?
