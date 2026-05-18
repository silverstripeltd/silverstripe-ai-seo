# Metadata Rendering

How the metadata fields defined in `specs/02_metadata-fields.md` are rendered as HTML output on the frontend. This is the **output** side of the pipeline.

## Rendering mechanisms

### Meta tags (in `<head>`)

Standard HTML meta tags, rendered via Silverstripe's `MetaTags()` method or equivalent extension point.

| Field | HTML output |
|-------|-------------|
| Meta description | `<meta name="description" content="...">` |
| OG title | `<meta property="og:title" content="...">` |
| OG description | `<meta property="og:description" content="...">` |

**Note on meta description:** SiteTree's built-in `MetaTags()` renders `<meta name="description">` from its own `MetaDescription` field. The module's extension overrides the `getMetaDescription()` accessor on SiteTree to return the `GeneratedSeo` value when it exists, falling back to the original SiteTree value. This ensures `MetaTags()` picks up the AI-generated description without requiring template changes.

**Note on Versioned reading:** All frontend rendering (`getMetaDescription()`, `updateMetaTags()`, `contentcontrollerInit()`, `LlmsTxtController`) reads the `GeneratedSeo` record from the **Live** stage. Only reviewed and published metadata appears on the frontend. The extension's `getAiSeo()` method is stage-aware — on the frontend (Live stage), it reads from the Live table; in the CMS (Draft stage), it reads from Draft.

**Note on `<title>` tag:** The `<title>` tag is typically rendered directly in the page template (e.g. `$Title | $SiteConfig.Title`), not via `MetaTags()`. The module does not store a `MetaTitle` field; `og:title` uses `OGTitle` and JSON-LD titles use `OGTitle` or the page title.

### JSON-LD (in `<head>`)

A single `<script type="application/ld+json">` block containing structured data for the page. This is the primary output for AI-oriented fields.

The JSON-LD block is **dynamically assembled** from the individual metadata fields (not stored in the database). It combines multiple schema.org types using `@graph`:

- **`WebPage`** — base type for every page. Includes `name`, `description`, `url`, `datePublished`, `dateModified`, `about`, `mentions`.
- **`Article`** (or `BlogPosting`, `NewsArticle`) — for content pages. Includes `headline`, `description`, `author`, `publisher`, `datePublished`, `dateModified`.
- **`FAQPage`** — when suggested FAQs are present. Includes `mainEntity` array of `Question`/`Answer` pairs.
- **`BreadcrumbList`** — navigation context derived from the site tree hierarchy.
- **`Organization`** — the site owner, referenced by `author`/`publisher`.

Where the data comes from:

| JSON-LD property | Source field |
|------------------|-------------|
| `WebPage.description` / `Article.description` | `SummaryLong` |
| `Article.abstract` | `SummaryLong` |
| `WebPage.about` / `WebPage.mentions` | `KeyEntities` (JSON) |
| `FAQPage.mainEntity` | `SuggestedFAQs` (JSON) |
| `WebPage.name` / `Article.headline` | `OGTitle` (falls back to page title) |
| Dates, URLs, breadcrumbs | Derived from `SiteTree` fields |

### Example output

```html
<head>
  <title>Residential Building Consent Guide | Wellington City Council</title>
  <meta name="description" content="Wellington City Council's guide to residential building consent requirements, timelines, and fees.">
  <meta property="og:title" content="Residential Building Consent Guide">
  <meta property="og:description" content="Guide to building consent requirements, timelines, and fees for Wellington homeowners.">
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@graph": [
      {
        "@type": "WebPage",
        "url": "https://example.com/services/building-consents",
        "name": "Residential Building Consent Guide",
        "description": "A comprehensive guide covering residential building consent in Wellington, including required documentation, processing timelines of 20 working days, associated fees starting from $2,500, and step-by-step application instructions for homeowners and builders.",
        "dateModified": "2026-02-20",
        "about": [
          {
            "@type": "Organization",
            "name": "Wellington City Council",
            "sameAs": "https://en.wikipedia.org/wiki/Wellington_City_Council"
          }
        ],
        "mentions": [
          {
            "@type": "Place",
            "name": "Wellington",
            "sameAs": "https://en.wikipedia.org/wiki/Wellington"
          }
        ]
      },
      {
        "@type": "Article",
        "headline": "Residential Building Consent Guide",
        "description": "A comprehensive guide covering residential building consent in Wellington...",
        "author": { "@type": "Organization", "name": "Wellington City Council" },
        "publisher": { "@type": "Organization", "name": "Wellington City Council" },
        "dateModified": "2026-02-20"
      },
      {
        "@type": "FAQPage",
        "mainEntity": [
          {
            "@type": "Question",
            "name": "How long does a building consent take?",
            "acceptedAnswer": {
              "@type": "Answer",
              "text": "Standard residential building consents are processed within 20 working days."
            }
          }
        ]
      }
    ]
  }
  </script>
</head>
```

## llms.txt (site-level)

A site-level Markdown file at `/llms.txt` providing a structured overview of the site for LLM crawlers. This is an emerging standard (llmstxt.org) with significant adoption (~844,000 sites including Anthropic, Cloudflare, Stripe) though no major AI platform has officially confirmed they read it.

- Generated automatically from published pages with metadata
- Served as a plain text response at the `/llms.txt` route
- Content sourced from:
  - **Site name:** `SiteConfig.Title`
  - **Short description:** `SiteConfig.Tagline`
  - **Page list:** published pages that have `GeneratedSeo` with `SummaryLong` populated — each entry includes page title, URL, and summary
- Generated dynamically on request (no build task or background regeneration required)
- Low cost to implement, reasonable bet on future consumption

Example output:
```markdown
# Wellington City Council

> Wellington City Council's official website — services, rates, consents, and community information.

## Pages

- [Residential Building Consent Guide](https://example.com/services/building-consents): A comprehensive guide covering residential building consent in Wellington, including required documentation, processing timelines, and fees.
- [Rates and Payments](https://example.com/rates): Information about property rates, payment options, and due dates for Wellington ratepayers.
- [Dog Registration](https://example.com/services/dog-registration): How to register your dog in Wellington, fees, and responsible dog ownership requirements.
```

## Existing JSON-LD

Some sites may already have JSON-LD from other sources (e.g. theme templates, other modules). This module's JSON-LD output coexists alongside any existing JSON-LD — multiple `<script type="application/ld+json">` blocks are valid and standard practice. No deduplication is attempted.

## No metadata scenario

If a page has no generated metadata (module installed but AI not yet run, or no API key configured), fall back to default Silverstripe behaviour — the existing `MetaTags()` output is not modified. No empty JSON-LD blocks are rendered.
