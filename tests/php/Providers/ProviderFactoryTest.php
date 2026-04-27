<?php

namespace SilverstripeLtd\AiMetadata\Tests\Providers;

use SilverstripeLtd\AiMetadata\Exceptions\AIProviderException;
use SilverstripeLtd\AiMetadata\Providers\AnthropicProvider;
use SilverstripeLtd\AiMetadata\Providers\GeminiProvider;
use SilverstripeLtd\AiMetadata\Providers\OpenAIProvider;
use SilverstripeLtd\AiMetadata\Providers\ProviderFactory;
use SilverstripeLtd\AiMetadata\Tests\StubProvider;
use SilverstripeLtd\AiMetadata\ValueObjects\AiMetadataResult;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * Ensures provider resolution respects environment configuration.
 */
class ProviderFactoryTest extends SapphireTest
{
    private StubProvider $geminiProvider;
    private StubProvider $openAiProvider;
    private StubProvider $anthropicProvider;

    /**
     * Register stub providers for provider factory tests.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->geminiProvider = new StubProvider(new AiMetadataResult());
        $this->openAiProvider = new StubProvider(new AiMetadataResult());
        $this->anthropicProvider = new StubProvider(new AiMetadataResult());

        Injector::inst()->registerService($this->geminiProvider, GeminiProvider::class);
        Injector::inst()->registerService($this->openAiProvider, OpenAIProvider::class);
        Injector::inst()->registerService($this->anthropicProvider, AnthropicProvider::class);
    }

    /**
     * Reset environment after provider tests.
     */
    protected function tearDown(): void
    {
        Environment::setEnv('AI_METADATA_PROVIDER', null);
        parent::tearDown();
    }

    /**
     * Ensure the factory defaults to Gemini when env is empty.
     */
    public function testDefaultsToGeminiWhenEnvEmpty(): void
    {
        Environment::setEnv('AI_METADATA_PROVIDER', '');
        $factory = new ProviderFactory();
        $this->assertSame($this->geminiProvider, $factory->getProvider());
    }

    /**
     * Ensure the factory returns OpenAI when configured.
     */
    public function testSelectsOpenAiProvider(): void
    {
        Environment::setEnv('AI_METADATA_PROVIDER', 'openai');
        $factory = new ProviderFactory();
        $this->assertSame($this->openAiProvider, $factory->getProvider());
    }

    /**
     * Ensure unknown providers throw a provider exception.
     */
    public function testThrowsForUnknownProvider(): void
    {
        Environment::setEnv('AI_METADATA_PROVIDER', 'unknown');
        $factory = new ProviderFactory();
        $this->expectException(AIProviderException::class);
        $factory->getProvider();
    }
}
