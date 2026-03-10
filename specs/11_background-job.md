# Background Job

## Overview

A `QueuedJob` subclass that bulk-generates AI metadata for pages that need it. Uses the `symbiote/silverstripe-queuedjobs` module.

## Job class

- Class: `GenerateAiMetadataJob` (namespace: `SilverstripeLtd\AiMetadata\Jobs\GenerateAiMetadataJob`)
- Extends: `AbstractQueuedJob`
- Type: `QueuedJob::QUEUED`

## Not enabled by default

The job is **not** automatically scheduled. An administrator must manually create and schedule it via the Queued Jobs CMS interface. This is consistent with the scope requirement and leverages the existing CMS interface for job management (see `docs/03_human-context.md` — no env var needed to enable/disable since admins can toggle directly in the CMS).

## Pages targeted

The job processes pages that meet either condition:

1. **No metadata:** Page has no `GeneratedMetadata` record, or `GeneratedMetadata` exists but has never been generated (`GeneratedAt` is null)
2. **Stale metadata:** Page has `GeneratedMetadata` but the content hash no longer matches (see `specs/07_stale-metadata.md`)

Pages are processed in `SiteTree.ID` order (deterministic, simple).
The job caps the number of pages per run via `AI_MODULE_JOB_BATCH_SIZE` (default: 50).

## Processing

For each page:

1. Check if the page has extractable content (non-empty string). Content is read from the **Draft** version when available, falling back to Live if the draft record does not exist (see `specs/03_content-extraction.md` - Versioned reading mode). Skip if empty.
2. Check if metadata is missing or stale (re-extract content, compare hash).
3. If generation is needed, call the AI provider (see `specs/04_ai-providers.md`).
4. Store results on the `GeneratedMetadata` Draft record (see `specs/06_generation-behaviour.md`).
5. Reset `ReviewedAt` to null (metadata needs human review). The metadata stays in Draft — it will only be published to Live when an editor reviews it and the page is next published.
6. Wait for the configured delay before processing the next page.

## Rate limiting

- Configurable delay between API calls: `AI_MODULE_RATE_LIMIT_DELAY` environment variable (default: 6 seconds)
- This prevents overwhelming the AI provider's rate limits during bulk processing

## Error handling

- **Per-page errors:** Log the error (via Silverstripe's `Injector::inst()->get(LoggerInterface::class)`), skip the page, continue to next.
- **Provider exceptions** (`AIProviderException`): Caught per-page, logged, page skipped.
- **Fatal errors** (e.g. missing/invalid API key affecting all pages): The job will fail on the first page and stop. The error is visible in the Queued Jobs CMS interface.
  - The job re-queues a fresh instance even on fatal failure so it can be retried after configuration is fixed.

## Logging

- Log each page processed (page ID, title, success/failure)
- Log summary at completion (total processed, succeeded, failed, skipped)
- Uses standard Silverstripe logging (PSR-3 `LoggerInterface`)

## llms.txt regeneration

`/llms.txt` is generated dynamically on request, so the job does not need to regenerate it.

## Re-queue behaviour

At the end of a run, the job re-queues a fresh instance scheduled for a later run (default 8 hours via `AI_MODULE_JOB_REQUEUE_DELAY`). This keeps periodic regeneration available without manual re-creation.

## Concurrency

- Only one instance of the job should run at a time (standard `QueuedJob` behaviour — the queue runner processes jobs sequentially)
- If an editor regenerates a page's metadata while the job is running, last write wins (acceptable — see `specs/06_generation-behaviour.md`)
