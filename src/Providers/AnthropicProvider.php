<?php

namespace SilverstripeLtd\AiSeo\Providers;

use SilverstripeLtd\AiSeo\Exceptions\AIProviderException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Provider integration for Anthropic Claude.
 */
class AnthropicProvider extends AbstractAIProvider
{
    /**
     * Send a request to the Anthropic API.
     *
     * @return array{status: int, body: string}
     */
    protected function performRequest(string $systemPrompt, string $userPrompt): array
    {
        $apiKey = $this->getApiKey();
        $url = 'https://api.anthropic.com/v1/messages';
        $payload = [
            'model' => $this->getModel(),
            'max_tokens' => $this->getMaxTokens(),
            'temperature' => $this->getTemperature(),
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];

        $client = new Client([
            'timeout' => $this->getTimeout(),
            'connect_timeout' => $this->getTimeout(),
        ]);

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ],
                'json' => $payload,
                'http_errors' => false,
            ]);
        } catch (RequestException $exception) {
            $handlerContext = $exception->getHandlerContext();
            $errno = isset($handlerContext['errno']) ? (int)$handlerContext['errno'] : 0;
            $timedOut = (bool)($handlerContext['timed_out'] ?? false);
            $error = isset($handlerContext['error']) ? (string)$handlerContext['error'] : $exception->getMessage();
            $message = $errno === CURLE_OPERATION_TIMEDOUT
                || $timedOut
                ? sprintf('Anthropic request timed out after %d seconds', $this->getTimeout())
                : 'Anthropic request failed: ' . $error;
            $this->logger->warning('AI provider connection failed', [
                'provider' => static::class,
                'endpoint' => $url,
                'errno' => $errno,
                'error' => $error,
                'timeout' => $this->getTimeout(),
            ]);
            throw new AIProviderException($message, true);
        }

        $body = (string)$response->getBody();
        $status = $response->getStatusCode();
        return [
            'status' => $status,
            'body' => $body,
        ];
    }

    /**
     * Extract the JSON text from the Anthropic response payload.
     */
    protected function extractResponseContent(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new AIProviderException('Anthropic returned invalid JSON');
        }

        $content = $decoded['content'][0]['text'] ?? null;
        $content = is_string($content) ? trim($content) : '';
        if ($content === '') {
            throw new AIProviderException('Anthropic response missing content');
        }
        return $content;
    }

    /**
     * Check whether the status code indicates a transient failure.
     */
    protected function isTransientStatus(int $statusCode): bool
    {
        return $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * Return the default Anthropic model name.
     */
    protected function getDefaultModel(): string
    {
        return 'claude-3-5-sonnet-20240620';
    }
}
