<?php

namespace SilverstripeLtd\AiMetadata\Tests;

use SilverstripeLtd\AiMetadata\Exceptions\AIProviderException;
use SilverstripeLtd\AiMetadata\Providers\AbstractAIProvider;
use SilverstripeLtd\AiMetadata\ValueObjects\AiMetadataResult;

/**
 * Provider stub that always throws a blocking exception.
 */
class BlockingJobProvider extends AbstractAIProvider
{
    /**
     * Throw a blocking exception to halt job processing.
     */
    public function generateMetadata(string $content, string $pageTitle, string $pageUrl): AiMetadataResult
    {
        throw new AIProviderException('Missing API key', false, true);
    }

    /**
     * Provide a no-op HTTP request payload for stubs.
     *
     * @return array{status: int, body: string}
     */
    protected function performRequest(string $systemPrompt, string $userPrompt): array
    {
        return ['status' => 403, 'body' => '{}'];
    }

    /**
     * Provide a placeholder content body for stubs.
     */
    protected function extractResponseContent(string $body): string
    {
        return '{}';
    }

    /**
     * Treat all stub statuses as non-transient.
     */
    protected function isTransientStatus(int $statusCode): bool
    {
        return false;
    }

    /**
     * Return a stub model name.
     */
    protected function getDefaultModel(): string
    {
        return 'stub';
    }
}
