# Elementor Podcast Manager design context

The canonical product design is in `../DESIGN.md` and the project instructions. This is a PHP/WordPress/jQuery plugin, not a React app.

Use system sans typography and a restrained neutral studio palette on the dashboard, retaining native WordPress navigation and post edit/search routes. The user explicitly requested an Apple-like redesign. The public player inherits existing theme and Elementor choices; preserve all `--epm-*` tokens and saved widget styles.

The editorial workspace puts recent editable drafts and scheduled releases ahead of infrastructure. Use explicit labels, bounded queries, timezone-aware dates and reliable edit links. Removal recovery restores only the removed row and preserves other edits.

References inspected: Mobbin Kajabi podcast list and 21st Table with Filters (component 22162). Adapt information structure, without importing React/TanStack or another visual system. Details and limitations are recorded in `docs/PRODUCT-EXPERIENCE-REVIEW.md` and `.21st/design.json`.
