<?php

namespace SilverstripeLtd\AiSeo\Tests;

use SilverStripe\Core\Injector\Injector;
use SilverstripeLtd\AiCore\Provider\Message\ChatRequest;
use SilverstripeLtd\AiCore\Provider\Message\ChatResponse;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Provider\ProviderFactory;
use SilverstripeLtd\AiCore\Testing\ScriptedProvider;
use SilverstripeLtd\AiCore\Testing\StubProviderFactory;

/**
 * Registers an ai-core scripted provider that answers every SEO request with a fixed payload.
 */
class SeoProviderStub
{
    /**
     * Number of replies queued, enough for any test in this suite.
     */
    public const REPLIES = 20;

    /**
     * Register a scripted provider replying with the payload as JSON, failing for chosen page titles.
     *
     * @param array<string, mixed> $payload
     * @param array<int, string> $failureTitles
     */
    public static function register(
        array $payload,
        array $failureTitles = [],
        ?ProviderException $failure = null
    ): ScriptedProvider {
        $reply = static function (ChatRequest $request) use ($payload, $failureTitles, $failure): ChatResponse {
            if ($failure) {
                throw $failure;
            }
            foreach ($failureTitles as $title) {
                if (str_contains($request->getLastUserText(), 'Page title: ' . $title . "\n")) {
                    throw new ProviderException('Boom');
                }
            }
            return ScriptedProvider::text((string)json_encode($payload));
        };
        $provider = new ScriptedProvider(array_fill(0, self::REPLIES, $reply));
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);
        return $provider;
    }

    /**
     * Register a scripted provider whose every request fails with the given exception.
     */
    public static function registerFailure(ProviderException $failure): ScriptedProvider
    {
        return self::register([], [], $failure);
    }

    /**
     * Restore the real provider factory.
     */
    public static function unregister(): void
    {
        Injector::inst()->unregisterNamedObject(ProviderFactory::class);
    }
}
