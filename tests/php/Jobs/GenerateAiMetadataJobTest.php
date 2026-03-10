<?php

namespace SilverstripeLtd\AiMetadata\Tests\Jobs;

use SilverstripeLtd\AiMetadata\Jobs\GenerateAiMetadataJob;
use SilverstripeLtd\AiMetadata\Models\GeneratedMetadata;
use SilverstripeLtd\AiMetadata\Providers\ProviderFactory;
use SilverstripeLtd\AiMetadata\Services\MetadataGenerationService;
use SilverstripeLtd\AiMetadata\ValueObjects\AiMetadataResult;
use SilverstripeLtd\AiMetadata\Tests\StubProviderFactory;
use SilverstripeLtd\AiMetadata\Tests\StubProvider;
use SilverstripeLtd\AiMetadata\Tests\BlockingJobProvider;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * Tests queued job processing for AI metadata generation.
 */
class GenerateAiMetadataJobTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        GeneratedMetadata::class,
    ];

    /**
     * Configure providers and rate limits for job tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('AI_MODULE_RATE_LIMIT_DELAY', '0');
        Environment::setEnv('AI_MODULE_JOB_REQUEUE_DELAY', '0');
        $providerFactory = new StubProviderFactory(new StubProvider(new AiMetadataResult([
            'metaDescription' => 'Generated description',
        ]), ['Fails']));
        Injector::inst()->registerService($providerFactory, ProviderFactory::class);
        Injector::inst()->registerService(
            new MetadataGenerationService(null, $providerFactory),
            MetadataGenerationService::class
        );
    }

    /**
     * Reset environment after job tests.
     */
    protected function tearDown(): void
    {
        Environment::setEnv('AI_MODULE_RATE_LIMIT_DELAY', null);
        Environment::setEnv('AI_MODULE_JOB_BATCH_SIZE', null);
        Environment::setEnv('AI_MODULE_JOB_REQUEUE_DELAY', null);
        Environment::setEnv('AI_MODULE_PROVIDER', null);
        Environment::setEnv('AI_MODULE_API_KEY', '');
        $defaultFactory = new ProviderFactory();
        Injector::inst()->registerService($defaultFactory, ProviderFactory::class);
        Injector::inst()->registerService(
            new MetadataGenerationService(null, $defaultFactory),
            MetadataGenerationService::class
        );
        parent::tearDown();
    }

    /**
     * Ensure the job processes pages and updates counters.
     */
    public function testJobProcessesPages(): void
    {
        $page1 = SiteTree::create(['Title' => 'Needs Generation', 'Content' => 'Content']);
        $page1->write();

        $page2 = SiteTree::create(['Title' => 'Up to date', 'Content' => 'Content']);
        $page2->write();
        $metadata2 = $page2->getOrCreateAiMetadata();
        $metadata2->ContentHash = md5('Up to date' . "\n\n" . 'Content');
        $metadata2->GeneratedAt = '2026-02-20 10:00:00';
        $metadata2->ReviewedAt = '2026-02-20 12:00:00';
        $metadata2->write();

        $page3 = SiteTree::create(['Title' => 'Fails', 'Content' => 'Content']);
        $page3->write();

        $job = new GenerateAiMetadataJob();
        while (!$job->jobFinished()) {
            $job->process();
        }

        $this->assertSame(3, $job->processedCount);
        $this->assertSame(1, $job->succeededCount);
        $this->assertSame(1, $job->failedCount);
        $this->assertSame(1, $job->skippedCount);

        $metadata1 = GeneratedMetadata::get()->filter('ParentID', $page1->ID)->first();
        $this->assertNotEmpty($metadata1->GeneratedAt);
    }

    /**
     * Ensure blocking provider failures abort the job.
     */
    public function testBlockingFailureAbortsJob(): void
    {
        $page = SiteTree::create(['Title' => 'Blocking', 'Content' => 'Content']);
        $page->write();

        $blockingFactory = new StubProviderFactory(new BlockingJobProvider());
        $job = new GenerateAiMetadataJob();
        $job->pagesToProcess = [$page->ID];
        $job->setGenerationService(new MetadataGenerationService(null, $blockingFactory));
        $this->expectException(\SilverstripeLtd\AiMetadata\Exceptions\AIProviderException::class);
        $job->process();
    }

    /**
     * Ensure batch size limits page selection.
     */
    public function testBatchSizeLimitsPages(): void
    {
        Environment::setEnv('AI_MODULE_JOB_BATCH_SIZE', '1');
        $page1 = SiteTree::create(['Title' => 'First', 'Content' => 'Content']);
        $page1->write();
        $page2 = SiteTree::create(['Title' => 'Second', 'Content' => 'Content']);
        $page2->write();

        $job = new GenerateAiMetadataJob();
        $this->assertCount(1, $job->pagesToProcess);
    }

    /**
     * Ensure rate limit delay defaults to six seconds and respects elapsed time.
     */
    public function testRateLimitDelayDefaultsAndCalculatesRemaining(): void
    {
        Environment::setEnv('AI_MODULE_RATE_LIMIT_DELAY', null);
        $job = new GenerateAiMetadataJob();

        $delayMethod = new \ReflectionMethod($job, 'getRateLimitDelay');
        $delayMethod->setAccessible(true);
        $this->assertSame(6, $delayMethod->invoke($job));

        $remainingMethod = new \ReflectionMethod($job, 'getRemainingRateLimitDelay');
        $remainingMethod->setAccessible(true);
        $remaining = $remainingMethod->invoke($job, 100.0, 102.0, 6);
        $this->assertEquals(4.0, $remaining);
        $this->assertSame(0.0, $remainingMethod->invoke($job, 100.0, 107.0, 6));

        Environment::setEnv('AI_MODULE_RATE_LIMIT_DELAY', '0');
    }
}
