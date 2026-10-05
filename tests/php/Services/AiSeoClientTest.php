<?php

namespace SilverstripeLtd\AiSeo\Tests\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverstripeLtd\AiCore\Provider\Anthropic\AnthropicProvider;
use SilverstripeLtd\AiCore\Provider\Gemini\GeminiProvider;
use SilverstripeLtd\AiCore\Provider\OpenAI\OpenAIProvider;
use SilverstripeLtd\AiCore\Provider\ProviderException;
use SilverstripeLtd\AiCore\Provider\ProviderFactory;
use SilverstripeLtd\AiCore\Testing\ScriptedProvider;
use SilverstripeLtd\AiCore\Testing\StubProviderFactory;
use SilverstripeLtd\AiSeo\Services\AiSeoClient;

/**
 * Tests SEO generation through the ai-core provider layer and the module provider defaults.
 */
class AiSeoClientTest extends SapphireTest
{
    private const ENV_NAMES = [
        'PROVIDER',
        'API_KEY',
        'MODEL',
        'MAX_TOKENS',
        'REQUEST_TIMEOUT',
        'TEMPERATURE',
        'THINKING_LEVEL',
    ];

    protected $usesDatabase = false;

    /**
     * Clear module and shared provider environment variables.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearEnvironment();
    }

    /**
     * Clear environment variables and restore the real provider factory.
     */
    protected function tearDown(): void
    {
        $this->clearEnvironment();
        Injector::inst()->unregisterNamedObject(ProviderFactory::class);
        parent::tearDown();
    }

    /**
     * Ensure JSON responses parse into SEO result objects.
     */
    public function testParsesJsonResponse(): void
    {
        $provider = $this->registerProvider(ScriptedProvider::text('{"metaDescription":"Desc","ogTitle":"Title"}'));
        $result = AiSeoClient::create()->generateSeo('content', 'Page name', 'https://example.com/page');
        $this->assertSame('Desc', $result->metaDescription);
        $this->assertSame('Title', $result->ogTitle);
        $request = $provider->getLastRequest();
        $this->assertStringContainsString('Page title: Page name', $request->getLastUserText());
        $this->assertStringContainsString('https://example.com/page', $request->getLastUserText());
        $this->assertNotSame('', $request->system);
    }

    /**
     * Ensure JSON wrapped in prose or code fences is recovered.
     */
    public function testRecoversEmbeddedJson(): void
    {
        $reply = "```json\n{\"metaDescription\":\"Desc\",\"keyTopics\":\"a, b\"}\n```";
        $this->registerProvider(ScriptedProvider::text($reply));
        $result = AiSeoClient::create()->generateSeo('content', 'title', 'url');
        $this->assertSame('Desc', $result->metaDescription);
        $this->assertSame('a, b', $result->keyTopics);
    }

    /**
     * Ensure fields with the wrong type are dropped.
     */
    public function testIgnoresFieldsWithTheWrongType(): void
    {
        $this->registerProvider(ScriptedProvider::text(
            '{"keyEntities":"none","keyTopics":["a"],"suggestedFAQs":[{"question":"Q","answer":"A"}]}'
        ));
        $result = AiSeoClient::create()->generateSeo('content', 'title', 'url');
        $this->assertNull($result->keyEntities);
        $this->assertNull($result->keyTopics);
        $this->assertSame([['question' => 'Q', 'answer' => 'A']], $result->suggestedFAQs);
    }

    /**
     * Ensure malformed responses throw non-transient provider exceptions.
     */
    public function testMalformedResponseThrows(): void
    {
        $this->registerProvider(ScriptedProvider::text('not-json'));
        try {
            AiSeoClient::create()->generateSeo('content', 'title', 'url');
            $this->fail('Expected provider exception to be thrown.');
        } catch (ProviderException $exception) {
            $this->assertFalse($exception->isTransient());
            $this->assertFalse($exception->isBlocking());
        }
    }

    /**
     * Ensure transient failures are not retried.
     */
    public function testTransientFailureDoesNotRetry(): void
    {
        $provider = $this->registerProvider(
            static function (): never {
                throw ProviderException::transient('Fail', 500);
            },
            ScriptedProvider::text('{"metaDescription":"Desc"}')
        );
        try {
            AiSeoClient::create()->generateSeo('content', 'title', 'url');
            $this->fail('Expected provider exception to be thrown.');
        } catch (ProviderException $exception) {
            $this->assertTrue($exception->isTransient());
            $this->assertCount(1, $provider->getRequests());
            $this->assertSame(1, $provider->getRemainingCount());
        }
    }

    /**
     * Ensure the module defaults apply when no environment variable is set.
     */
    public function testModuleDefaultsWhenEnvMissing(): void
    {
        $settings = AiSeoClient::create()->getSettings();
        $this->assertSame('gemini', $settings->getProviderName());
        $this->assertSame('gemini-3.1-flash-lite', $settings->getModel());
        $this->assertSame(2000, $settings->getMaxTokens());
        $this->assertSame(15, $settings->getTimeoutSeconds());
        $this->assertSame(1.0, $settings->getTemperature());
        $this->assertSame('low', $settings->getThinkingLevel());
    }

