<?php

namespace SilverstripeLtd\AiMetadata\Tests;

use SilverstripeLtd\AiMetadata\Exceptions\AIProviderException;
use SilverstripeLtd\AiMetadata\Providers\AbstractAIProvider;
use SilverstripeLtd\AiMetadata\ValueObjects\AiMetadataResult;

/**
 * Stub provider that always throws.
 */
class FailingControllerStubProvider extends AbstractAIProvider
{
    /**
     * Throw a provider exception for regeneration tests.
     */
    public function generateMetadata(string $content, string $pageTitle, string $pageUrl): AiMetadataResult
    {
        throw new AIProviderException('Provider boom');
    }

    /**
     * Provide a no-op HTTP request payload for stubs.
     *
     * @return array{status: int, body: string}
     */
    protected function performRequest(string $systemPrompt, string $userPrompt): array
    {
        return ['status' => 500, 'body' => '{}'];
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
