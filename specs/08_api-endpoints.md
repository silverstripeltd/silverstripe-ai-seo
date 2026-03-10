# API Endpoints

## Overview

Server-side endpoints for the CMS modal's FormSchema requests. Implemented as a Silverstripe admin controller.

## Controller

- Class: `AiMetadataController` (namespace: `SilverstripeLtd\AiMetadata\Controllers\AiMetadataController`)
- Registered as an admin route (standard Silverstripe admin controller pattern)

## Endpoints

### GET `/admin/ai-metadata/schema/AiMetadataForm/{ID}?fqcn={FQCN}`

Fetch the FormSchema payload for the modal.

- **FQCN:** Fully qualified class name of the parent DataObject (URL-encoded). Must be validated - only classes that have the AI metadata extension applied are allowed.
- **ID:** DataObject ID
- **Auth:** CMS session (standard admin controller)
- **Response:** FormSchema JSON payload with `schema`, `state`, and `meta` (including `stale` + `generatedAt`)
- **Error:** 400 if request parameters are invalid, 403 if user cannot edit, 404 if record not found

### POST `/admin/ai-metadata/aiMetadataForm/{ID}`

Submit modal actions via FormSchema.

- **FQCN:** Passed via request params and validated as above
- **ID:** DataObject ID
- **Auth:** CMS session + CSRF token
- **Behaviour:**
  - `doRegenerate` triggers an AI call and returns generated fields without persisting
  - `doSave` validates, sets `ReviewedAt`, writes Draft metadata, and returns updated schema/state
- **Response:** FormSchema JSON payload with schema/state and validation errors when present
- **Error:** Validation errors are returned in the FormSchema response; request errors follow standard controller error responses

Publishing to Live now happens when the parent record is published (see `AiMetadataExtension`), so there are no dedicated publish/unpublish endpoints.

## FQCN validation

The controller must validate that the provided FQCN:

1. Is a valid, existing class
2. Has the AI metadata extension applied (i.e. has the reciprocal `has_one` to `GeneratedMetadata`)
3. The current user has `canEdit()` permission on the specific record

This prevents security issues with arbitrary class name injection.

## Error response format

Request validation errors use the controller error response JSON payload:

```json
{
  "error": "Human-readable error message"
}
```

Form validation errors are returned inside the FormSchema response `errors` payload.
