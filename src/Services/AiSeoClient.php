<?php

namespace SilverstripeLtd\AiSeo\Services;

use Psr\Log\LoggerInterface;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverstripeLtd\AiCore\Completion\JsonCompletion;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Settings\EnvProviderSettings;
use SilverstripeLtd\AiSeo\ValueObjects\AiSeoResult;

/**
 * Generates SEO content through the shared ai-core provider layer.
 */
class AiSeoClient
{
    use Injectable;

    /**
     * Module prefix of the AI_SEO_* environment variables and the ai-core YAML settings.
     */
    public const SETTINGS_MODULE = 'SEO';

    private PromptService $promptService;

    private ?LoggerInterface $logger;

    /**
     * Configure the client with optional dependencies.
     */
    public function __construct(?PromptService $promptService = null, ?LoggerInterface $logger = null)
    {
        $this->promptService = $promptService ?: Injector::inst()->get(PromptService::class);
        $this->logger = $logger;
    }

    /**
     * Generate SEO for the supplied page content.
     *
     * @throws ProviderException
     */
    public function generateSeo(string $content, string $pageTitle, string $pageUrl): AiSeoResult
    {
        [$systemPrompt, $userPrompt] = $this->promptService->buildPrompts($content, $pageTitle, $pageUrl);
        $completion = JsonCompletion::create($this->getSettings(), null, $this->logger);
        return $this->parseSeo($completion->completeJson($systemPrompt, $userPrompt));
    }

    /**
     * Return the provider settings for this module.
     */
    public function getSettings(): EnvProviderSettings
    {
        return EnvProviderSettings::forModule(self::SETTINGS_MODULE);
    }

    /**
     * Map a decoded provider payload onto an SEO result.
     *
     * @param array<int|string, mixed> $payload
     */
    private function parseSeo(array $payload): AiSeoResult
    {
        return new AiSeoResult([
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
}
