# Metadata Fields

## Traditional SEO fields

SiteTree has a "Metadata" toggle section on the page edit form containing `MetaDescription` and `ExtraMeta` (Custom Meta Tags). The `<title>` tag is typically rendered directly in the page template (e.g. `$Title | $SiteConfig.Title`), not from a CMS field.

The AI module generates its own `MetaDescription` and Open Graph fields on the `GeneratedMetadata` DataObject. Rather than hiding the Metadata toggle section (which contains Custom Meta Tags that must be preserved), the module replaces the `MetaDescription` field in the toggle with a read-only field that displays the value from `GeneratedMetadata`. See `specs/09_cms-ux.md` for details. The `<title>` tag remains template-driven, and JSON-LD titles use `OGTitle` or the page title.

### Meta description

- Concise summary of the page for search result snippets
- Rendered as `<meta name="description">` and `og:description`
- Text, ~150 characters recommended by default (configurable via `AI_MODULE_META_DESCRIPTION_MAX`)
- DB field: `MetaDescription`
- Validation: if AI generates over the configured limit, store as-is and show a warning in the CMS modal. Prompt instructs AI to stay within limit.

### Open Graph title

- Title specifically for social media sharing (Facebook, LinkedIn, etc.). Often similar to the page title but can be tailored for social context.
- Rendered as `<meta property="og:title">`
- Varchar, ~70 characters recommended
- DB field: `OGTitle`

### Open Graph description

- Description specifically for social media sharing. Often the same as meta description but can be tailored for social context.
- Rendered as `<meta property="og:description">`
- Text, ~200 characters recommended
- DB field: `OGDescription`

## AI-oriented fields

These are fields that modern AI systems (ChatGPT, Gemini, Perplexity, Claude) consume when crawling and indexing pages. All are rendered as JSON-LD structured data unless noted otherwise.

### Structured summary (long form)

- A factual, plain-language summary of the page content (1-2 paragraphs). Written for machine consumption — clear and self-contained rather than marketing copy. This is the primary description AI crawlers use for context.
- Rendered as the `description` and `abstract` properties in JSON-LD schema
- Text, ~300-500 words
- DB field: `SummaryLong`

Note: A separate short-form summary field is not needed — the meta description (above) serves this purpose.

### Key entities

- Structured identification of people, organisations, and places mentioned in or related to the page content. Includes canonical identifiers (e.g. Wikipedia/Wikidata URLs) for disambiguation ("Apple" the company vs "apple" the fruit).
- Rendered as JSON-LD using `about` (primary subject) and `mentions` (referenced entities) properties, with `Person`, `Organization`, and `Place` schema types and `sameAs` links
- Stored as JSON (array of objects, each with `type`, `name`, and optional `sameAs` URL)
- `type` values constrained to schema.org types: `Person`, `Organization`, `Place`
- DB field: `KeyEntities`
- CMS display: read-only formatted view (not editable; regenerate to change)

Example value:
```json
[
  {"type": "Organization", "name": "Wellington City Council", "sameAs": "https://en.wikipedia.org/wiki/Wellington_City_Council"},
  {"type": "Place", "name": "Wellington", "sameAs": "https://en.wikipedia.org/wiki/Wellington"}
]
```

### Key topics / themes

- A list of topic labels or categories describing the page's subject matter (e.g. "urban planning", "climate policy", "public transport")
- Stored as JSON (array of strings)
- DB field: `KeyTopics`
- CMS display: read-only formatted view (not editable; regenerate to change)
- Note: Low-effort, supplementary value. AI crawlers read JSON-LD as text and use topics for context, but there is no evidence of special keyword-aware processing.

Example value:
```json
["building consent", "residential construction", "Wellington regulations"]
```

### Suggested FAQs

- Question-and-answer pairs derived from the page content, marked up as FAQ structured data. Pages with FAQ schema are significantly more likely to appear in AI-generated answers (e.g. Google AI Overviews). Google restricted FAQ rich results to government and health sites in 2023, but the structured data is still read and processed by all major AI systems.
- Rendered as JSON-LD using `FAQPage` schema with `Question` and `acceptedAnswer` types
- Stored as JSON (array of objects, each with `question` and `answer`)
- DB field: `SuggestedFAQs`
- CMS display: read-only formatted view (not editable; regenerate to change)

Example value:
```json
[
  {"question": "How long does a building consent take?", "answer": "Standard residential building consents are processed within 20 working days."},
  {"question": "What documents do I need?", "answer": "You need building plans, an engineering report, and a completed application form."}
]
```

### JSON-LD schema markup

- A complete JSON-LD block combining the relevant schema.org types for the page. This is the core structured data output — the other AI-oriented fields feed into it. Uses `@graph` to combine multiple schema types (e.g. `WebPage`, `Article`, `BreadcrumbList`, `Organization`).
- Rendered as `<script type="application/ld+json">` in the page `<head>`
- **Not stored in the database** — dynamically assembled from the other fields at render time. This keeps it always in sync and avoids drift if individual fields are edited.
- CMS display: read-only field in modal showing the assembled JSON-LD for preview purposes
- DB field: None (computed)

## Additional fields on GeneratedMetadata

These are internal/tracking fields, not AI-generated content:

- `ContentHash` (Varchar) — MD5 hash of extracted content at generation time. Used for stale detection. See `specs/07_stale-metadata.md`.
- `ReviewedAt` (DBDatetime, nullable) — set when editor submits via modal, reset to null when metadata is regenerated. Null = needs review. See `specs/09_cms-ux.md`.
- `GeneratedAt` (DBDatetime) — when metadata was last generated by AI.
- `GenerationNote` (Varchar, nullable) — internal note if generation was skipped (e.g. "Insufficient content"). Not rendered.

## Dropped fields

The following fields from the original requirements were evaluated and dropped:

- **Content intent** (informational / transactional / navigational) — no standard exists for declaring intent in metadata. AI systems infer intent from content. Not worth building.
- **Semantic tags for vector/embedding use** — no web standard exists. External AI systems compute their own embeddings from page text. Only useful for internal RAG pipelines, which is out of scope.
- **Canonical description optimised for LLM ingestion** — this is effectively the same as writing a good meta description and long-form summary. A separate field adds no value. Site-level `llms.txt` generation (see `specs/10_metadata-rendering.md`) covers the LLM-specific angle.
