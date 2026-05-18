<?php

namespace SilverstripeLtd\AiSeo\Tests\Services;

use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverstripeLtd\AiSeo\Services\AiSeoStateService;
use SilverstripeLtd\AiSeo\Services\ContentExtractService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;

/**
 * Covers state detection for staleness and draft changes.
 */
class AiSeoStateServiceTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        GeneratedSeo::class,
    ];

    /**
     * Ensure state flags reflect staleness and draft changes.
     */
    public function testGetStateDetectsStaleAndDraftChanges(): void
    {
        $extractor = new ContentExtractService();
        $service = new AiSeoStateService($extractor);

        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Live content']);
        $page->write();
        $page->publishSingle();

        $metadata = $page->getOrCreateAiSeo();
        $metadata->ContentHash = $extractor->computeHash($extractor->extract($page));
        $metadata->write();

        $state = $service->getState($page, $metadata);
        $this->assertFalse($state['stale']);
        $this->assertFalse($state['hasUnpublishedChanges']);
        $this->assertFalse($state['usedLive']);

        $page->Content = 'Draft content';
        $page->write();

        $state = $service->getState($page, $metadata);
        $this->assertTrue($state['stale']);
        $this->assertTrue($state['hasUnpublishedChanges']);
        $this->assertFalse($state['usedLive']);
    }
}
