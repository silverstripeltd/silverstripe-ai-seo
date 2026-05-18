<?php

namespace SilverstripeLtd\AiSeo\Jobs;

use SilverstripeLtd\AiSeo\Exceptions\AIProviderException;
use SilverstripeLtd\AiSeo\Services\AiSeoStateService;
use SilverstripeLtd\AiSeo\Services\SeoGenerationService;
use Psr\Log\LoggerInterface;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

/**
 * Queued job that generates AI SEO for SiteTree records.
 */
class GenerateAiSeoJob extends AbstractQueuedJob
{
    private const DEFAULT_BATCH_SIZE = 50;
    private const DEFAULT_RATE_LIMIT_DELAY = 6;
    private const DEFAULT_REQUEUE_DELAY = 28800;
    private const ENV_BATCH_SIZE = 'AI_SEO_JOB_BATCH_SIZE';
    private const ENV_RATE_LIMIT_DELAY = 'AI_SEO_RATE_LIMIT_DELAY';
    private const ENV_REQUEUE_DELAY = 'AI_SEO_JOB_REQUEUE_DELAY';

    public array $pagesToProcess = [];
    public int $processedCount = 0;
    public int $succeededCount = 0;
    public int $failedCount = 0;
    public int $skippedCount = 0;
    private ?SeoGenerationService $generationService = null;

    /**
     * Build the job and pre-load the list of pages to process.
     */
    public function __construct()
    {
        $pages = SiteTree::get()->sort('ID');
        $batchSize = $this->getBatchSize();
        if ($batchSize > 0) {
            $pages = $pages->limit($batchSize);
        }
        $this->pagesToProcess = $pages->column('ID');
        $this->totalSteps = count($this->pagesToProcess ?? []);
        $this->currentStep = 0;
    }

    /**
     * Return the queued job type.
     */
    public function getJobType(): string
    {
        return QueuedJob::QUEUED;
    }

    /**
     * Return the display title for the job.
     */
    public function getTitle(): string
    {
        return 'Generate AI SEO';
    }

    /**
     * Inject a custom metadata generation service.
     */
    public function setGenerationService(SeoGenerationService $service): void
    {
        $this->generationService = $service;
    }

    /**
     * Process the next page in the queue.
     */
    public function process(): void
    {
        $remaining = $this->pagesToProcess;
        if (!count($remaining ?? [])) {
            $this->finishJob();
            return;
        }

        $pageId = array_shift($remaining);
        $this->pagesToProcess = $remaining;
        $this->currentStep++;
        $this->processedCount++;

        $logger = Injector::inst()->get(LoggerInterface::class);
        $page = SiteTree::get()->byID($pageId);
        if (!$page) {
            $this->failedCount++;
            $logger->warning(sprintf('AI SEO job: page %d not found', $pageId));
            return;
        }

        $metadata = $page->getAiSeo();
        $needsGeneration = !$metadata || !$metadata->exists() || !$metadata->GeneratedAt;
        if (!$needsGeneration && $metadata->ContentHash) {
            $stateService = Injector::inst()->get(AiSeoStateService::class);
            $state = $stateService->getState($page, $metadata);
            if ($state['stale']) {
                $needsGeneration = true;
            }
        }

        if (!$needsGeneration) {
            $this->skippedCount++;
            return;
        }

        $rateLimitDelay = $this->getRateLimitDelay();
        $requestStart = $rateLimitDelay > 0 ? microtime(true) : null;
        $service = $this->generationService ?: Injector::inst()->get(SeoGenerationService::class);
        try {
            $metadata = $service->generateForRecord($page);
            if ($metadata->GenerationNote) {
                $this->skippedCount++;
            } else {
                $this->succeededCount++;
            }
            $logger->info(sprintf('AI SEO job: processed page ID %d (%s)', $page->ID, $page->Title));
        } catch (AIProviderException $exception) {
            $this->failedCount++;
            $logger->error(sprintf(
                'AI SEO job: failed page %d (%s): %s',
                $page->ID,
                $page->Title,
                $exception->getMessage()
            ));
            if ($exception->isBlocking()) {
                $this->addMessage(
                    'Blocking failure encountered, job will be requeued.',
                    'ERROR'
                );
                $this->reenqueue();
                throw $exception;
            }
        }

        if ($rateLimitDelay > 0 && $requestStart !== null && count($remaining ?? []) > 0) {
            $remainingDelay = $this->getRemainingRateLimitDelay($requestStart, microtime(true), $rateLimitDelay);
            if ($remainingDelay > 0) {
                usleep((int)round($remainingDelay * 1000000));
            }
        }

        if (!count($remaining ?? [])) {
            $this->finishJob();
        }
    }

    /**
     * Mark the job as complete and enqueue the next run.
     */
    private function finishJob(): void
    {
        $logger = Injector::inst()->get(LoggerInterface::class);
        $logger->info(sprintf(
            'AI SEO job completed: processed %d, succeeded %d, failed %d, skipped %d',
            $this->processedCount,
            $this->succeededCount,
            $this->failedCount,
            $this->skippedCount
        ));

        $this->reenqueue();
        $this->isComplete = true;
    }

    /**
     * Schedule the next regeneration job.
     */
    private function reenqueue(): void
    {
        $delay = $this->getRequeueDelay();

        $next = DBDatetime::create()->setValue(DBDatetime::now()->getTimestamp() + $delay)->Rfc2822();
        QueuedJobService::singleton()->queueJob(Injector::inst()->create(GenerateAiSeoJob::class), $next);
    }

    /**
     * Resolve the maximum number of pages to process in one batch.
     */
    private function getBatchSize(): int
    {
        $batchSize = $this->getEnvInt(
            GenerateAiSeoJob::ENV_BATCH_SIZE,
            GenerateAiSeoJob::DEFAULT_BATCH_SIZE
        );
        return $batchSize > 0 ? $batchSize : GenerateAiSeoJob::DEFAULT_BATCH_SIZE;
    }

    /**
     * Resolve the minimum delay between provider request starts in seconds.
     */
    private function getRateLimitDelay(): int
    {
        $delay = $this->getEnvInt(
            GenerateAiSeoJob::ENV_RATE_LIMIT_DELAY,
            GenerateAiSeoJob::DEFAULT_RATE_LIMIT_DELAY
        );
        return $delay > 0 ? $delay : 0;
    }

    /**
     * Resolve the delay before re-queueing the next job run.
     */
    private function getRequeueDelay(): int
    {
        $delay = $this->getEnvInt(
            GenerateAiSeoJob::ENV_REQUEUE_DELAY,
            GenerateAiSeoJob::DEFAULT_REQUEUE_DELAY
        );
        return $delay > 0 ? $delay : 0;
    }

    /**
     * Resolve an integer environment override with a default fallback.
     */
    private function getEnvInt(string $name, int $default): int
    {
        if (!Environment::hasEnv($name)) {
            return $default;
        }

        $env = Environment::getEnv($name);
        if ($env === null || $env === '' || $env === false) {
            return $default;
        }
        return (int)$env;
    }

    /**
     * Calculate remaining delay to enforce a minimum interval between request starts.
     */
    private function getRemainingRateLimitDelay(float $requestStartTime, float $now, int $minDelay): float
    {
        $elapsed = $now - $requestStartTime;
        if ($elapsed >= $minDelay) {
            return 0.0;
        }
        return $minDelay - $elapsed;
    }
}
