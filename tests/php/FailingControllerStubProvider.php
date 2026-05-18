<?php

namespace SilverstripeLtd\AiSeo\Tests;

use SilverstripeLtd\AiSeo\Exceptions\AIProviderException;
use SilverstripeLtd\AiSeo\Providers\AbstractAIProvider;
use SilverstripeLtd\AiSeo\ValueObjects\AiSeoResult;

/**
 * Stub provider that always throws.
 */
class FailingControllerStubProvider extends AbstractAIProvider
{
    /**
     * Throw a provider exception for regeneration tests.
     */
    public function generateSeo(string $content, string $pageTitle, string $pageUrl): AiSeoResult
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
