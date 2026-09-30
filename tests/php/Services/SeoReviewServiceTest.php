<?php

namespace SilverstripeLtd\AiSeo\Tests\Services;

use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverstripeLtd\AiSeo\Services\SeoReviewService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * Covers approval of generated SEO metadata.
 */
class SeoReviewServiceTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        GeneratedSeo::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        DBDatetime::set_mock_now('2026-02-20 10:00:00');
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    /**
     * Ensure approval stamps the review time at or after generation.
     */
    public function testApproveMarksMetadataReviewed(): void
    {
        $page = $this->createPage();
        $metadata = $page->getOrCreateAiSeo();
        $metadata->MetaDescription = 'AI description';
        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $metadata->write();
        $this->assertEmpty($metadata->ReviewedAt);
        $this->assertFalse($metadata->isReviewed());
        $approved = $this->getService()->approve($metadata, $this->getMember());
        $this->assertSame('2026-02-20 10:00:00', $approved->ReviewedAt);
        $this->assertTrue($approved->isReviewed());
        $stored = GeneratedSeo::get()->byID($metadata->ID);
        $this->assertSame('2026-02-20 10:00:00', $stored->ReviewedAt);
        $this->assertTrue($stored->isReviewed());
    }

    /**
     * Ensure approval writes the draft stage without publishing.
     */
    public function testApproveWritesDraftOnly(): void
    {
        $page = $this->createPage();
        $metadata = $page->getOrCreateAiSeo();
        $metadata->MetaDescription = 'AI description';
        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $metadata->write();
        $this->getService()->approve($metadata, $this->getMember());
        $this->assertNull($this->getLiveMetadata($metadata));
        $this->assertFalse($metadata->isPublished());
    }

    /**
     * Ensure a regenerated record can be approved again after an earlier review.
     */
    public function testApproveRefreshesOutdatedReview(): void
    {
        $page = $this->createPage();
        $metadata = $page->getOrCreateAiSeo();
        $metadata->MetaDescription = 'Regenerated description';
        $metadata->ReviewedAt = '2026-02-20 08:00:00';
        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $metadata->write();
        $this->assertFalse($metadata->isReviewed());
        $approved = $this->getService()->approve($metadata, $this->getMember());
        $this->assertSame('2026-02-20 10:00:00', $approved->ReviewedAt);
        $this->assertTrue($approved->isReviewed());
    }

    /**
     * Ensure approval fills a missing content hash from the parent record.
     */
    public function testApproveFillsMissingContentHash(): void
    {
        $page = $this->createPage();
        $metadata = $page->getOrCreateAiSeo();
        $this->assertEmpty($metadata->ContentHash);
        $approved = $this->getService()->approve($metadata, $this->getMember());
        $this->assertNotEmpty($approved->ContentHash);
    }

    /**
     * Ensure approval keeps a content hash that is already set.
     */
    public function testApproveKeepsExistingContentHash(): void
    {
        $page = $this->createPage();
        $metadata = $page->getOrCreateAiSeo();
        $metadata->ContentHash = 'existinghash';
        $metadata->write();
        $approved = $this->getService()->approve($metadata, $this->getMember());
        $this->assertSame('existinghash', $approved->ContentHash);
    }

    /**
     * Ensure approved metadata publishes with its parent record.
     */
    public function testApprovedMetadataPublishesWithParent(): void
    {
        $page = $this->createPage();
        $metadata = $page->getOrCreateAiSeo();
        $metadata->MetaDescription = 'AI description';
        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $metadata->write();
        $page->publishSingle();
        $this->assertNull($this->getLiveMetadata($metadata));
        $this->getService()->approve($metadata, $this->getMember());
        $page->publishSingle();
        $liveMetadata = $this->getLiveMetadata($metadata);
        $this->assertNotNull($liveMetadata);
        $this->assertSame('AI description', $liveMetadata->MetaDescription);
    }

    /**
     * Create a page with content for the metadata to belong to.
     */
    private function createPage(): SiteTree
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();
        return $page;
    }

    /**
     * Create a member acting as the reviewer.
     */
    private function getMember(): Member
    {
        $member = Member::get()->filter('Email', 'reviewer@example.com')->first();
        if ($member) {
            return $member;
        }
        $member = Member::create(['Email' => 'reviewer@example.com']);
        $member->write();
        return $member;
    }

    /**
     * Fetch the live copy of the metadata if it has been published.
     */
    private function getLiveMetadata(GeneratedSeo $metadata): ?GeneratedSeo
    {
        return Versioned::withVersionedMode(function () use ($metadata): ?GeneratedSeo {
            Versioned::set_stage(Versioned::LIVE);
            return GeneratedSeo::get()->byID($metadata->ID);
        });
    }

    private function getService(): SeoReviewService
    {
        return Injector::inst()->get(SeoReviewService::class);
    }
}
