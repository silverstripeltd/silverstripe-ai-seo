<?php

namespace SilverstripeLtd\AiSeo\Tests\Services;

use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverstripeLtd\AiSeo\Services\ContentExtractService;
use SilverstripeLtd\AiSeo\Services\SeoGenerationService;
use SilverstripeLtd\AiSeo\Tests\EmptyContentObject;
use SilverstripeLtd\AiSeo\Tests\SeoProviderStub;
use SilverstripeLtd\AiSeo\Tests\EmptyContentExtractService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;

/**
 * Exercises the metadata generation flow.
 */
class SeoGenerationServiceTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        GeneratedSeo::class,
        EmptyContentObject::class,
    ];

    /**
     * Restore the real provider factory.
     */
    protected function tearDown(): void
    {
        SeoProviderStub::unregister();
        parent::tearDown();
    }

    /**
     * Ensure generated metadata is applied and persisted.
     */
    public function testGeneratesMetadataPipeline(): void
    {
        SeoProviderStub::register([
            'metaDescription' => 'Meta Description',
        ]);
        $service = new SeoGenerationService(new ContentExtractService());

        $page = SiteTree::create([
            'Title' => 'Sample page',
            'Content' => '<p>Body</p>',
        ]);
        $page->write();

        $metadata = $service->generateForRecord($page);
        $this->assertSame('Meta Description', $metadata->MetaDescription);
        $this->assertNotEmpty($metadata->ContentHash);
        $this->assertNotEmpty($metadata->GeneratedAt);
        $this->assertNull($metadata->ReviewedAt);
        $this->assertNull($metadata->GenerationNote);
        $this->assertFalse($metadata->usedLiveVersion);
        $this->assertTrue($metadata->hasUnpublishedChanges);
    }

    /**
     * Ensure empty content results in a generation note.
     */
    public function testHandlesEmptyContent(): void
    {
        SeoProviderStub::register([]);
        $service = new SeoGenerationService(new EmptyContentExtractService());

        $record = EmptyContentObject::create(['Name' => 'Empty']);
        $record->write();

        $metadata = $service->generateForRecord($record, GeneratedSeo::create(), false);
        $this->assertSame('Insufficient content', $metadata->GenerationNote);
    }

    /**
     * Ensure generation captures published and draft-change flags.
     */
    public function testGenerateForRecordSetsPublishedFlags(): void
    {
        SeoProviderStub::register([
            'metaDescription' => 'Meta Description',
        ]);
        $service = new SeoGenerationService(new ContentExtractService());

        $page = SiteTree::create([
            'Title' => 'Live title',
            'Content' => '<p>Live content</p>',
        ]);
        $page->write();
        $page->publishSingle();

        $page->Content = '<p>Draft content</p>';
        $page->write();

        $metadata = $service->generateForRecord($page, GeneratedSeo::create(), false);
        $this->assertFalse($metadata->usedLiveVersion);
        $this->assertTrue($metadata->hasUnpublishedChanges);
    }

    /**
     * Ensure generation strips HTML from plain-text metadata fields only.
     */
    public function testGenerateForRecordSanitizesPlainTextFields(): void
    {
        $keyEntities = [
            [
                'type' => 'Organization',
                'name' => '<strong>Acme Corp</strong>',
                'sameAs' => 'https://example.com/acme',
            ],
        ];
        $suggestedFaqs = [
            [
                'question' => '<b>What does Acme do?</b>',
                'answer' => '<i>It builds rockets.</i>',
            ],
        ];
        SeoProviderStub::register([
            'metaDescription' => 'Plain description',
            'ogTitle' => '<strong>Social title</strong>',
            'ogDescription' => '<p>Social description</p>',
            'summaryLong' => '<div>Long summary</div>',
            'keyEntities' => $keyEntities,
            'keyTopics' => '<span>Topic one</span>, Topic two',
            'suggestedFAQs' => $suggestedFaqs,
        ]);
        $service = new SeoGenerationService(new ContentExtractService());

        $page = SiteTree::create([
            'Title' => 'Sample page',
            'Content' => '<p>Body</p>',
        ]);
        $page->write();

        $metadata = $service->generateForRecord($page);
        $this->assertSame('Plain description', $metadata->MetaDescription);
        $this->assertSame('Social title', $metadata->OGTitle);
        $this->assertSame('Social description', $metadata->OGDescription);
        $this->assertSame('Long summary', $metadata->SummaryLong);
        $this->assertSame('Topic one, Topic two', $metadata->KeyTopics);
        $this->assertSame(json_encode($keyEntities), $metadata->KeyEntities);
        $this->assertSame(json_encode($suggestedFaqs), $metadata->SuggestedFAQs);
    }
}
