# AI Providers

## Providers

The provider layer comes from the shared `silverstripeltd/silverstripe-ai-core` package, which every Silverstripe AI module uses.

- **Gemini** - primary provider (default for this module)
- **OpenAI** - Chat Completions API provider
- **Anthropic** - Messages API provider
- **Custom providers** - register them in the ai-core `ProviderFactory.providers` YAML map (see the ai-core README).

The thinking level is passed to whichever vendor is active (Gemini `thinkingConfig.thinkingLevel`, OpenAI `reasoning_effort`, Anthropic `output_config.effort`). The module default sets `low` for Gemini only, so out of the box only Gemini receives one.

## Provider selection

- One active provider at a time
- Selected via environment variable `AI_SEO_PROVIDER`, then the shared `AI_PROVIDER`, then the module YAML default (`gemini`)

## Generation service

`AiSeoClient::generateSeo(string $content, string $pageTitle, string $pageUrl): AiSeoResult` builds the prompts with `PromptService`, sends them through ai-core's `JsonCompletion` with `EnvProviderSettings::forModule('SEO')`, and maps the decoded JSON onto an `AiSeoResult`. `SeoGenerationService` calls it.

### Generation approach

- **Single API call** generates all metadata fields at once
- The prompt asks the AI to return a JSON object with keys matching the metadata field names
- The reply is decoded as is, or from the text between the first `{` and the last `}` when it is wrapped in prose or code fences
- If the AI response is malformed, `ProviderException` is thrown

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
    public ?string $keyTopics;     // comma separated topics
    public ?array $suggestedFAQs;  // decoded JSON array
}
```

### Error handling

Every failure is an ai-core `SilverstripeLtd\AiCore\Provider\ProviderException`:

- **Transient failures** (network timeout, rate limit, 5xx): `isTransient()` is true. Thrown immediately (no retry).
- **Blocking failures** (missing or invalid API key, 401/403, unknown provider, invalid settings): `isBlocking()` is true.
- **Permanent failures** (other 4xx, malformed reply): neither flag.
- **Callers** (CMS controller, background job) catch `ProviderException` and handle it: toast notification for CMS, log-and-skip for the background job, with blocking failures aborting the job.

### Request timeout

- Default timeout: 15 seconds per API call
- Configurable via environment variable `AI_SEO_REQUEST_TIMEOUT` (seconds)

## Configuration

All configuration via environment variables. Env vars are preferred over YAML config because the hosting support team can change env vars and trigger deployments via support ticket, whereas code changes require booking developer time which can take weeks.

Every `AI_SEO_*` provider variable falls back to the shared `AI_*` variable of the same name (`AI_PROVIDER`, `AI_API_KEY`, `AI_MODEL`, ...), so one key in `.env` can serve every AI module. The shared key and model are skipped when `AI_SEO_PROVIDER` names a different provider than `AI_PROVIDER`.

| Environment variable | Description | Default |
|---|---|---|
| `AI_SEO_PROVIDER` | Active provider (`gemini`, `openai`, `anthropic`) | `gemini` |
| `AI_SEO_API_KEY` | API key for the active provider | (required) |
| `AI_SEO_MODEL` | Model to use | `gemini-3.1-flash-lite`, `gpt-5-mini` or `claude-haiku-4-5` |
| `AI_SEO_THINKING_LEVEL` | Thinking level sent to the active vendor (`none` sends nothing) | `low` for Gemini, none for the others |
| `AI_SEO_TEMPERATURE` | Temperature for generation | `1.0` |
| `AI_SEO_MAX_TOKENS` | Max tokens in response | `2000` |
| `AI_SEO_REQUEST_TIMEOUT` | Request timeout in seconds | `15` |
| `AI_SEO_RATE_LIMIT_DELAY` | Delay in seconds between API calls (for background job) | `6` |

### Overriding in project code

The defaults live in YAML under `SilverstripeLtd\AiCore\Settings\EnvProviderSettings.modules.SEO` (with per provider overrides under `providers`), for cases where env vars aren't suitable. Env vars take precedence over YAML config.
