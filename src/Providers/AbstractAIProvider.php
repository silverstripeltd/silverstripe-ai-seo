<?php

namespace SilverstripeLtd\AiMetadata\Providers;

use SilverstripeLtd\AiMetadata\ValueObjects\AiMetadataResult;
use SilverstripeLtd\AiMetadata\Exceptions\AIProviderException;
use SilverstripeLtd\AiMetadata\Services\PromptService;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Config\Config;

/**
 * Base provider implementation with shared prompt handling.
 */
abstract class AbstractAIProvider
{
    private PromptService $promptService;
    protected LoggerInterface $logger;

    /**
     * Configure the provider with optional dependencies.
     */
    public function __construct(?PromptService $promptService = null, ?LoggerInterface $logger = null)
    {
        $this->promptService = $promptService ?: Injector::inst()->get(PromptService::class);
        $this->logger = $logger ?: Injector::inst()->get(LoggerInterface::class);
    }

    /**
     * Generate metadata using the provider.
     *
     * @throws AIProviderException
     */
    public function generateMetadata(string $content, string $pageTitle, string $pageUrl): AiMetadataResult
    {
        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            $this->logger->warning('AI provider API key missing', ['provider' => static::class]);
            throw new AIProviderException('AI_MODULE_API_KEY is not configured', false, true);
        }

        [$systemPrompt, $userPrompt] = $this->promptService->buildPrompts($content, $pageTitle, $pageUrl);
        $model = $this->getModel();
        $this->logger->info('AI provider request starting', [
            'provider' => static::class,
            'model' => $model,
            'timeout' => $this->getTimeout(),
        ]);
        $startedAt = microtime(true);
        $loggedFailure = false;

        try {
            $response = $this->performRequest($systemPrompt, $userPrompt);
            $status = $response['status'] ?? 0;
            $body = $response['body'] ?? '';
            $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
            $this->logger->debug('AI provider response received', [
                'provider' => static::class,
                'status' => $status,
                'durationMs' => $durationMs,
            ]);

            if ($status >= 400) {
                $message = $this->extractErrorMessage($body) ?: 'AI provider request failed';
                $this->logger->warning('AI provider request failed', [
                    'provider' => static::class,
                    'status' => $status,
                    'message' => $message,
                ]);
                $loggedFailure = true;
                throw new AIProviderException(
                    $message,
                    $this->isTransientStatus($status),
                    $this->isBlockingStatus($status)
                );
            }

            $contentJson = $this->extractResponseContent($body);
            return $this->parseMetadata($contentJson);
        } catch (AIProviderException $exception) {
            if (!$loggedFailure) {
                $this->logger->warning('AI provider error', [
                    'provider' => static::class,
                    'message' => $exception->getMessage(),
                    'transient' => $exception->isTransient(),
                ]);
            }
            throw $exception;
        }
    }

    /**
     * Resolve the API key for this provider.
     */
    protected function getApiKey(): string
    {
        if (!Environment::hasEnv('AI_MODULE_API_KEY')) {
            return '';
        }

        $env = Environment::getEnv('AI_MODULE_API_KEY');
        return $env !== false ? (string)$env : '';
    }

    /**
     * Resolve the model to use for this provider.
     */
    protected function getModel(): string
    {
        $env = Environment::hasEnv('AI_MODULE_MODEL')
            ? Environment::getEnv('AI_MODULE_MODEL')
            : null;
        if ($env !== null && $env !== '' && $env !== false) {
            return (string)$env;
        }

        $config = Config::inst()->get(static::class, 'model');
        if ($config) {
            return (string)$config;
        }

        return $this->getDefaultModel();
    }

    /**
     * Resolve the provider-specific thinking level.
     */
    protected function getThinkingLevel(): string
    {
        $env = Environment::hasEnv('AI_MODULE_THINKING_LEVEL')
            ? Environment::getEnv('AI_MODULE_THINKING_LEVEL')
            : null;
        return $env !== null && $env !== '' && $env !== false ? (string)$env : 'low';
    }

    /**
     * Resolve the provider temperature.
     */
    protected function getTemperature(): float
    {
        $env = Environment::hasEnv('AI_MODULE_TEMPERATURE')
            ? Environment::getEnv('AI_MODULE_TEMPERATURE')
            : null;
        return $env !== null && $env !== '' && $env !== false ? (float)$env : 1.0;
    }

    /**
     * Resolve the provider max token limit.
     */
    protected function getMaxTokens(): int
    {
        $env = Environment::hasEnv('AI_MODULE_MAX_TOKENS')
            ? Environment::getEnv('AI_MODULE_MAX_TOKENS')
            : null;
        $value = $env !== null && $env !== '' && $env !== false ? (int)$env : 2000;
        return $value > 0 ? $value : 2000;
    }

    /**
     * Resolve the request timeout in seconds.
     */
    protected function getTimeout(): int
    {
        $env = Environment::hasEnv('AI_MODULE_REQUEST_TIMEOUT')
            ? Environment::getEnv('AI_MODULE_REQUEST_TIMEOUT')
            : null;
        if ($env !== null && $env !== '' && $env !== false) {
            $value = (int)$env;
            if ($value > 0) {
                return $value;
            }
        }

        return 15;
    }

    /**
     * Parse the provider response into a metadata result.
     *
     * @throws AIProviderException
     */
    private function parseMetadata(string $json): AiMetadataResult
    {
        $payload = $this->decodeJson($json);
        if (!is_array($payload)) {
            throw new AIProviderException('AI provider returned malformed JSON');
        }

        return new AiMetadataResult([
            'metaDescription' => $payload['metaDescription'] ?? null,
            'ogTitle' => $payload['ogTitle'] ?? null,
            'ogDescription' => $payload['ogDescription'] ?? null,
            'summaryLong' => $payload['summaryLong'] ?? null,
            'keyEntities' => isset($payload['keyEntities']) && is_array($payload['keyEntities'])
                ? $payload['keyEntities'] : null,
            'keyTopics' => isset($payload['keyTopics']) && is_string($payload['keyTopics'])
                ? $payload['keyTopics'] : null,
            'suggestedFAQs' => isset($payload['suggestedFAQs']) && is_array($payload['suggestedFAQs'])
                ? $payload['suggestedFAQs'] : null,
        ]);
    }

    /**
     * Decode a JSON payload, attempting to recover embedded JSON if needed.
     *
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $json): ?array
    {
        $trimmed = trim($json);
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Extract a provider error message from the response body.
     */
    private function extractErrorMessage(string $body): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['error']['message'])) {
            return (string)$decoded['error']['message'];
        }

        return '';
    }

    /**
     * Perform the provider request.
     *
     * @return array{status: int, body: string}
     */
    abstract protected function performRequest(string $systemPrompt, string $userPrompt): array;

    /**
     * Extract the JSON content string from the provider response body.
     *
     * @throws AIProviderException
     */
    abstract protected function extractResponseContent(string $body): string;

    /**
     * Determine whether the status code is transient.
     */
    abstract protected function isTransientStatus(int $statusCode): bool;

    /**
     * Return the default model name for this provider.
     */
    abstract protected function getDefaultModel(): string;

    /**
     * Determine whether the status code should block further processing.
     */
    private function isBlockingStatus(int $statusCode): bool
    {
        return in_array($statusCode, [401, 403], true);
    }
}
