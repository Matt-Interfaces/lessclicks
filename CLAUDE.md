# Lessclicks project rules

## Ponytail

This project follows the global `ponytail` skill at full level for every coding task. Load it before planning or editing. The skill holds the rules; this file only adds project-specific commands and constraints.

## Graphify

The project has a knowledge graph at `graphify-out/`.
- If `graphify-out/graph.json` exists, run `graphify query "<question>"` before the first grep, search or source-file read for a codebase question. Read raw files only for lines the graph or an earlier read already located.
- Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts.
- After structural changes, run `graphify update .` (AST-only, no API cost).

## Commands

- PHPCS: `composer run phpcs` (auto-fix: `composer run phpcbf`)
- Svelte build: `cd app && npm run build`
- Release ZIP: `CI=true bash build-plugin.sh`
- German catalog: `msgfmt --check -o languages/lessclicks-analytics-for-matomo-de_DE.mo languages/lessclicks-analytics-for-matomo-de_DE.po`

## i18n

English is the source language for the settings UI and server messages. Every visible string in `LessclicksSettings.svelte` is bound to `i18n.key || 'English fallback'`; German comes from `languages/lessclicks-analytics-for-matomo-de_DE.po`. Do not add German fallbacks to the settings component.
