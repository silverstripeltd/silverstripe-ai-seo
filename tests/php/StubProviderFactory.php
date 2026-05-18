<?php

namespace SilverstripeLtd\AiSeo\Tests;

use SilverstripeLtd\AiSeo\Providers\AbstractAIProvider;
use SilverstripeLtd\AiSeo\Providers\ProviderFactory;

/**
 * Provider factory that always returns the supplied provider.
 */
class StubProviderFactory extends ProviderFactory
{
    private AbstractAIProvider $provider;

    /**
     * Create the factory wrapper.
     */
    public function __construct(AbstractAIProvider $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Return the configured provider.
     */
    public function getProvider(): AbstractAIProvider
    {
        return $this->provider;
    }
}
