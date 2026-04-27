<?php

namespace SilverstripeLtd\AiMetadata\Tests\Providers;

use SilverstripeLtd\AiMetadata\Exceptions\AIProviderException;
use SilverstripeLtd\AiMetadata\Tests\TestAIProvider;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;

/**
 * Tests shared provider parsing and configuration logic.
 */
class AbstractAIProviderTest extends SapphireTest
{
    /**
     * Configure environment for provider tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('AI_METADATA_API_KEY', 'test-key');
    }

    /**
     * Reset environment after tests.
     */
    protected function tearDown(): void
    {
        Environment::setEnv('AI_METADATA_API_KEY', null);
        Environment::setEnv('AI_METADATA_REQUEST_TIMEOUT', null);
        Environment::setEnv('AI_METADATA_THINKING_LEVEL', null);
        Environment::setEnv('AI_METADATA_TEMPERATURE', null);
        parent::tearDown();
    }

    /**
     * Ensure JSON responses parse into metadata objects.
     */
    public function testParsesJsonResponse(): void
    {
        $provider = new TestAIProvider([
            ['status' => 200, 'body' => '{"metaDescription":"Desc","ogTitle":"Title"}'],
        ]);

        $result = $provider->generateMetadata('content', 'title', 'url');
        $this->assertSame('Desc', $result->metaDescription);
        $this->assertSame('Title', $result->ogTitle);
    }

    /**
     * Ensure malformed responses throw provider exceptions.
     */
    public function testMalformedResponseThrows(): void
    {
        $provider = new TestAIProvider([
            ['status' => 200, 'body' => 'not-json'],
        ]);

        $this->expectException(AIProviderException::class);
        $provider->generateMetadata('content', 'title', 'url');
    }

    /**
     * Ensure timeouts resolve from environment.
     */
    public function testTimeoutUsesEnv(): void
    {
        Environment::setEnv('AI_METADATA_REQUEST_TIMEOUT', '12');
        $provider = new TestAIProvider([
            ['status' => 200, 'body' => '{"metaDescription":"Desc"}'],
        ]);

        $this->assertSame(12, $provider->getResolvedTimeout());
    }

    /**
     * Ensure transient failures are not retried.
     */
    public function testTransientFailureDoesNotRetry(): void
    {
        $provider = new TestAIProvider([
            ['status' => 500, 'body' => '{"error":{"message":"Fail"}}'],
            ['status' => 200, 'body' => '{"metaDescription":"Desc"}'],
        ]);

        try {
            $provider->generateMetadata('content', 'title', 'url');
            $this->fail('Expected provider exception to be thrown.');
        } catch (AIProviderException $exception) {
            $this->assertSame(1, $provider->callCount);
        }
    }

    /**
     * Ensure temperature defaults when no environment value is set.
     */
    public function testTemperatureDefaultsWhenEnvMissing(): void
    {
        Environment::setEnv('AI_METADATA_TEMPERATURE', null);
        $provider = new TestAIProvider([
            ['status' => 200, 'body' => '{"metaDescription":"Desc"}'],
        ]);

        $this->assertSame(1.0, $provider->getResolvedTemperature());
    }

    /**
     * Ensure temperature resolves to zero when explicitly configured.
     */
    public function testTemperatureSupportsZero(): void
    {
        Environment::setEnv('AI_METADATA_TEMPERATURE', '0');
        $provider = new TestAIProvider([
            ['status' => 200, 'body' => '{"metaDescription":"Desc"}'],
        ]);

        $this->assertSame(0.0, $provider->getResolvedTemperature());
    }

    /**
     * Ensure thinking defaults to none when not configured.
     */
    public function testThinkingLevelDefaultsWhenEnvMissing(): void
    {
        Environment::setEnv('AI_METADATA_THINKING_LEVEL', null);
        $provider = new TestAIProvider([
            ['status' => 200, 'body' => '{"metaDescription":"Desc"}'],
        ]);

        $this->assertSame('low', $provider->getResolvedThinkingLevel());
    }
}