    /**
     * Provide each provider with its default model and thinking level.
     *
     * @return array<string, array{string, string, ?string}>
     */
    public static function provideProviderDefaults(): array
    {
        return [
            'anthropic' => ['anthropic', 'claude-haiku-4-5', null],
            'openai' => ['openai', 'gpt-5-mini', null],
            'gemini' => ['gemini', 'gemini-3.1-flash-lite', 'low'],
        ];
    }

    /**
     * Ensure each provider gets its own default model and only Gemini a thinking level.
     */
    #[DataProvider('provideProviderDefaults')]
    public function testProviderDefaults(string $provider, string $model, ?string $thinkingLevel): void
    {
        Environment::setEnv('AI_SEO_PROVIDER', $provider);
        $settings = AiSeoClient::create()->getSettings();
        $this->assertSame($model, $settings->getModel());
        $this->assertSame($thinkingLevel, $settings->getThinkingLevel());
    }

    /**
     * Ensure AI_SEO_* environment variables override the module defaults.
     */
    public function testEnvironmentOverridesDefaults(): void
    {
        Environment::setEnv('AI_SEO_PROVIDER', 'openai');
        Environment::setEnv('AI_SEO_API_KEY', 'seo-key');
        Environment::setEnv('AI_SEO_MODEL', 'custom-model');
        Environment::setEnv('AI_SEO_MAX_TOKENS', '500');
        Environment::setEnv('AI_SEO_REQUEST_TIMEOUT', '12');
        Environment::setEnv('AI_SEO_TEMPERATURE', '0');
        Environment::setEnv('AI_SEO_THINKING_LEVEL', 'none');
        $settings = AiSeoClient::create()->getSettings();
        $this->assertSame('openai', $settings->getProviderName());
        $this->assertSame('seo-key', $settings->getApiKey());
        $this->assertSame('custom-model', $settings->getModel());
        $this->assertSame(500, $settings->getMaxTokens());
        $this->assertSame(12, $settings->getTimeoutSeconds());
        $this->assertSame(0.0, $settings->getTemperature());
        $this->assertSame('none', $settings->getThinkingLevel());
    }

    /**
     * Ensure the shared AI_* variables are used when no AI_SEO_* variable is set.
     */
    public function testSharedEnvironmentIsTheFallback(): void
    {
        Environment::setEnv('AI_PROVIDER', 'anthropic');
        Environment::setEnv('AI_API_KEY', 'shared-key');
        Environment::setEnv('AI_REQUEST_TIMEOUT', '40');
        $settings = AiSeoClient::create()->getSettings();
        $this->assertSame('anthropic', $settings->getProviderName());
        $this->assertSame('shared-key', $settings->getApiKey());
        $this->assertSame('claude-haiku-4-5', $settings->getModel());
        $this->assertSame(40, $settings->getTimeoutSeconds());
        Environment::setEnv('AI_SEO_API_KEY', 'seo-key');
        $this->assertSame('seo-key', $settings->getApiKey());
    }

    /**
     * Provide provider names and the ai-core provider class each resolves to.
     *
     * @return array<string, array{string, string}>
     */
    public static function provideProviderClasses(): array
    {
        return [
            'default' => ['', GeminiProvider::class],
            'openai' => ['openai', OpenAIProvider::class],
            'anthropic' => ['anthropic', AnthropicProvider::class],
        ];
    }

    /**
     * Ensure the configured provider resolves to the matching ai-core provider.
     */
    #[DataProvider('provideProviderClasses')]
    public function testProviderSelection(string $provider, string $class): void
    {
        Environment::setEnv('AI_SEO_PROVIDER', $provider);
        $resolved = ProviderFactory::singleton()->forSettings(AiSeoClient::create()->getSettings());
        $this->assertInstanceOf($class, $resolved);
    }

    /**
     * Ensure unknown providers throw a blocking provider exception.
     */
    public function testThrowsForUnknownProvider(): void
    {
        Environment::setEnv('AI_SEO_PROVIDER', 'unknown');
        try {
            AiSeoClient::create()->generateSeo('content', 'title', 'url');
            $this->fail('Expected provider exception to be thrown.');
        } catch (ProviderException $exception) {
            $this->assertTrue($exception->isBlocking());
        }
    }

    /**
     * Ensure a missing API key is a blocking failure naming the module variable.
     */
    public function testMissingApiKeyIsBlocking(): void
    {
        try {
            AiSeoClient::create()->generateSeo('content', 'title', 'url');
            $this->fail('Expected provider exception to be thrown.');
        } catch (ProviderException $exception) {
            $this->assertTrue($exception->isBlocking());
            $this->assertStringContainsString('AI_SEO_API_KEY', $exception->getMessage());
        }
    }

    /**
     * Register a scripted provider with the given replies.
     */
    private function registerProvider(mixed ...$replies): ScriptedProvider
    {
        $provider = new ScriptedProvider($replies);
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);
        return $provider;
    }

    /**
     * Unset every module and shared provider environment variable.
     */
    private function clearEnvironment(): void
    {
        foreach (self::ENV_NAMES as $name) {
            Environment::setEnv('AI_SEO_' . $name, null);
            Environment::setEnv('AI_' . $name, null);
        }
    }
}
