# Data Architecture

## Separate DataObject for metadata fields

Metadata is stored on a separate DataObject (`GeneratedSeo`) linked to the parent page (or other DataObject) via a polymorphic relationship.

### Schema

- **Class name:** `GeneratedSeo` (namespace: `SilverstripeLtd\AiSeo\Models\GeneratedSeo`)
- **Table name:** `AiSeo` (explicitly set to avoid a migration when the class was renamed)
- **Relationship:** `GeneratedSeo` has_one `Parent` (polymorphic)
  - `ParentID` (Int) + `ParentClass` (Varchar) — standard Silverstripe polymorphic pattern
  - The page does NOT have a has_one to the metadata; instead, the metadata points back to the page
  - Polymorphic design supports future extension to other DataObject types without migration
- **Versioned:** Yes. `GeneratedSeo` uses the `Versioned` extension (Draft/Live)
  - Sideways XHR saves from the CMS modal and background job writes go to the **Draft** stage
  - Metadata is published to Live **only when the parent page is published**, via a JS hook that fires after the page publish action
  - **ReviewedAt publish gate:** The JS publish hook only publishes metadata if `ReviewedAt` is set (i.e. a human has reviewed the metadata). Unreviewed draft metadata stays in Draft even when the page is published — this prevents unverified AI-generated content from going live accidentally
  - Frontend rendering (`updateMetaTags()`, JSON-LD, llms.txt) reads from the **Live** stage, so only reviewed+published metadata appears on the site

### Reasons for separate DataObject

- Avoids cluttering existing tables with many new columns
- Background job can write to metadata's Draft stage without touching page's versioned state at all
- Cleaner separation of concerns
- Polymorphic relationship enables future extension to other DataObjects

### Extension on Page

An Extension is applied to `SiteTree` (or `Page`) that:

- Provides helper methods to fetch (`getAiSeo()`) and get-or-create (`getOrCreateAiSeo()`) the `GeneratedSeo` record for a page
- Replaces the `MetaDescription` field in SiteTree's Metadata toggle with a read-only field showing the `GeneratedSeo` value (see `specs/09_cms-ux.md`)
- Adds the "AI SEO" button to the CMS edit form (as specified in `specs/09_cms-ux.md`)

### Lifecycle

- **First access:** When a page's metadata is first requested (via modal open, background job, or rendering), the `GeneratedSeo` record is created if it doesn't exist
- **Module removal:** If the module is uninstalled, the `AiSeo` table and extension are simply removed. Original SiteTree `MetaDescription` values remain unaffected. See data migration notes below.

### Data migration (existing sites)

When the module is installed on a site that already has `MetaDescription` values on pages:

- A `BuildTask` (`MigrateExistingSeoTask`) copies existing `SiteTree.MetaDescription` values into new `GeneratedSeo` records
- The task is idempotent — safe to run multiple times (skips pages that already have a `GeneratedSeo` record with a non-empty `MetaDescription`)
- The task does NOT modify the original SiteTree fields (they remain as a fallback)
- The task is not run automatically — it must be triggered manually by an administrator
