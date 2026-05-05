<?php

namespace SilverstripeLtd\AiMetadata\Tests;

use SilverstripeLtd\AiMetadata\Exceptions\AIProviderException;
use SilverstripeLtd\AiMetadata\Providers\AbstractAIProvider;
use SilverstripeLtd\AiMetadata\ValueObjects\AiMetadataResult;

/**
 * Stub AI provider returning fixed results.
 */
class StubProvider extends AbstractAIProvider
{
    private AiMetadataResult $result;
    /**
     * @var string[]
     */
    private array $failureTitles;

    /**
     * Create a stub provider with a predefined result.
     *
     * @param string[] $failureTitles
     */
    public function __construct(AiMetadataResult $result, array $failureTitles = [])
    {
        $this->result = $result;
        $this->failureTitles = $failureTitles;
    }

    /**
     * Return the preconfigured metadata result.
     */
    public function generateMetadata(string $content, string $pageTitle, string $pageUrl): AiMetadataResult
    {
        if (in_array($pageTitle, $this->failureTitles, true)) {
            throw new AIProviderException('Boom');
        }
        return $this->result;
    }

    /**
     * Provide a no-op HTTP request payload for stubs.
     *
     * @return array{status: int, body: string}
     */
    protected function performRequest(string $systemPrompt, string $userPrompt): array
    {
        return ['status' => 200, 'body' => '{}'];
    }

    /**
     * Provide a placeholder content body for stubs.
     */
    protected function extractResponseContent(string $body): string
    {
        return '{}';
    }

    /**
     * Stubbed providers never treat responses as transient.
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
