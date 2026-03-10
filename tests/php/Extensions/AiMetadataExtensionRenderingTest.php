<?php

namespace SilverstripeLtd\AiMetadata\Tests\Extensions;

use SilverstripeLtd\AiMetadata\Models\GeneratedMetadata;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\View\Requirements;

/**
 * Tests metadata rendering and meta tag output.
 */
class AiMetadataExtensionRenderingTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        GeneratedMetadata::class,
    ];

    /**
     * Ensure the meta description falls back when AI metadata is empty.
     */
    public function testGetMetaDescriptionFallback(): void
    {
        $page = SiteTree::create([
            'Title' => 'Test page',
            'MetaDescription' => 'Original description',
        ]);
        $page->write();

        $metadata = $page->getOrCreateAiMetadata();
        $metadata->MetaDescription = 'AI description';
        $metadata->write();

        $this->assertSame('AI description', $page->getMetaDescription());

        $metadata->MetaDescription = '';
        $metadata->write();

        $page = SiteTree::get()->byID($page->ID);
        $this->assertSame('Original description', $page->getMetaDescription());
    }

    /**
     * Ensure OG tags are emitted.
     */
    public function testMetaTagsIncludeOgTags(): void
    {
        $page = SiteTree::create(['Title' => 'Test page']);
        $page->write();

        $metadata = $page->getOrCreateAiMetadata();
        $metadata->OGTitle = 'OG Title';
        $metadata->OGDescription = 'OG Description';
        $metadata->write();

        $tags = $page->MetaTags(false);
        $this->assertStringContainsString('og:title', $tags);
        $this->assertStringContainsString('og:description', $tags);
    }

    /**
     * Ensure JSON-LD is injected into the head when metadata is generated.
     */
    public function testContentControllerInitInjectsJsonLd(): void
    {
        Requirements::clear();

        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();
        $page->publishSingle();

        $metadata = $page->getOrCreateAiMetadata();
        $metadata->SummaryLong = 'Summary';
        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $metadata->ReviewedAt = '2026-02-20 10:00:00';
        $metadata->write();
        $metadata->publishSingle();

        $controller = ContentController::create($page);
        $page->contentcontrollerInit($controller);

        $headTags = Requirements::backend()->getCustomHeadTags();
        $combined = implode("\n", $headTags);
        $this->assertStringContainsString('application/ld+json', $combined);
        $this->assertStringContainsString('@context', $combined);
    }
}
