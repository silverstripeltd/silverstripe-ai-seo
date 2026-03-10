# CMS Report

## Overview

A Silverstripe CMS report showing pages that need metadata attention. Accessible from the Reports section of the CMS.

## Report class

- Class: `AiMetadataReport` (namespace: `SilverstripeLtd\AiMetadata\Reports\AiMetadataReport`)
- Extends: `SilverStripe\Reports\Report`
- Title: "AI Metadata Status"

## Columns

| Column | Description |
|--------|-------------|
| Page title | Linked to the page edit form in the CMS |
| Page type | Class name (e.g. `Page`, `BlogPost`) |
| Status | One of: **Missing**, **Stale**, **Unreviewed**, **OK** |
| Live status | One of: **Published**, **Not published**, **Outdated** |
| Last generated | `GeneratedAt` datetime, or "Never" |
| Last reviewed | `ReviewedAt` datetime, or "Never" |

## Status definitions

- **Missing** - page has no `GeneratedMetadata` record, or `GeneratedAt` is null
- **Stale** - content hash has changed since metadata was last generated (requires re-extraction and comparison, using the same extraction pipeline as the modal/job)
- **Unreviewed** - metadata has been generated but not yet reviewed. Either `ReviewedAt` is null, or `ReviewedAt` is older than `GeneratedAt` (meaning metadata was regenerated after the last review)
- **OK** - metadata exists, is not stale, and has been reviewed

## Live status definitions

- **Published** - metadata is published to the Live stage and matches Draft (visitors see the reviewed metadata)
- **Not published** - metadata has no Live record (visitors do not see any AI-generated metadata for this page)
- **Outdated** - metadata exists on Live but Draft has newer changes not yet published (visitors see the old metadata)

"Needs attention" = Missing OR Stale OR Unreviewed OR (OK + Not published).

## Filtering

- Dropdown filter by status (Needs attention / Missing / Stale / Unreviewed / OK / Not published / All)
- Default view: "Needs attention" (shows Missing + Stale + Unreviewed + OK that is not published)

## Sorting

- Default sort: Status priority (Missing first, then Stale, then Unreviewed, then OK), then page title alphabetically

## Permissions

- Visible to anyone with access to the CMS Reports section (standard Silverstripe permission: `CMS_ACCESS_ReportAdmin`)
- No additional permission codes needed

## Performance note

The "Stale" check requires re-extracting content and computing the hash for each page, which is heavier than a simple DB query. For sites with thousands of pages, the report may take a few seconds to load. This is acceptable for an on-demand report. The report uses pagination (via `PaginatedList`) to keep the UI manageable for large sites.
