<?php

namespace SilverstripeLtd\AiMetadata\Tests;

use SilverstripeLtd\AiMetadata\Providers\AbstractAIProvider;
use SilverstripeLtd\AiMetadata\ValueObjects\AiMetadataResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Test provider that returns canned responses.
 */
class TestAIProvider extends AbstractAIProvider
{
    public array $responses = [];
    public int $callCount = 0;

    /**
     * Store the response queue.
     *
     * @param array<int, array{status: int, body: string}> $responses
     */
    public function __construct(array $responses, ?LoggerInterface $logger = null)
    {
        parent::__construct(null, $logger ?: new NullLogger());
        $this->responses = $responses;
    }

    /**
     * Return the next queued response payload.
     *
     * @return array{status: int, body: string}
     */
    protected function performRequest(string $systemPrompt, string $userPrompt): array
    {
        $response = $this->responses[$this->callCount] ?? ['status' => 200, 'body' => '{}'];
        $this->callCount++;
        return $response;
    }

    /**
     * Return the response body as-is.
     */
    protected function extractResponseContent(string $body): string
    {
        return $body;
    }

    /**
     * Treat 5xx responses as transient.
     */
    protected function isTransientStatus(int $statusCode): bool
    {
        return $statusCode >= 500;
    }

    /**
     * Return a fixed test model name.
     */
    protected function getDefaultModel(): string
    {
        return 'test-model';
    }

    /**
     * Expose timeout resolution for tests.
     */
    public function getResolvedTimeout(): int
    {
        return $this->getTimeout();
    }

    /**
     * Expose temperature resolution for tests.
     */
    public function getResolvedTemperature(): float
    {
        return $this->getTemperature();
    }

    /**
     * Expose thinking level resolution for tests.
     */
    public function getResolvedThinkingLevel(): string
    {
        return $this->getThinkingLevel();
    }
}
