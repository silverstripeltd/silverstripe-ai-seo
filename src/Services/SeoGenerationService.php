<?php

namespace SilverstripeLtd\AiSeo\Services;

use SilverstripeLtd\AiSeo\ValueObjects\AiSeoResult;
use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverstripeLtd\AiSeo\Providers\ProviderFactory;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Coordinates content extraction, provider calls, and persistence.
 */
class SeoGenerationService
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
     * Generate SEO for a record and optionally persist it.
     */
    public function generateForRecord(
        DataObject $record,
        ?GeneratedSeo $metadata = null,
        bool $persist = true
    ): GeneratedSeo {
        $metadata = $metadata ?: $this->resolveSeo($record);
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
        $result = $provider->generateSeo($content, $pageTitle, $pageUrl);
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
     * Resolve the SEO record for the given data object.
     */
    private function resolveSeo(DataObject $record): GeneratedSeo
    {
        if ($record->hasMethod('getOrCreateAiSeo')) {
            return $record->getOrCreateAiSeo();
        }
        return GeneratedSeo::create();
    }

    /**
     * Apply provider result values to an SEO record.
     */
    private function applyResult(GeneratedSeo $metadata, AiSeoResult $result): void
    {
        $metadata->MetaDescription = $this->sanitizePlainTextValue($result->metaDescription);
        $metadata->OGTitle = $this->sanitizePlainTextValue($result->ogTitle);
        $metadata->OGDescription = $this->sanitizePlainTextValue($result->ogDescription);
        $metadata->SummaryLong = $this->sanitizePlainTextValue($result->summaryLong);
        $metadata->KeyEntities = $result->keyEntities ? json_encode($result->keyEntities) : null;
        $metadata->KeyTopics = $this->sanitizePlainTextValue($result->keyTopics);
        $metadata->SuggestedFAQs = $result->suggestedFAQs ? json_encode($result->suggestedFAQs) : null;
    }

    /**
     * Sanitize a generated plain-text SEO value.
     */
    private function sanitizePlainTextValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return strip_tags($value);
    }
}
