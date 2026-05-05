<?php

namespace SilverstripeLtd\AiMetadata\Providers;

use SilverstripeLtd\AiMetadata\Exceptions\AIProviderException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Provider integration for OpenAI chat completions.
 */
class OpenAIProvider extends AbstractAIProvider
{
    /**
     * Send a request to the OpenAI API.
     *
     * @return array{status: int, body: string}
     */
    protected function performRequest(string $systemPrompt, string $userPrompt): array
    {
        $apiKey = $this->getApiKey();
        $url = 'https://api.openai.com/v1/chat/completions';
        $payload = [
            'model' => $this->getModel(),
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => $this->getTemperature(),
            'max_tokens' => $this->getMaxTokens(),
        ];

        $client = new Client([
            'timeout' => $this->getTimeout(),
            'connect_timeout' => $this->getTimeout(),
        ]);

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $apiKey,
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
                ? sprintf('OpenAI request timed out after %d seconds', $this->getTimeout())
                : 'OpenAI request failed: ' . $error;
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
     * Extract the JSON text from the OpenAI response payload.
     */
    protected function extractResponseContent(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new AIProviderException('OpenAI returned invalid JSON');
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        $content = is_string($content) ? trim($content) : '';
        if ($content === '') {
            throw new AIProviderException('OpenAI response missing content');
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
     * Return the default OpenAI model name.
     */
    protected function getDefaultModel(): string
    {
        return 'gpt-4.1-mini';
    }
}
