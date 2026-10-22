---
title: "AI agents"
description: "Let an AI agent export, import and manage copies for you: the abilities over MCP, what to ask, what the agent sees, and the safety built in."
---

Eizport is **agent-first**: everything the screens do is an [ability](../abilities/) of the WordPress Abilities API — a named action with a JSON Schema for its input and output, a permission check, and hints saying whether it only reads or deletes. That is exactly what an AI agent needs to act on a site safely, without screen scraping.

## Connect an agent

1. Install the [MCP Adapter](https://github.com/WordPress/mcp-adapter) on the site. It exposes abilities as **MCP tools**.
2. Connect your MCP client (an AI assistant, an IDE agent, a CLI agent) to the site as described in the adapter's docs, with a user who can export (and import, if the agent should import).
3. Ask in plain words. The agent lists the `eizport/*` tools, reads their schemas, and calls them.

On a local [WordPress Studio](https://developer.wordpress.com/studio/) site, Studio's own MCP server can run the same abilities through its `wp_cli` tool (`wp eval`, see [WP-CLI](../wp-cli/)).

## What to ask

| You ask | The agent calls |
| --- | --- |
| “What can I export from this site?” | `eizport/get-export-options` |
| “Which page is the homepage, and which ones are the shop and checkout?” | `eizport/get-items` (`roles` on each item) |
| “Export the homepage with its images and the basic settings as a .zip.” | `eizport/get-items`, then `eizport/export` with `types`, `options.groups: ["basics"]`, `zip: true` |
| “Export every published product in the Shoes category, for Playground, with WooCommerce.” | `eizport/get-export-options` (term ID), then `eizport/export` with `filters` and `blueprint.plugins` |
| “Which settings mention the newsletter?” | `eizport/get-options` with `search` |
| “List the copies and delete the ones older than a day.” | `eizport/get-copies`, then `eizport/delete-copies` |
| “Import the store-products copy here.” | `eizport/import` with `copy` |
| “Save this as my default export.” | `eizport/save-export-defaults` |

## What the agent sees

Each tool carries its **description**, its **input schema** (types, enums, defaults, required fields) and its **output schema**, and the MCP annotations:

- **read-only** — `get-export-options`, `get-items`, `get-options`, `get-copies`, `get-export-defaults`, `get-settings`: safe to call while exploring;
- **destructive** — `delete-copies`, `clear-export-defaults`: a well-behaved agent asks before calling them;
- **idempotent** — calling again changes nothing more.

Errors come back as codes an agent can act on: `eizport_invalid_type` (a post type that can't be exported), `eizport_invalid_options` (an option that is never exported), `eizport_invalid_blueprint` (a theme or plugin not installed), `eizport_export_unavailable` (one export per request: call again).

## Safety built in

- **WordPress's permissions:** the agent can only do what its user can — `export` for exports and copies, `import` to import, `manage_options` for settings.
- **No secrets leave:** site URLs, the admin email, keys and salts, users and roles, active plugins, transients and caches are never exported, whatever the agent asks — nor options, theme mods or widget fields named like secrets (passwords, tokens, API and license keys).
- **Copies are temporary and hidden:** a random 16-character token in each link, `noindex` and robots.txt, a folder locked against direct access, and deletion when they expire ([copies](../../settings/copies/)).
- **Imports merge**, they don't overwrite the site.

## Docs for agents

This documentation is written for agents too:

- [`/llms.txt`](https://eizport.github.io/llms.txt) — an index of every page, with descriptions, linking to Markdown versions;
- [`/llms-full.txt`](https://eizport.github.io/llms-full.txt) — the whole documentation in one file;
- every page as Markdown — the same URL ending in `.md` (see “View as Markdown” at the bottom of each page).

Point your agent at `llms.txt` and it knows the plugin before its first call.
