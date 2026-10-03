---
name: project-design
description: Apply the owner's Design-Learnings & Regeln throughout this project, including product behavior, interfaces, copy, accessibility, error recovery, and design review.
---

# Project design guidance

The owner supplied [Design-Learnings & Regeln](../../../docs/design-learnings-und-regeln.md).
The repository-root path is `docs/design-learnings-und-regeln.md`.
Read the full rule collection in Part 1 before making design decisions; consult
Parts 2 and 3 for the attributed rationale and sources. Preserve the reference's
verification labels: supplied summaries are not independent source verification.

Apply this guidance throughout the project. Backend behavior also shapes the
experience: truthful progress, safe defaults, data preservation, bounded work,
recoverable failures and accurate final states matter as much as presentation.
Apply the relevant rules to each task without introducing unrelated redesigns.
User instructions take precedence. The reference explicitly treats rules as
context-dependent; explain intentional exceptions through the user goal,
platform constraints and evidence.

Also read [the compact design skill](../../../docs/design-skills.md) for UI tasks.
Apply its hierarchy, content and interaction rules in the existing native
WordPress/Elementor design direction; preserve deliberate frontend presets.

## Working method

1. State the user's task, audience, key message and next useful action before
   changing a flow. Use the existing product and real content as the brief.
2. Preserve WordPress admin and Elementor conventions, the existing design
   tokens and explicitly chosen podcast/widget settings. Use native controls
   and established components. Fit changes to their context; do not impose a
   new font, palette or visual system across existing presets.
3. Establish one primary action and a clear information hierarchy. Group
   related content through spacing and alignment; remove visual noise and
   unnecessary copy. Keep decoration subordinate to controls and errors.
4. Include the applicable default, focus, hover, pressed, disabled, loading,
   empty, error, success and overflow states. Give immediate feedback, name
   ongoing work, show truthful progress and provide a useful recovery action.
5. Build accessibility into the change: semantic HTML first, keyboard access,
   visible unobscured focus, appropriate live regions, text alongside status
   colors, adequate contrast and reduced-motion support. Aim for 44×44 px
   touch targets; meet WCAG 2.2 AA sizing or its applicable exceptions.
6. Use complete translatable sentences and plurals, locale-aware numbers,
   actionable error copy, realistic episode content and long German labels.
   Check narrow containers and mobile widths, not just a desktop default.
7. Review the first implementation and refine it. For visible changes, inspect
   the rendered result and relevant interactions with real content. Verify
   keyboard and failure paths as appropriate. Use targeted checks and existing
   suites; do not add tests that merely duplicate CSS or prose.
8. Explain the concrete improvement and its evidence. Report remaining limits
   honestly. Never invent testimonials, user research, conversion results or
   source verification, and never describe unverified work as perfect.

## Visual and interaction constraints

Use restrained color, legible typography, purposeful spacing and coherent
states. Avoid the generic AI defaults called out in Part 1, section 9: purple
gradients, dark mesh heroes, arbitrary card grids, blanket glass effects,
ornamental motion and indiscriminate font changes. Existing deliberate styles
are evaluated in context; do not overwrite a user's selected preset merely
because a general rule recommends another default.

Animation must communicate feedback, continuity or structure and remain
interruptible where useful. Keep frequent interactions fast. Prefer direct,
familiar controls for small edits. Ask for confirmation only where the user's
authorization and the consequences require it; do not add routine approval
fatigue. The current work order still requires a separate order for merging,
releasing, deploying or changing a production hosting/feed setup.
