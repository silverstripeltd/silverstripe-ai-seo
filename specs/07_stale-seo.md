# Stale Metadata Detection

## Approach: content hashing

Stale metadata is detected by comparing a hash of the current page content against the hash stored when metadata was last generated.

### How it works

1. **At generation time:** After extracting page content (via the content extraction pipeline in `specs/03_content-extraction.md`), compute an MD5 hash of the extracted text. Store this hash as `ContentHash` on the `GeneratedSeo` record alongside the generated metadata.

2. **At check time:** Re-extract the page content using the same extraction pipeline, compute the MD5 hash, and compare to the stored `ContentHash`. If they differ, metadata is stale.

3. **Important:** The hash must be computed on the same extracted content string that is sent to the AI provider. This ensures the hash reflects exactly what the AI saw, and changes to any content source (Content field, Elemental blocks, custom extensions) are detected.

### When staleness is checked

Staleness is checked **on-demand only** — NOT on every page save:

- **When the CMS modal is opened** — the stale indicator is shown if content has changed
- **When the CMS report runs** — to populate the "Stale" status column
- **When the background job runs** — to determine which pages need regeneration

This avoids adding overhead to every page save operation. Most editors may not use the AI SEO feature, so save-time overhead would be unwelcome feature bloat.

### Storage

- `ContentHash` field (Varchar) on the `GeneratedSeo` DataObject
- MD5 produces a 32-character hex string
- Null `ContentHash` means metadata has never been generated

### Querying for stale pages

For the CMS report and background job, staleness requires re-extracting content for each page and comparing hashes. This is a heavier operation than a simple DB query, so:

- The background job iterates pages and checks staleness per-page as it processes
- The CMS report does the same (acceptable performance for a report that runs on-demand)
- There is no "pre-computed" stale flag that stays up to date automatically

### What counts as a content change

Because the hash is computed on the full extracted content (which includes Elemental blocks, Content field, and anything added via the `updateExtractedContent` extension hook), it naturally catches:

- Changes to the page's `Content` field
- Changes to Elemental content blocks (added, removed, edited)
- Changes to custom content sources that hook into the extraction pipeline

It does **not** catch changes to content sources that aren't part of the extraction pipeline (e.g. related DataObjects that don't hook into `updateExtractedContent`). This is "good enough" for the common cases.
