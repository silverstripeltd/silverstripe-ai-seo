# AI Module for Silverstripe CMS

This current project is to build a Silverstripe CMS "ai-metadata" in `vendor/silverstripeltd/ai-metadata`. No other works is being performed.

Do not modify any files outside of the `vendor/silverstripeltd/ai-metadata` directory unless explicitly instructed to do so.

**Silverstripe CMS 6** module that automatically creates metadata on page content for machine consumption.

When implementing phases from a plan, implement all phases in one go. Do not stop to ask for review or confirmation between phases. Act as an autonomous developer — use your best judgement, do not ask for clarifications.

Never attempt to use MCP (Model Context Protocol) — it is disabled at an organisation level.

## Hard Constraints

- **NEVER view or edit bundled/compiled files** — no `/dist/`, `bundle.js`, `vendor.js`, or `*.min.js`. These will blow out your context window.
- **Only change code directly related to the task** — no unrelated cleanup, lint fixes, or reformatting. Note anything worth fixing in `z-learnings.md` instead.
- **PHP typing/docblocks** — docblock every class/interface/method; type-hint all params/returns; only add `@param/@return` when clarifying array contents.

## Important: consult human context before updating specs

Before updating any spec files in `specs/`, always read `docs/03_human-context.md` first. It contains important background on support layers, customer considerations, and design rationale that should inform spec decisions. When the human provides new background context or rationale during spec discussions, actively add it to `docs/03_human-context.md`.

## Directory structure

- `client/src/` - React modal + Entwine adapter sources
- `client/dist/` - Webpack build output (JS/CSS bundles) — do not read or edit
- `src/` - PHP classes (controllers, services, extensions, etc.)
- `_config/` - YAML configuration (extensions, routes, requirements)
- `docs/` - Scope, original requirements, and human context. Inform design but are not used directly for implementation.
- `specs/` - Technical specs used as basis for implementation. See `specs/00_overview.md` for the full spec index.
- `tests/php/` - PHPUnit tests

Files in `docs/` and `specs/` are prefixed with `01_`, `02_`, etc. In `specs/`, numbering reflects recommended implementation order. When adding new spec files, number them according to where they sit in the implementation sequence.

## Other files

The file `ROOT_CLAUDE.md` is symlinked to the project root as `CLAUDE.md`. It should not be read directly as it is already loaded as pre-prompt.

## Running Commands

All commands run inside Docker via SSH. Never call `phpunit`, `phpcs`, `phpcbf`, `yarn`, `npm`, or `npx` directly.

**Resource throttling:** Always prefix the actual command with `nice -n 19 ionice -c 3 taskset -c 0` to keep CPU/IO usage low on the host. For yarn/node commands also prepend `NODE_OPTIONS=--max-old-space-size=512` to cap memory. The examples below already include these prefixes.

### PHP unit tests

`ssh webserver "cd /var/www && rm -rf /tmp/pu-cache && mkdir -p /tmp/pu-cache && SS_TEMP_PATH=/tmp/pu-cache nice -n 19 ionice -c 3 taskset -c 0 vendor/bin/phpunit vendor/silverstripeltd/ai-metadata/tests/ --fail-on-warning [--filter={test-name}]"`

### PHP linting (phpcs/phpcbf)

`ssh webserver "cd /var/www/vendor/silverstripeltd/ai-metadata && nice -n 19 ionice -c 3 taskset -c 0 ../../bin/{binary} --ignore=*/thirdparty/*,*/node_modules/* --extensions=php ."`

Replace `{binary}` with `phpcs` or `phpcbf`.

### yarn (test/lint/build)

`ssh webserver "cd /var/www/vendor/silverstripeltd/ai-metadata && NODE_OPTIONS=--max-old-space-size=512 nice -n 19 ionice -c 3 taskset -c 0 yarn test"`

You may also run `yarn lint`. Nothing else. Only call commands already defined in `package.json` scripts. Do not install packages or modify `package.json`. **Do not run `yarn build` here** — see "Final step" below.

**JS dependency prerequisite:** This module depends on `vendor/silverstripe/admin` for shared JS tooling. Before running `yarn build` or `yarn test`, first run `yarn install` in admin:

`ssh webserver "cd /var/www/vendor/silverstripe/admin && NODE_OPTIONS=--max-old-space-size=512 nice -n 19 ionice -c 3 taskset -c 0 yarn install"`

Without this, `yarn build`/`yarn test` in the ai-metadata will fail with missing dependency errors.

### Final step — JS build

If any `.js` or `.jsx` files were changed during the task, run `yarn build` as the **very last code-related step**, after all implementation, tests, and linting pass. Do not run it mid-implementation to check progress.

`ssh webserver "cd /var/www/vendor/silverstripeltd/ai-metadata/client && NODE_OPTIONS=--max-old-space-size=512 nice -n 19 ionice -c 3 taskset -c 0 yarn install && NODE_OPTIONS=--max-old-space-size=512 nice -n 19 ionice -c 3 taskset -c 0 yarn build"`

## PHP Testing — SapphireTest

Do not mock dependencies like a traditional unit test. Silverstripe tests use `SapphireTest` which provides a temporary database — use fixtures or programmatically create data within tests.

Use `#[DataProvider('provideFoo')]` attribute syntax (PHP 8), **not** the legacy `@dataProvider` annotation — the annotation does not work in this project. Place the provider method directly above the test method it supplies.

Do not use the description argument in assertions (e.g. `$this->assertTrue(true, 'message')` — just `$this->assertTrue(true)`).

## JS Testing

Flat `test()` blocks only — no `describe()` nesting. Use RTL queries by accessibility role/text (`getByRole`, `getByText`), not `getByTestId`.

## Key architectural decisions

- Unversioned `AiMetadata` DataObject stored via sideways XHR saves.
- Polymorphic Parent relation to support non-SiteTree types later.
- Single AI call returns a JSON object for all metadata fields.
- MD5 content hashing used for on-demand stale detection.
- React modal mounted via Entwine adapter; `ReviewedAt` gates approval.
- JSON-LD assembled at render time rather than stored.

## Environment variables

- `AI_MODULE_PROVIDER` (default `gemini`)
- `AI_MODULE_API_KEY`
- `AI_MODULE_MODEL`
- `AI_MODULE_THINKING_LEVEL` (default `low`)
- `AI_MODULE_TEMPERATURE` (default `1.0`)
- `AI_MODULE_MAX_TOKENS` (default `2000`)
- `AI_MODULE_REQUEST_TIMEOUT` (default `15`)
- `AI_MODULE_RATE_LIMIT_DELAY` (default `6`)

## Learnings

Read `/app/z-learnings.md` if it exists. Append non-obvious discoveries — gotchas, workarounds, patterns, constraints. Prefix each entry with a category tag (e.g. `[testing]`, `[config]`, `[api]`). Keep entries to 1-2 sentences. Create the file with a `# Learnings` heading if it doesn't exist. Never overwrite existing entries; always append.

## z- file outputs

If you are asked to create any `z-*.md` files, always look for them and put them in the `/app` dir, not `vendor/silverstripeltd/ai-metadata`.
