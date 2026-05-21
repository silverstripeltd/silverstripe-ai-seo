# Content Extraction

## Goal

Extract clean text content from a page to feed to the AI provider for metadata generation. This is the **input** side of the pipeline — separate from the metadata fields (what the AI generates) and metadata rendering (how it appears in HTML).

## Site content patterns

Most Silverstripe CMS sites follow one of two patterns:

- **SiteTree + Elemental:** Pages use `DNADesign\Elemental` content blocks. The page's `Content` DB field is typically empty or minimal. Actual content lives on `BaseElement` subclasses within an `ElementalArea` related to the page (`SiteTree` → `ElementalArea` → `BaseElement`).
- **SiteTree + Content field:** Pages store content directly in the `Content` HTML field on `SiteTree`. No Elemental.

Some sites may also have content in other related DataObjects (e.g. custom components, accordion items, related resources) that contribute to the rendered page but are not part of either pattern above.

## Existing Elemental search indexing methods

Elemental already solves the "extract text from blocks" problem for search indexing. We can reuse this:

- **`BaseElement::getContentForSearchIndex()`** — renders the element via `forTemplate()`, strips HTML tags, and returns plain text. Has an existing extension point `updateContentForSearchIndex` that projects can use to customise output for custom block types.
- **`ElementalPageExtension::getElementsForSearch()`** — iterates all elements on a page, calls `getContentForSearchIndex()` on each, concatenates the results. Respects the `search_indexable` config per block type (so blocks can opt out).

This handles the Elemental case well, including extensibility — custom block types that override `forTemplate()` or hook into `updateContentForSearchIndex` will work automatically.

## Approach

Use the existing Elemental search indexing methods where available, with a fallback for non-Elemental sites:

1. If the page has the `ElementalPageExtension`, call `getElementsForSearch()` to get text content from all blocks.
2. If the page does not have `ElementalPageExtension` (or it returns empty), fall back to reading the `Content` database field with its HTML retained so the AI can use structural cues (headings, lists, links, emphasis) when generating metadata.
3. Concatenate results with the page's `Title` for context.
4. Provide an extension hook (`updateExtractedContent` or similar) on the module's content extractor so project-level code can append additional content from custom sources.

In practice, most developers will overlook the extension hook — AI SEO is a background feature and custom content sources are an edge case. The module should work well out of the box for the two common patterns without requiring developer intervention.

## No phased implementation needed

The original plan had a phased approach (Content field first, Elemental later). Since `getElementsForSearch()` already exists and works, we can support both patterns from the start.

## Content passed to AI

The extracted content is concatenated into a single string and passed to the AI provider along with basic page metadata (title, URL slug) for context. When the Content field is used as the source, its HTML is retained so the AI has richer structural context. The Elemental search path already returns plain text via `getElementsForSearch()`. The AI provider uses this to generate all metadata fields defined in `specs/02_metadata-fields.md`.

## Content length

No truncation is applied. Modern AI models have large context windows (e.g. Gemini Flash: 1M tokens) and can handle very long page content. If the content exceeds the model's context window, the API will return an error which is handled as a provider exception (see `specs/04_ai-providers.md` error handling).

## Versioned reading mode

Content extraction must read from the **Draft** version of the page when it exists. If the draft record does not exist (e.g. a Live-only record), fall back to the Live version. This applies to CMS-triggered generation (modal Regenerate button), background job generation, and report stale detection.

The extraction service wraps the read in `Versioned::withVersionedMode()` and reads Draft first, then Live. Live is only used as the content source when Draft is missing, but both stages are inspected so we can detect unpublished changes.

### Draft changes notice

When the Draft content differs from Live (or when no Live record exists), the CMS modal must display a banner informing the editor:

> "This page has unpublished changes. AI SEO reflects the draft content and will go live when the page is published."

This banner is shown alongside the existing status/stale banners. The modified state should be derived from `isModifiedOnDraft()` **or** a detected difference between Draft and Live extracted content (so Elemental block changes are captured even if the page itself is not marked modified).

## Content hashing

The same extracted content string is used to compute the MD5 content hash for stale detection (see `specs/07_stale-metadata.md`). It is critical that the hash is computed on exactly the same content that is sent to the AI provider, so that content changes are reliably detected.
