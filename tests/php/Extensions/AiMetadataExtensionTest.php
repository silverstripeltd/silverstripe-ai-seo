<?php

namespace SilverstripeLtd\AiMetadata\Tests\Extensions;

use SilverstripeLtd\AiMetadata\Models\GeneratedMetadata;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Versioned\Versioned;

/**
 * Covers extension behaviour for AI metadata.
 */
class AiMetadataExtensionTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        GeneratedMetadata::class,
    ];

    /**
     * Ensure metadata is created and linked to the page.
     */
    public function testGetOrCreateAiMetadata(): void
    {
        $page = SiteTree::create(['Title' => 'Test page']);
        $page->write();

        $metadata = $page->getOrCreateAiMetadata();
        $this->assertTrue($metadata->exists());
        $this->assertEquals($page->ID, $metadata->ParentID);
        $this->assertEquals($page->ClassName, $metadata->ParentClass);
        $this->assertFalse($page->hasField('AiMetadataID'));
        $this->assertSame($metadata->ID, $page->getAiMetadata()->ID);
    }

    /**
     * Ensure CMS fields use the AI-managed description.
     */
    public function testUpdateCMSFieldsReplacesMetaDescription(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'MetaDescription' => 'Original description']);
        $page->write();

        $fields = $page->getCMSFields();
        $field = $fields->dataFieldByName('MetaDescription');

        $this->assertInstanceOf(ReadonlyField::class, $field);
        $this->assertStringContainsString('Previous value:', (string)$field->getDescription());
    }

    /**
     * Ensure publishing a page publishes reviewed metadata.
     */
    public function testOnAfterPublishPublishesReviewedMetadata(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiMetadata();
        $metadata->MetaDescription = 'AI description';
        $metadata->ReviewedAt = '2026-02-20 10:00:00';
        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $metadata->write();

        $page->publishSingle();

        $liveMetadata = Versioned::withVersionedMode(function () use ($metadata): ?GeneratedMetadata {
            Versioned::set_stage(Versioned::LIVE);
            return GeneratedMetadata::get()->byID($metadata->ID);
        });

        $this->assertNotNull($liveMetadata);
        $this->assertSame('AI description', $liveMetadata->MetaDescription);
    }

    /**
     * Ensure unreviewed metadata is not published with the page.
     */
    public function testOnAfterPublishSkipsUnreviewedMetadata(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiMetadata();
        $metadata->MetaDescription = 'Unreviewed description';
        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $metadata->write();

        $page->publishSingle();

        $liveMetadata = Versioned::withVersionedMode(function () use ($metadata): ?GeneratedMetadata {
            Versioned::set_stage(Versioned::LIVE);
            return GeneratedMetadata::get()->byID($metadata->ID);
        });

        $this->assertNull($liveMetadata);
    }

    /**
     * Ensure metadata with ReviewedAt before GeneratedAt is not published.
     */
    public function testOnAfterPublishSkipsOutdatedReview(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiMetadata();
        $metadata->MetaDescription = 'Stale review description';
        $metadata->ReviewedAt = '2026-02-20 09:00:00';
        $metadata->GeneratedAt = '2026-02-20 10:00:00';
        $metadata->write();

        $page->publishSingle();

        $liveMetadata = Versioned::withVersionedMode(function () use ($metadata): ?GeneratedMetadata {
            Versioned::set_stage(Versioned::LIVE);
            return GeneratedMetadata::get()->byID($metadata->ID);
        });

        $this->assertNull($liveMetadata);
    }

    /**
     * Ensure unpublishing a page removes live metadata.
     */
    public function testOnBeforeUnpublishRemovesLiveMetadata(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiMetadata();
        $metadata->ReviewedAt = '2026-02-20 10:00:00';
        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $metadata->write();

        $page->publishSingle();
        $page->doUnpublish();

        $liveMetadata = Versioned::withVersionedMode(function () use ($metadata): ?GeneratedMetadata {
            Versioned::set_stage(Versioned::LIVE);
            return GeneratedMetadata::get()->byID($metadata->ID);
        });

        $this->assertNull($liveMetadata);
    }

    /**
     * Ensure archiving a page removes live metadata.
     */
    public function testOnBeforeArchiveRemovesLiveMetadata(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiMetadata();
        $metadata->ReviewedAt = '2026-02-20 10:00:00';
        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $metadata->write();

        $page->publishSingle();
        $metadata->publishSingle();

        $page->doArchive();

        $liveMetadata = Versioned::withVersionedMode(function () use ($metadata): ?GeneratedMetadata {
            Versioned::set_stage(Versioned::LIVE);
            return GeneratedMetadata::get()->byID($metadata->ID);
        });

        $this->assertNull($liveMetadata);
    }
}
