<?php

namespace SilverstripeLtd\AiMetadata\Tests\Services;

use SilverstripeLtd\AiMetadata\ValueObjects\AiMetadataResult;
use SilverstripeLtd\AiMetadata\Models\GeneratedMetadata;
use SilverstripeLtd\AiMetadata\Services\ContentExtractService;
use SilverstripeLtd\AiMetadata\Services\MetadataGenerationService;
use SilverstripeLtd\AiMetadata\Tests\EmptyContentObject;
use SilverstripeLtd\AiMetadata\Tests\StubProvider;
use SilverstripeLtd\AiMetadata\Tests\StubProviderFactory;
use SilverstripeLtd\AiMetadata\Tests\EmptyContentExtractService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;

/**
 * Exercises the metadata generation flow.
 */
class MetadataGenerationServiceTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        GeneratedMetadata::class,
        EmptyContentObject::class,
    ];

    /**
     * Ensure generated metadata is applied and persisted.
     */
    public function testGeneratesMetadataPipeline(): void
    {
        $provider = new StubProvider(new AiMetadataResult([
            'metaDescription' => 'Meta Description',
        ]));

        $factory = new StubProviderFactory($provider);
        $service = new MetadataGenerationService(new ContentExtractService(), $factory);

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
        $provider = new StubProvider(new AiMetadataResult());
        $factory = new StubProviderFactory($provider);
        $service = new MetadataGenerationService(new EmptyContentExtractService(), $factory);

        $record = EmptyContentObject::create(['Name' => 'Empty']);
        $record->write();

        $metadata = $service->generateForRecord($record, GeneratedMetadata::create(), false);
        $this->assertSame('Insufficient content', $metadata->GenerationNote);
    }

    /**
     * Ensure generation captures published and draft-change flags.
     */
    public function testGenerateForRecordSetsPublishedFlags(): void
    {
        $provider = new StubProvider(new AiMetadataResult([
            'metaDescription' => 'Meta Description',
        ]));
        $factory = new StubProviderFactory($provider);
        $service = new MetadataGenerationService(new ContentExtractService(), $factory);

        $page = SiteTree::create([
            'Title' => 'Live title',
            'Content' => '<p>Live content</p>',
        ]);
        $page->write();
        $page->publishSingle();

        $page->Content = '<p>Draft content</p>';
        $page->write();

        $metadata = $service->generateForRecord($page, GeneratedMetadata::create(), false);
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
        $provider = new StubProvider(new AiMetadataResult([
            'metaDescription' => 'Plain description',
            'ogTitle' => '<strong>Social title</strong>',
            'ogDescription' => '<p>Social description</p>',
            'summaryLong' => '<div>Long summary</div>',
            'keyEntities' => $keyEntities,
            'keyTopics' => '<span>Topic one</span>, Topic two',
            'suggestedFAQs' => $suggestedFaqs,
        ]));
        $factory = new StubProviderFactory($provider);
        $service = new MetadataGenerationService(new ContentExtractService(), $factory);

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
