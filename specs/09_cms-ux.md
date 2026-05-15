# CMS UX

## JS framework

The modal is rendered as a React component. The modal's fields are defined server-side in PHP and delivered as JSON to the React frontend via Silverstripe's FormSchema mechanism.

## UI container

A modal dialog opened from the page edit form. This is the initial approach for development — the container may change later (e.g. a tab, toggleable section, sidebar, or inline panel).

A button on the page edit form opens the modal. Button should be placed in the page actions area (bottom toolbar), visually distinct from save/publish buttons.

## Modal layout

Top to bottom:

1. **Generation status banner** — always shown. It combines the review state with the timestamp (formatted in the editor's local timezone/locale):
   - **No AI metadata yet.** — shown when `GeneratedAt` is empty (no timestamp).
   - **AI metadata ready for review. Last generated: <timestamp>** — shown when `GeneratedAt` is set and `ReviewedAt` is empty or older than `GeneratedAt`.
   - **AI metadata reviewed and saved. Last generated: <timestamp>. Status: <draft status>.** — shown when `GeneratedAt` is set and `ReviewedAt` is newer than or equal to `GeneratedAt`. The draft status reflects the versioned state of the `GeneratedMetadata` record:
     - **Draft only (not published yet)** — metadata exists only on Draft.
     - **Draft changes not published** — Draft differs from Live.
     - **Published** — Draft and Live match and Live exists.
   The banner uses different colours per state to make the status visible at a glance.
2. **Stale content indicator** — shown inside the modal if the content hash has changed since metadata was last generated. Warning banner text: "Page content has changed since metadata was generated. To regenerate, click the "Regenerate" button."
2a. **Draft changes notice** — shown when the page has unpublished changes (Draft differs from Live or Live does not exist). Informs the editor that the AI metadata reflects draft content and will go live when the page is published. This is an informational notice (not a warning). This notice is driven by the same draft status logic used in the modal schema meta (the Draft vs Live comparison), so it should remain consistent with the status banner state.
3. **All metadata fields** — laid out vertically, one after another. Traditional SEO fields first (editable text fields), then AI-oriented fields:
   - `MetaDescription`, `OGTitle`, `OGDescription` — editable text fields
   - `MetaDescription` shows a yellow warning indicator if it exceeds the recommended character limit (default 150, configurable via `AI_METADATA_META_DESCRIPTION_MAX`)
    - `SummaryLong` — editable textarea shown at 3 rows
   - `KeyEntities`, `KeyTopics`, `SuggestedFAQs` — **read-only formatted display** (not editable; regenerate to change). Show as nicely formatted text, not raw JSON.
   - `JsonLdSchema` — **read-only preview** showing the assembled JSON-LD for the page. Not stored in DB, dynamically assembled for display.
4. **Review confirmation checkbox** — shows **I have reviewed the AI metadata** while review is required. When metadata is already reviewed, the label switches to **Metadata was reviewed** and the checkbox is disabled.
5. **Action buttons** (top to bottom):
    - **Generate Metadata / Regenerate** button — triggers AI generation of metadata. Shows **Generate Metadata** when `GeneratedAt` is empty, otherwise **Regenerate**. This button uses the CMS info button style.
    - **Apply Metadata** button — saves the metadata. It is shown only after AI-generated metadata exists, and is disabled unless metadata needs review and the review confirmation checkbox is active, or an editor has made manual edits in the editable fields. This button uses the CMS info button style.
    - **Submit note** — the text "Check the "I have reviewed the AI metadata" checkbox, then click Apply Metadata. Metadata will go live when the page is next published." is shown above Apply Metadata only when review is required.

### Footer button states

- **Generate Metadata/Regenerate:** enabled by default. Disabled only while a generation request is in progress, then re-enabled when the response returns.
- **Review confirmation:** disabled when there is no metadata to review (never generated, or already reviewed). Enabled after generation when review is required.
- **Apply Metadata:** hidden until metadata has been generated. Once shown, it is disabled when review is not required and there are no manual edits, and enabled when review is required and confirmed, or when an editor has changed an editable field.

## Toast notifications

- **Generation success toast** — distinguishes between first generation and regeneration (i.e. whether `GeneratedAt` was already set before this request).
- **Save success toast** on successful submit.
- **Error toast** if generation fails, submit fails, or initial metadata fetch fails.

## Stale metadata indicator (REMOVED)

~~Previously shown on the page edit form near the "AI Metadata" button.~~ **Removed.** Stale metadata detection is now handled exclusively via the CMS report (`specs/13_cms-report.md`). No inline stale indicator on the page edit form.

## SiteTree Metadata toggle section

SiteTree's existing "Metadata" toggle section on the page edit form contains `MetaDescription` and `ExtraMeta` (Custom Meta Tags). The module does **not** hide this section — `ExtraMeta` must remain available.

Instead, the extension applied to `SiteTree` via `updateCMSFields()`:

- **Replaces** the `MetaDescription` field with a **read-only field** that displays the current `MetaDescription` value from the page's `GeneratedMetadata` record
- Adds a description/right-title on the field explaining: "This value is managed by the AI Metadata module. Open the AI Metadata modal to edit."
- If the page's original SiteTree `MetaDescription` had a value (i.e. before the module was installed or before migration), shows it below as informational text: "Previous value: ..."
- **Leaves `ExtraMeta` (Custom Meta Tags) untouched**

## Regenerate behaviour

- Clicking Regenerate sends an XHR request that calls the AI provider and returns generated values.
- Generated values are populated into the modal form fields but are **not** persisted to the database.
- The editor must review the values and click Apply Metadata to save them.
- On submit, `GeneratedAt` is set, `ReviewedAt` is set, `ContentHash` is updated, and the record is written to Draft.
- If the editor closes the modal without submitting, the generated values are lost.
- Loading/spinner state shown while generation is in progress.
- On failure, error toast is shown and existing field values are preserved.

## Submission behaviour

- Apply Metadata is a "sideways" XHR save (does not submit the main page edit form).
- Saves the `GeneratedMetadata` record to Draft independently from the page.
- Sets `ReviewedAt` to the current datetime.
- On success, the modal stays open and the status banner updates to the reviewed state.
- On success, a toast is shown.
- The editor closes the modal manually.
- On failure, an error toast is shown and the modal stays open.
- **Metadata goes live when the page is next published** — see "Publish-on-page-publish" below.

## Publish-on-page-publish

When the editor publishes the parent page, `AiMetadataExtension::onAfterPublish()` runs on the server as part of the normal Silverstripe publish lifecycle. There is no dedicated publish controller endpoint or extra JS publish hook. The extension checks the related `GeneratedMetadata` record and only publishes it when the metadata is reviewed:

- **Metadata is reviewed** (`GeneratedAt` exists and `ReviewedAt` is current via `isReviewed()`): `onAfterPublish()` calls `$metadata->publishSingle()` and metadata goes live with the parent page.
- **Metadata is unreviewed** (for example background-job-generated, or regenerated after the last review): `onAfterPublish()` does nothing, so existing live metadata stays as-is and the newer draft metadata remains on Draft only.

This ensures unverified AI-generated content never goes live accidentally, while matching the editor mental model that "publish page = publish everything on the page".

Similarly, when the parent page is **unpublished** or **archived**, `AiMetadataExtension::onBeforeUnpublish()` and `AiMetadataExtension::onBeforeArchive()` call `$metadata->doUnpublish()` when live metadata exists, so the AI metadata lifecycle stays aligned with the parent record's Live state.

## Loading states

- **Modal open:** loading spinner while fetching existing metadata via XHR. Error toast if fetch fails.
- **Regeneration:** loading spinner on modal fields while AI generation is in progress. Generate Metadata and Regenerate are disabled during generation.
- **Apply Metadata:** brief loading state on the apply button. Error toast if save fails.
