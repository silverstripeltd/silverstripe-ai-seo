<?php

namespace SilverstripeLtd\AiSeo\Tests\Jobs;

use SilverstripeLtd\AiSeo\Jobs\GenerateAiSeoJob;
use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverstripeLtd\AiSeo\Providers\ProviderFactory;
use SilverstripeLtd\AiSeo\Services\SeoGenerationService;
use SilverstripeLtd\AiSeo\ValueObjects\AiSeoResult;
use SilverstripeLtd\AiSeo\Tests\StubProviderFactory;
use SilverstripeLtd\AiSeo\Tests\StubProvider;
use SilverstripeLtd\AiSeo\Tests\BlockingJobProvider;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * Tests queued job processing for AI SEO generation.
 */
class GenerateAiSeoJobTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        GeneratedSeo::class,
    ];

    /**
     * Configure providers and rate limits for job tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Environment::setEnv('AI_SEO_RATE_LIMIT_DELAY', '0');
        Environment::setEnv('AI_SEO_JOB_REQUEUE_DELAY', '0');
        $providerFactory = new StubProviderFactory(new StubProvider(new AiSeoResult([
            'metaDescription' => 'Generated description',
        ]), ['Fails']));
        Injector::inst()->registerService($providerFactory, ProviderFactory::class);
        Injector::inst()->registerService(
            new SeoGenerationService(null, $providerFactory),
            SeoGenerationService::class
        );
    }

    /**
     * Reset environment after job tests.
     */
    protected function tearDown(): void
    {
        Environment::setEnv('AI_SEO_RATE_LIMIT_DELAY', null);
        Environment::setEnv('AI_SEO_JOB_BATCH_SIZE', null);
        Environment::setEnv('AI_SEO_JOB_REQUEUE_DELAY', null);
        Environment::setEnv('AI_SEO_PROVIDER', null);
        Environment::setEnv('AI_SEO_API_KEY', '');
        $defaultFactory = new ProviderFactory();
        Injector::inst()->registerService($defaultFactory, ProviderFactory::class);
        Injector::inst()->registerService(
            new SeoGenerationService(null, $defaultFactory),
            SeoGenerationService::class
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
        $metadata2 = $page2->getOrCreateAiSeo();
        $metadata2->ContentHash = md5('Up to date' . "\n\n" . 'Content');
        $metadata2->GeneratedAt = '2026-02-20 10:00:00';
        $metadata2->ReviewedAt = '2026-02-20 12:00:00';
        $metadata2->write();

        $page3 = SiteTree::create(['Title' => 'Fails', 'Content' => 'Content']);
        $page3->write();

        $job = new GenerateAiSeoJob();
        while (!$job->jobFinished()) {
            $job->process();
        }

        $this->assertSame(3, $job->processedCount);
        $this->assertSame(1, $job->succeededCount);
        $this->assertSame(1, $job->failedCount);
        $this->assertSame(1, $job->skippedCount);

        $metadata1 = GeneratedSeo::get()->filter('ParentID', $page1->ID)->first();
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
        $job = new GenerateAiSeoJob();
        $job->pagesToProcess = [$page->ID];
        $job->setGenerationService(new SeoGenerationService(null, $blockingFactory));
        $this->expectException(\SilverstripeLtd\AiSeo\Exceptions\AIProviderException::class);
        $job->process();
    }

    /**
     * Ensure batch size limits page selection.
     */
    public function testBatchSizeLimitsPages(): void
    {
        Environment::setEnv('AI_SEO_JOB_BATCH_SIZE', '1');
        $page1 = SiteTree::create(['Title' => 'First', 'Content' => 'Content']);
        $page1->write();
        $page2 = SiteTree::create(['Title' => 'Second', 'Content' => 'Content']);
        $page2->write();

        $job = new GenerateAiSeoJob();
        $this->assertCount(1, $job->pagesToProcess);
    }

    /**
     * Ensure rate limit delay defaults to six seconds and respects elapsed time.
     */
    public function testRateLimitDelayDefaultsAndCalculatesRemaining(): void
    {
        Environment::setEnv('AI_SEO_RATE_LIMIT_DELAY', null);
        $job = new GenerateAiSeoJob();

        $delayMethod = new \ReflectionMethod($job, 'getRateLimitDelay');
        $delayMethod->setAccessible(true);
        $this->assertSame(6, $delayMethod->invoke($job));

        $remainingMethod = new \ReflectionMethod($job, 'getRemainingRateLimitDelay');
        $remainingMethod->setAccessible(true);
        $remaining = $remainingMethod->invoke($job, 100.0, 102.0, 6);
        $this->assertEquals(4.0, $remaining);
        $this->assertSame(0.0, $remainingMethod->invoke($job, 100.0, 107.0, 6));

        Environment::setEnv('AI_SEO_RATE_LIMIT_DELAY', '0');
    }
}
