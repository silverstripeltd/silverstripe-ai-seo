<?php

namespace SilverstripeLtd\AiSeo\Tests\Reports;

use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverstripeLtd\AiSeo\Reports\AiSeoReport;
use SilverstripeLtd\AiSeo\Services\ContentExtractService;
use SilverstripeLtd\AiSeo\Tests\RestrictedViewPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Model\List\PaginatedList;

/**
 * Tests report status calculations and filtering.
 */
class AiSeoReportTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        GeneratedSeo::class,
        RestrictedViewPage::class,
    ];

    /**
     * Log in as a CMS-capable user before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->logInWithPermission('ADMIN');
    }

    /**
     * Ensure status filters include expected records.
     */
    public function testReportStatusesAndFiltering(): void
    {
        $extractor = new ContentExtractService();

        $missing = SiteTree::create(['Title' => 'Missing']);
        $missing->write();

        $stale = SiteTree::create(['Title' => 'Stale', 'Content' => 'Content']);
        $stale->write();
        $staleMeta = $stale->getOrCreateAiSeo();
        $staleMeta->ContentHash = 'oldhash';
        $staleMeta->GeneratedAt = '2026-02-20 10:00:00';
        $staleMeta->write();

        $unreviewed = SiteTree::create(['Title' => 'Unreviewed', 'Content' => 'Content']);
        $unreviewed->write();
        $unreviewedMeta = $unreviewed->getOrCreateAiSeo();
        $unreviewedMeta->ContentHash = $extractor->computeHash($extractor->extractPublished($unreviewed)['content']);
        $unreviewedMeta->GeneratedAt = '2026-02-20 10:00:00';
        $unreviewedMeta->write();

        $ok = SiteTree::create(['Title' => 'OK', 'Content' => 'Content']);
        $ok->write();
        $okMeta = $ok->getOrCreateAiSeo();
        $okMeta->ContentHash = $extractor->computeHash($extractor->extractPublished($ok)['content']);
        $okMeta->GeneratedAt = '2026-02-20 10:00:00';
        $okMeta->ReviewedAt = '2026-02-20 11:00:00';
        $okMeta->write();

        $okPublished = SiteTree::create(['Title' => 'OK published', 'Content' => 'Content']);
        $okPublished->write();
        $okPublishedMeta = $okPublished->getOrCreateAiSeo();
        $okPublishedMeta->ContentHash = $extractor->computeHash($extractor->extractPublished($okPublished)['content']);
        $okPublishedMeta->GeneratedAt = '2026-02-20 10:00:00';
        $okPublishedMeta->ReviewedAt = '2026-02-20 11:00:00';
        $okPublishedMeta->write();
        $okPublishedMeta->publishSingle();

        // ReviewedAt before GeneratedAt — should count as unreviewed
        $unreviewedStale = SiteTree::create(['Title' => 'Unreviewed stale', 'Content' => 'Content']);
        $unreviewedStale->write();
        $unreviewedStaleMeta = $unreviewedStale->getOrCreateAiSeo();
        $extracted = $extractor->extractPublished($unreviewedStale);
        $unreviewedStaleMeta->ContentHash = $extractor->computeHash($extracted['content']);
        $unreviewedStaleMeta->GeneratedAt = '2026-02-20 12:00:00';
        $unreviewedStaleMeta->ReviewedAt = '2026-02-20 11:00:00';
        $unreviewedStaleMeta->write();

        $report = new AiSeoReport();
        $needsAttention = $report->sourceRecords(['Status' => 'needs_attention']);
        $this->assertInstanceOf(PaginatedList::class, $needsAttention);
        $this->assertCount(5, $needsAttention);

        $okOnly = $report->sourceRecords(['Status' => 'ok']);
        $this->assertCount(1, $okOnly);

        $notPublishedOnly = $report->sourceRecords(['Status' => 'not_published']);
        $this->assertCount(5, $notPublishedOnly);

        $unreviewedOnly = $report->sourceRecords(['Status' => 'unreviewed']);
        $this->assertCount(2, $unreviewedOnly);
    }

    /**
     * Ensure live status column shows correct values for different versioned states.
     */
    public function testReportLiveStatusColumn(): void
    {
        $extractor = new ContentExtractService();

        // Draft-only metadata: Live status = "Not published"
        $draftPage = SiteTree::create(['Title' => 'Draft metadata', 'Content' => 'Content']);
        $draftPage->write();
        $draftMeta = $draftPage->getOrCreateAiSeo();
        $draftMeta->ContentHash = $extractor->computeHash($extractor->extractPublished($draftPage)['content']);
        $draftMeta->GeneratedAt = '2026-02-20 10:00:00';
        $draftMeta->ReviewedAt = '2026-02-20 11:00:00';
        $draftMeta->write();

        // Published metadata: Live status = "Published"
        $publishedPage = SiteTree::create(['Title' => 'Published metadata', 'Content' => 'Content']);
        $publishedPage->write();
        $publishedMeta = $publishedPage->getOrCreateAiSeo();
        $publishedMeta->ContentHash = $extractor->computeHash($extractor->extractPublished($publishedPage)['content']);
        $publishedMeta->GeneratedAt = '2026-02-20 10:00:00';
        $publishedMeta->ReviewedAt = '2026-02-20 11:00:00';
        $publishedMeta->write();
        $publishedMeta->publishSingle();

        // Outdated metadata: published then modified on draft
        $outdatedPage = SiteTree::create(['Title' => 'Outdated metadata', 'Content' => 'Content']);
        $outdatedPage->write();
        $outdatedMeta = $outdatedPage->getOrCreateAiSeo();
        $outdatedMeta->ContentHash = $extractor->computeHash($extractor->extractPublished($outdatedPage)['content']);
        $outdatedMeta->GeneratedAt = '2026-02-20 10:00:00';
        $outdatedMeta->ReviewedAt = '2026-02-20 11:00:00';
        $outdatedMeta->write();
        $outdatedMeta->publishSingle();
        $outdatedMeta->MetaDescription = 'Updated draft description';
        $outdatedMeta->write();

        // Missing metadata: Live status = "Not published"
        $missingPage = SiteTree::create(['Title' => 'No metadata']);
        $missingPage->write();

        $report = new AiSeoReport();
        $all = $report->sourceRecords(['Status' => 'all']);

        $liveStatuses = [];
        foreach ($all as $record) {
            $liveStatuses[$record->Title] = $record->LiveStatus;
        }

        $this->assertSame('Not published', $liveStatuses['Draft metadata']);
        $this->assertSame('Published', $liveStatuses['Published metadata']);
        $this->assertSame('Outdated', $liveStatuses['Outdated metadata']);
        $this->assertSame('Not published', $liveStatuses['No metadata']);
    }

    /**
     * Ensure the report columns include LiveStatus.
     */
    public function testReportColumnsIncludeLiveStatus(): void
    {
        $report = new AiSeoReport();
        $columns = $report->columns();
        $this->assertArrayHasKey('LiveStatus', $columns);
        $this->assertSame('Live status', $columns['LiveStatus']['title']);
    }

    /**
     * Ensure the report excludes pages the current user cannot view.
     */
    public function testReportExcludesPagesCurrentUserCannotView(): void
    {
        $visiblePage = SiteTree::create(['Title' => 'Visible page', 'Content' => 'Content']);
        $visiblePage->write();

        $hiddenPage = RestrictedViewPage::create(['Title' => 'Hidden page', 'Content' => 'Content']);
        $hiddenPage->write();

        $report = new AiSeoReport();
        $records = $report->sourceRecords(['Status' => 'all']);

        $titles = [];
        foreach ($records as $record) {
            $titles[] = $record->Title;
        }

        $this->assertContains('Visible page', $titles);
        $this->assertNotContains('Hidden page', $titles);
    }
}
