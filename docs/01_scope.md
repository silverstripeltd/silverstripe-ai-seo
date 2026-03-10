# Scope

## In scope

### Metadata generation

- Traditional SEO metadata generation (see `specs/02_metadata-fields.md`)
- AI-oriented metadata generation (see `specs/02_metadata-fields.md`)
- Content extraction (see `specs/03_content-extraction.md`)
- Generation behaviour (see `specs/06_generation-behaviour.md`)
- Prompt management — hardcoded in module, extensible/overridable via code at project level (see `specs/05_prompts.md`)

### Data architecture

- See `specs/01_data-architecture.md`
- Versioned `GeneratedMetadata` DataObject with polymorphic relationship

### Dirty state / versioning / publishing behaviour

- See `specs/12_dirty-versioned-state.md`
- Sideways XHR saves, independent from page form — largely a non-issue

### Stale metadata detection

- See `specs/07_stale-metadata.md`
- MD5 content hashing, on-demand checking only

### AI providers

- AI provider abstraction (see `specs/04_ai-providers.md`)
- Gemini as primary provider, abstract base for others
- Graceful failure when no API key is configured
- Logging of API errors (e.g. invalid key) in all environments
- Local dev server option for free development testing

### CMS interface (per-page)

- Modal dialog for viewing and editing generated metadata (see `specs/09_cms-ux.md`)
- React frontend with Entwine-to-React adapter
- Manual override of editable fields (text fields editable, JSON fields read-only)
- Per-page regeneration trigger (button in CMS)
- Generated content requires user review before being marked as accepted (`ReviewedAt`)
- Replaces `MetaDescription` in SiteTree's Metadata toggle with read-only field showing `GeneratedMetadata` value
- Toast notifications for async feedback (consistent with Elemental patterns)

### Background job

- Async job to generate metadata for pages that don't have it yet, and pages with stale metadata (see `specs/11_background-job.md`)
- Does not auto-publish
- Still requires human review and approval
- Rate limiting / throttle between API requests (default 1000ms, overridable via `AI_MODULE_RATE_LIMIT_DELAY` environment variable)
- Not enabled by default (manageable via queued jobs CMS interface by admin users)

### Reporting

- CMS report showing pages that need attention (see `specs/13_cms-report.md`):
  - Pages without generated metadata (Missing)
  - Pages with stale metadata (Stale)
  - Pages with AI-generated metadata pending review (Unreviewed)

### API endpoints

- Server-side XHR endpoints for the CMS modal (see `specs/08_api-endpoints.md`)
- Polymorphic-aware URLs supporting future DataObject types

### Metadata rendering

- Meta tags, JSON-LD structured data, llms.txt (see `specs/10_metadata-rendering.md`)
- `getMetaDescription()` override on SiteTree for transparent MetaTags() integration

---

## Nice to have (phase 2)

### Configuration

- Configurable token/usage limits (probably better handled at the API provider level e.g. spend limits in Gemini's UI)
- Per-field tracking of AI-generated vs manually edited

### Developer experience

- Debug logging of prompts and outputs (dev mode only)

### Extensibility

- Generating metadata for non-SiteTree DataObjects (polymorphic architecture already supports this)

--- 

## Out of scope

### Content and publishing

- Auto-publishing of generated metadata without user review
- Content generation (body copy, page content) — this is metadata only

### Performance and infrastructure

- Real-time generation during page render (frontend performance must not be affected)
- Custom AI model training or fine-tuning
- Cost tracking or billing dashboards for API usage

### Integrations

- Integration with existing SEO modules (e.g. silverstripe/meta-tags)
- Integration with external SEO audit tools
- Subsites support

### Features

- Automatic generation on page save
- Version history of generated metadata
- Admin-editable prompts via SiteSettings
- Controlling the `<title>` tag (template-driven, not CMS-managed)
