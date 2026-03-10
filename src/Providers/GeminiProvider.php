<?php

namespace SilverstripeLtd\AiMetadata\Providers;

use SilverstripeLtd\AiMetadata\Exceptions\AIProviderException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * Provider integration for Google Gemini.
 */
class GeminiProvider extends AbstractAIProvider
{
    /**
     * Send a request to the Gemini API.
     *
     * @return array{status: int, body: string}
     */
    protected function performRequest(string $systemPrompt, string $userPrompt): array
    {
        $model = $this->getModel();
        $apiKey = $this->getApiKey();
        $url = sprintf(
            'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
            rawurlencode($model)
        );

        $payload = [
            'systemInstruction' => [
                'role' => 'system',
                'parts' => [
                    ['text' => $systemPrompt],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $userPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $this->getTemperature(),
                'maxOutputTokens' => $this->getMaxTokens(),
            ],
        ];

        $thinkingLevel = $this->getThinkingLevel();
        if ($thinkingLevel !== 'none') {
            $payload['generationConfig']['thinkingConfig'] = [
                'thinkingLevel' => $thinkingLevel,
            ];
        }

        $client = new Client([
            'timeout' => $this->getTimeout(),
            'connect_timeout' => $this->getTimeout(),
        ]);

        try {
            $response = $client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiKey,
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
                ? sprintf('Gemini request timed out after %d seconds', $this->getTimeout())
                : 'Gemini request failed: ' . $error;
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
     * Extract the JSON text from the Gemini response payload.
     */
    protected function extractResponseContent(string $body): string
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new AIProviderException('Gemini returned invalid JSON');
        }

        $candidate = $decoded['candidates'][0]['content']['parts'] ?? null;
        if (!is_array($candidate)) {
            throw new AIProviderException('Gemini response missing content');
        }

        $text = '';
        foreach ($candidate as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }
        }

        $text = trim($text);
        if ($text === '') {
            throw new AIProviderException('Gemini response contained no text');
        }

        return $text;
    }

    /**
     * Check whether the status code indicates a transient failure.
     */
    protected function isTransientStatus(int $statusCode): bool
    {
        return $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * Return the default Gemini model name.
     */
    protected function getDefaultModel(): string
    {
        return 'gemini-3.1-flash-lite-preview';
    }
}
