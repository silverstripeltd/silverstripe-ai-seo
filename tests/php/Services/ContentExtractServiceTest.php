<?php

namespace SilverstripeLtd\AiMetadata\Tests\Services;

use DNADesign\Elemental\Models\ElementContent;
use DNADesign\Elemental\Models\ElementalArea;
use SilverstripeLtd\AiMetadata\Models\AiBlocksPage;
use SilverstripeLtd\AiMetadata\Services\ContentExtractService;
use SilverstripeLtd\AiMetadata\Tests\ContentExtractServiceTestRecord;
use SilverstripeLtd\AiMetadata\Tests\ContentExtractServiceDraftDiffPage;
use SilverstripeLtd\AiMetadata\Tests\ContentExtractServiceTestExtension;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Tests content extraction behaviour.
 */
class ContentExtractServiceTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        ElementalArea::class,
        ElementContent::class,
        AiBlocksPage::class,
        ContentExtractServiceTestRecord::class,
        ContentExtractServiceDraftDiffPage::class,
    ];

    /**
     * Apply the test extension before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->merge(ContentExtractServiceTestRecord::class, 'extensions', [
            ContentExtractServiceTestExtension::class,
        ]);
    }

    /**
     * Reset extensions after each test.
     */
    protected function tearDown(): void
    {
        Config::modify()->set(ContentExtractServiceTestRecord::class, 'extensions', []);
        parent::tearDown();
    }

    /**
     * Ensure elemental search content is preferred over the Content field.
     */
    public function testExtractUsesElementalSearchContent(): void
    {
        $record = ContentExtractServiceTestRecord::create([
            'Title' => 'Test title',
            'Content' => '<p>Fallback</p>',
        ]);

        $service = new ContentExtractService();
        $content = $service->extract($record);

        $this->assertStringContainsString('Test title', $content);
        $this->assertStringContainsString('Elemental content', $content);
        $this->assertStringNotContainsString('Fallback', $content);
    }

    /**
     * Ensure published extraction uses the draft version when available.
     */
    public function testExtractPublishedUsesDraftWhenAvailable(): void
    {
        $service = new ContentExtractService();
        $page = SiteTree::create([
            'Title' => 'Live title',
            'Content' => '<p>Live content</p>',
        ]);
        $page->write();
        $page->publishSingle();

        $page->Title = 'Draft title';
        $page->Content = '<p>Draft content</p>';
        $page->write();

        $result = $service->extractPublished($page);

        $this->assertFalse($result['usedLive']);
        $this->assertTrue($result['hasUnpublishedChanges']);
        $this->assertStringContainsString('Draft title', $result['content']);
        $this->assertStringContainsString('Draft content', $result['content']);
        $this->assertStringNotContainsString('Live title', $result['content']);
    }

    /**
     * Ensure published extraction falls back to draft when no live version exists.
     */
    public function testExtractPublishedFallsBackToDraft(): void
    {
        $service = new ContentExtractService();
        $page = SiteTree::create([
            'Title' => 'Draft title',
            'Content' => '<p>Draft content</p>',
        ]);
        $page->write();

        $result = $service->extractPublished($page);

        $this->assertFalse($result['usedLive']);
        $this->assertTrue($result['hasUnpublishedChanges']);
        $this->assertStringContainsString('Draft title', $result['content']);
        $this->assertStringContainsString('Draft content', $result['content']);
    }

    /**
     * Ensure draft changes are detected even when isModifiedOnDraft is false.
     */
    public function testExtractPublishedDetectsDraftChangesWhenModifiedStateIsFalse(): void
    {
        $service = new ContentExtractService();
        $page = ContentExtractServiceDraftDiffPage::create([
            'Title' => 'Live title',
            'Content' => '<p>Live content</p>',
        ]);
        $page->write();
        $page->publishSingle();

        $page->Content = '<p>Draft content</p>';
        $page->write();

        $result = $service->extractPublished($page);

        $this->assertFalse($result['usedLive']);
        $this->assertTrue($result['hasUnpublishedChanges']);
    }

    /**
     * Ensure Elemental draft changes are detected as unpublished.
     */
    public function testExtractPublishedDetectsElementalDraftChanges(): void
    {
        $service = new ContentExtractService();
        $area = ElementalArea::create();
        $area->write();

        $page = AiBlocksPage::create([
            'Title' => 'Blocks page',
            'ElementalAreaID' => $area->ID,
        ]);
        $page->write();

        $element = ElementContent::create([
            'Title' => 'Block',
            'HTML' => '<p>Live block</p>',
            'ParentID' => $area->ID,
        ]);
        $element->write();

        $page->publishSingle();
        $area->publishSingle();
        $element->publishSingle();

        $element->HTML = '<p>Draft block</p>';
        $element->write();

        $result = $service->extractPublished($page);

        $this->assertFalse($result['usedLive']);
        $this->assertTrue($result['hasUnpublishedChanges']);
        $this->assertStringContainsString('Draft block', $result['content']);
        $this->assertStringNotContainsString('Live block', $result['content']);
    }
}
