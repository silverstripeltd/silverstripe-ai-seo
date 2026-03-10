<?php

namespace SilverstripeLtd\AiMetadata\Services;

use SilverstripeLtd\AiMetadata\ValueObjects\AiMetadataResult;
use SilverstripeLtd\AiMetadata\Models\GeneratedMetadata;
use SilverstripeLtd\AiMetadata\Providers\ProviderFactory;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Coordinates content extraction, provider calls, and persistence.
 */
class MetadataGenerationService
{
    private ContentExtractService $contentExtractor;
    private ProviderFactory $providerFactory;

    /**
     * Create the service with optional dependencies.
     */
    public function __construct(
        ?ContentExtractService $contentExtractor = null,
        ?ProviderFactory $providerFactory = null
    ) {
        $this->contentExtractor = $contentExtractor ?: Injector::inst()->get(ContentExtractService::class);
        $this->providerFactory = $providerFactory ?: Injector::inst()->get(ProviderFactory::class);
    }

    /**
     * Generate metadata for a record and optionally persist it.
     */
    public function generateForRecord(
        DataObject $record,
        ?GeneratedMetadata $metadata = null,
        bool $persist = true
    ): GeneratedMetadata {
        $metadata = $metadata ?: $this->resolveMetadata($record);

        $extracted = $this->contentExtractor->extractPublished($record);
        $metadata->usedLiveVersion = $extracted['usedLive'];
        $metadata->hasUnpublishedChanges = $extracted['hasUnpublishedChanges'];

        $content = $extracted['content'];
        if ($content === '') {
            $metadata->GenerationNote = 'Insufficient content';
            if ($persist) {
                $metadata->write();
            }
            return $metadata;
        }

        $hash = $this->contentExtractor->computeHash($content);
        $pageTitle = $record->hasField('Title') ? (string)$record->Title : $record->ClassName;
        $pageUrl = method_exists($record, 'AbsoluteLink') ? $record->AbsoluteLink() : '';

        $provider = $this->providerFactory->getProvider();
        $result = $provider->generateMetadata($content, $pageTitle, $pageUrl);

        $this->applyResult($metadata, $result);
        $metadata->ContentHash = $hash;
        $metadata->GeneratedAt = DBDatetime::now()->getValue();
        $metadata->ReviewedAt = null;
        $metadata->GenerationNote = null;

        if ($persist) {
            $metadata->write();
        }

        return $metadata;
    }

    /**
     * Resolve metadata record for the given data object.
     */
    private function resolveMetadata(DataObject $record): GeneratedMetadata
    {
        if ($record->hasMethod('getOrCreateAiMetadata')) {
            return $record->getOrCreateAiMetadata();
        }

        return GeneratedMetadata::create();
    }

    /**
     * Apply provider result values to a metadata record.
     */
    private function applyResult(GeneratedMetadata $metadata, AiMetadataResult $result): void
    {
        $metadata->MetaDescription = $result->metaDescription;
        $metadata->OGTitle = $result->ogTitle;
        $metadata->OGDescription = $result->ogDescription;
        $metadata->SummaryLong = $result->summaryLong;
        $metadata->KeyEntities = $result->keyEntities ? json_encode($result->keyEntities) : null;
        $metadata->KeyTopics = $result->keyTopics;
        $metadata->SuggestedFAQs = $result->suggestedFAQs ? json_encode($result->suggestedFAQs) : null;
    }
}
