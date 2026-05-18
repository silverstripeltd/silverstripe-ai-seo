# AI Providers

## Providers

- **Gemini** — primary provider (default)
- **OpenAI** — Chat Completions API provider
- **Anthropic** — Messages API provider
- **Custom providers** — the built-in factory supports `gemini`, `openai`, and `anthropic` only. To use a custom provider, projects must override the factory via Silverstripe's Injector.

Gemini requests call the v1beta `generateContent` endpoint and include `thinkingConfig.thinkingLevel` when `AI_SEO_THINKING_LEVEL` is not `none`.

## Provider selection

- One active provider at a time
- Switching provider should be straightforward (designed for single active provider, not multi-provider routing)
- Selected via environment variable `AI_SEO_PROVIDER` (default: `gemini`)

## Provider base class

All providers extend `AbstractAIProvider`, which supplies `generateMetadata()` and shared error handling:

```php
abstract class AbstractAIProvider
{
    /**
     * Generate all metadata fields for the given content.
     *
     * @param string $content The extracted page content (plain text)
     * @param string $pageTitle The page title for context
     * @param string $pageUrl The page URL for context
     * @return AiSeoResult Object containing all generated field values
     * @throws AIProviderException On unrecoverable failure
     */
    public function generateMetadata(string $content, string $pageTitle, string $pageUrl): AiSeoResult;
}
```

Concrete providers implement the protected request hooks (`performRequest`, `extractResponseContent`, `isTransientStatus`, and `getDefaultModel`) defined by `AbstractAIProvider`.
HTTP requests are made with Guzzle (bundled with Silverstripe framework) and respect the configured timeouts.

### Generation approach

- **Single API call** generates all metadata fields at once
- The prompt asks the AI to return a JSON object with keys matching the metadata field names
- The provider parses the JSON response and returns an `AiSeoResult` value object
- If the AI response is malformed or missing fields, the provider throws `AIProviderException`

### AiSeoResult value object

A simple value object with nullable typed properties for each metadata field:

```php
class AiSeoResult
{
    public ?string $metaDescription;
    public ?string $ogTitle;
    public ?string $ogDescription;
    public ?string $summaryLong;
    public ?array $keyEntities;    // decoded JSON array
    public ?array $keyTopics;      // decoded JSON array
    public ?array $suggestedFAQs;  // decoded JSON array
}
```

### Error handling

- **Transient failures** (network timeout, rate limit, 5xx): Throw `AIProviderException` immediately (no retry).
- **Permanent failures** (invalid API key, 4xx non-rate-limit): Throw `AIProviderException` immediately.
- **Malformed response** (AI returns invalid JSON, missing required keys): Throw `AIProviderException`.
- **Callers** (CMS controller, background job) catch `AIProviderException` and handle appropriately — toast notification for CMS, log-and-skip for background job, with blocking failures (e.g. missing/invalid API key) aborting the job.

### Request timeout

- Default timeout: 15 seconds per API call
- Configurable via environment variable `AI_SEO_REQUEST_TIMEOUT` (seconds)

## Configuration

All configuration via environment variables. Env vars are preferred over YAML config because the hosting support team can change env vars and trigger deployments via support ticket, whereas code changes require booking developer time which can take weeks.

| Environment variable | Description | Default |
|---|---|---|
| `AI_SEO_PROVIDER` | Active provider (`gemini`, `openai`, `anthropic`) | `gemini` |
| `AI_SEO_API_KEY` | API key for the active provider | (required) |
| `AI_SEO_MODEL` | Model to use (e.g. `gemini-3.1-flash-lite`, `gpt-4.1`) | Provider-specific default |
| `AI_SEO_THINKING_LEVEL` | Thinking level (`none`, `low`, `medium`, `high`) used by Gemini `thinkingConfig` | `low` |
| `AI_SEO_TEMPERATURE` | Temperature for generation | `1.0` |
| `AI_SEO_MAX_TOKENS` | Max tokens in response | `2000` |
| `AI_SEO_REQUEST_TIMEOUT` | Request timeout in seconds | `15` |
| `AI_SEO_RATE_LIMIT_DELAY` | Delay in seconds between API calls (for background job) | `6` |

### Overriding in project code

Provider defaults (model, thinking level, etc.) can also be overridden via Silverstripe YAML config on the provider class, for cases where env vars aren't suitable. Env vars take precedence over YAML config.
