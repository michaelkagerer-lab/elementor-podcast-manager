# Instructions for agents working in this repository

## Required project guidance

For all work in this repository, read and apply
[the project-design skill](.agents/skills/project-design/SKILL.md) and the owner's
[Design-Learnings & Regeln](docs/design-learnings-und-regeln.md).
For every UI task also read [the compact design skill](docs/design-skills.md).
The additional [UI audit work order](docs/ui-audit-work-order.md) specifies
review checkpoints after its audit and plan phases when that order is invoked.
External skill links are references, not installed tools or verified claims.
Native WordPress/Elementor conventions and explicit user choices take priority
over generic typography or visual-anchor prescriptions.

Part 1 is the primary rule collection; Parts 2 and 3 supply rationale and
attribution. These rules guide product behavior as well as interfaces,
accessibility, copy and error recovery. Apply them in context; user instructions
and the user's chosen settings take precedence. Do not turn a focused fix into
an unrelated redesign.

## Current 1.4.0 work order

- Use `docs/FINDINGS-1.4.0.md` and `docs/findings-1.4.0.json` as the audit register.
  Do not mark a finding fixed and tested without reproduced failure and passing
  regression evidence. Record unverified or externally blocked work honestly.
- Before fixing a finding, add a reproducer that fails on the unfixed behavior.
  Run checks appropriate to the change; use `tests/README.md` for suite coverage.
- Test only on marked disposable sites. The fixture seeder deletes episodes.
  `EPM_ALLOW_TEST_SEED=1` is for authorized test runners only. Never point tests,
  import probes, uninstall probes or seeders at a real site.
- Preserve episode and podcast GUIDs, existing URLs, settings, imported episodes'
  local edits and manual widget settings. Avoid destructive migrations.
- Keep tests, downloads and CI jobs bounded. Stop stuck work, diagnose the cause
  and avoid repeated hosted runs when a local targeted check will resolve it.
- The current user authorized fixes, commits and merge preparation. A separate
  order is required to merge into `main`, release, deploy, perform a production
  import, switch production hosting or submit to a podcast directory.
