# Architecture and Design Decisions

Record of key decisions made during spec development. Consult this when specs reference "why" something was chosen.

## Data architecture

- **GeneratedMetadata is Versioned** (Draft/Live). Sideways XHR saves write to Draft. Metadata is published to Live when the parent page is published, via a JS Entwine hook (following the Elemental pattern). `ReviewedAt` acts as a publish-readiness gate: unreviewed metadata stays in Draft even when the page is published. Rationale: matches editor mental model that "publish page = publish everything on the page", while preventing unverified AI content from going live.
- **Polymorphic relationship.** GeneratedMetadata has_one Parent (ParentID + ParentClass). This supports future extension to other DataObject types without migration. The page does not have a has_one to metadata.
- **Sideways XHR saves.** Metadata submission is completely independent from the page edit form. No interaction with page save/publish. Follows Elemental inline editable block pattern.

## Generation approach

- **Single API call for all fields.** One prompt generates all metadata fields as a JSON object. Cheaper and simpler than per-field calls.
- **No content truncation.** Full extracted content is sent to the AI provider. Modern models have large enough context windows. If content exceeds limits, the API error is handled as a provider exception.
- **Empty string threshold for skipping.** Generation is only skipped when extracted content is an empty string (not a word count threshold).

## Stale detection

- **MD5 content hashing.** Hash of extracted content stored at generation time, compared on-demand. No security requirements for the hash — MD5 is fast and sufficient for change detection.
- **On-demand checking only.** Hash comparison happens when the modal opens, report runs, or background job runs. NOT on every page save. This avoids performance overhead for editors who don't use the AI metadata feature.

## CMS UX

- **React with Entwine adapter.** Modal uses React (via Entwine-to-React adapter pattern). FormSchema mechanism delivers PHP-defined fields as JSON to React.
- **JSON fields are read-only.** KeyEntities, KeyTopics, SuggestedFAQs displayed as formatted read-only views. Editors regenerate to change them. Avoids complex structured sub-forms.
- **JsonLdSchema is not stored.** Dynamically assembled from individual fields. Shown as read-only preview in modal. Always consistent with other fields.

## Review tracking

- **ReviewedAt datetime** (not boolean). Set when editor submits via modal, reset to null on regeneration. Null = needs review. Datetime allows tracking when review happened. Also serves as the publish-readiness gate for the page-publish cascade.

## Meta tag collision

- **`<title>` tag is template-driven.** Typically rendered in the theme template (e.g. `$Title | $SiteConfig.Title`). The module does not store a `MetaTitle` field; `og:title` uses `OGTitle` and JSON-LD titles fall back to the page title when no OG title is set.
- **`<meta name="description">`** — the module overrides `getMetaDescription()` on SiteTree so `MetaTags()` picks up the `GeneratedMetadata` value automatically. No framework-level change needed.
- **SiteTree Metadata toggle preserved.** The toggle section is not hidden — `ExtraMeta` (Custom Meta Tags) must remain. The `MetaDescription` field is replaced with a read-only field showing the `GeneratedMetadata` value.

## API endpoints

- **Polymorphic-aware URLs.** Endpoints use `/{FQCN}/{ID}` pattern to support any DataObject type with the metadata extension, not just pages. FQCN is validated to prevent arbitrary class injection.

## Permissions

- **Simple model.** canEdit() on the parent DataObject gates all metadata operations. No new permission codes. Report visible to anyone with Reports section access. Background job runs as admin.
