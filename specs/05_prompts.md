# Prompts

## Approach

A single AI call generates all metadata fields at once. The prompt asks for structured JSON output containing all field values.

Prompts are hardcoded in the module but extensible via an extension hook at project level. See `docs/03_human-context.md` for why prompts are not admin-editable.
The markdown templates live in the module-root `prompts/` directory so they can sit alongside other non-PHP module assets without being treated as source classes.

## Prompt structure

### System prompt

A short role statement (1-2 sentences). Kept minimal because weaker/cheaper models pay less attention to system prompts than user prompts on some providers (notably Gemini).

See `prompts/system.md` and `PromptService::getSystemPrompt()` for the text.

### User prompt

Contains the bulk of the instructions: field definitions, constraints, edge-case handling, a concrete JSON example, and the page content. This structure is deliberate — weaker models follow instructions more reliably when they appear in the user message.

Key design decisions:
- **All field definitions in the user prompt** rather than the system prompt, for maximum compliance from cheap/small models.
- **Plain text formatting** (no markdown headers) to avoid models echoing markdown in their response.
- **Concrete example JSON** so the model can pattern-match the exact output structure.
- **Explicit array-size limits** (0-5 entities, 2-5 FAQs, 3-7 topics) to keep output predictable and token costs bounded.
- **Strict type constraints** on keyEntities (`Person|Organization|Place` only) since the JSON-LD renderer only handles these three schema.org types.
- **"Do not guess" instruction** for `sameAs` URLs to avoid hallucinated Wikipedia links.
- **Edge-case instructions** so the model returns valid JSON even for empty/thin content.
- **summaryLong set to 100-200 words** (not the original 300-500) to stay within `max_tokens=2000` alongside the other 6 fields.
- **Content delimiters** (`--- PAGE CONTENT START/END ---`) to prevent the model from confusing page content with prompt instructions.
- **"Return only the JSON object" repeated** at the end as a final reinforcement.

See `prompts/user.md` and `PromptService::getUserPrompt()` for the full template.

## Output parsing

The provider parses the AI response as JSON. Field mapping from JSON keys to `GeneratedMetadata` DB fields:

| JSON key | DB field |
|----------|----------|
| `metaDescription` | `MetaDescription` |
| `ogTitle` | `OGTitle` |
| `ogDescription` | `OGDescription` |
| `summaryLong` | `SummaryLong` |
| `keyEntities` | `KeyEntities` (stored as JSON string) |
| `keyTopics` | `KeyTopics` (stored as JSON string) |
| `suggestedFAQs` | `SuggestedFAQs` (stored as JSON string) |

If any key is missing from the response, the corresponding field is set to null.

## Extension hook

The prompt service provides an extension hook for project-level customisation:

```php
$this->extend('updatePrompts', $systemPrompt, $userPrompt);
```

This allows projects to:
- Add site-specific context to the system prompt (e.g. "This is a government website, use formal language")
- Modify field instructions (e.g. change character limits)
- Add additional context to the user prompt

The extension receives both prompts by reference and can modify them.
