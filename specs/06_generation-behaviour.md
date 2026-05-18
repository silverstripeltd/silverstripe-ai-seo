# Generation Behaviour

## Generation pipeline

1. **Extract content** — use the content extraction pipeline (`specs/03_content-extraction.md`) to get plain text from the page. Content is read from the Draft version when available, falling back to Live if the draft record does not exist (see `specs/03_content-extraction.md` — Versioned reading mode)
2. **Check content threshold** — if extracted content is an empty string, skip generation and store note (see below)
3. **Call AI provider** — single API call with extracted content, page title, and page URL. Returns all metadata fields as JSON. See `specs/04_ai-providers.md`.
4. **Store results** — populate the `GeneratedSeo` record with generated field values, set `ContentHash` (MD5 of extracted content), set `GeneratedAt` timestamp, reset `ReviewedAt` to null
5. **Return results** — for CMS regeneration, return the field values to populate the modal. For background job, move to next page.

## Minimal content handling

- Skip generation when extracted content is an **empty string** only
- Store an internal note on the `GeneratedSeo` record (`GenerationNote` field) indicating why generation was skipped (e.g. "Insufficient content")
- This note is not rendered to HTML metadata output
- Pages with a generation note show in the CMS report as needing attention

## Permissions

- Permissions for generating/editing metadata are deferred to the parent DataObject (e.g. page's `canEdit()`)
- See `specs/13_cms-report.md` for report permissions

## Error handling

- **CMS regeneration:** Toast notification for generation failures. Modal fields are not updated on failure. In development environments, the actual provider error message is shown. In production, a generic message is shown and the detail is logged server-side.
- **Background job:** Log error, skip page, continue to next. See `specs/11_background-job.md`.

## Concurrency

- If two editors regenerate the same page simultaneously, last write wins (acceptable since both are generating from the same content)
- If the background job is processing a page while an editor regenerates manually, last write wins (same rationale)
- No locking mechanism needed — the cost of a redundant generation is just one extra API call

## Prompt specification

See `specs/05_prompts.md` for the prompt template and output format.
