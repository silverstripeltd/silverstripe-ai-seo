# Docs Overview

These documents provide context, scope, and rationale for the AI metadata module. They inform design but are not used directly for implementation — see `specs/` for implementation specs.

## Files

| # | File | Purpose |
|---|------|---------|
| 00 | This file | Index of docs |
| 01 | `01_scope.md` | What's in scope, out of scope, and phase 2. The definitive list of what the module does and doesn't do. |
| 02 | `02_original-requirements.md` | The product owner's initial requirements document. Loosely defined but captures the original intent. Reference only — do not modify. |
| 03 | `03_human-context.md` | Business context that informs design decisions: support layers, customer considerations, why env vars over YAML, why prompts aren't admin-editable, etc. Updated as new context emerges. |
| 04 | `04_decisions.md` | Record of key architecture and design decisions with rationale. Consult this when specs reference "why" something was chosen. |

## How to use these docs

- **Before updating specs:** Always read `03_human-context.md` first (as noted in `CLAUDE.md`).
- **When new context emerges:** Add it to `03_human-context.md`.
- **When a design decision is made:** Record it in `04_decisions.md`.
- **To understand what to build:** Read `01_scope.md`, then the specs in `specs/`.
- **To understand original intent:** Read `02_original-requirements.md` (but treat `01_scope.md` as the refined version).
