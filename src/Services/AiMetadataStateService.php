<?php

namespace SilverstripeLtd\AiMetadata\Services;

use SilverstripeLtd\AiMetadata\Models\GeneratedMetadata;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;

/**
 * Centralises AI metadata state checks (staleness, draft changes).
 */
class AiMetadataStateService
{
    private ContentExtractService $contentExtractor;

    /**
     * Create the service with optional dependencies.
     */
    public function __construct(?ContentExtractService $contentExtractor = null)
    {
        $this->contentExtractor = $contentExtractor ?: Injector::inst()->get(ContentExtractService::class);
    }

    /**
     * Resolve the AI metadata state for the record.
     *
     * @return array{stale: bool, hasUnpublishedChanges: bool, usedLive: bool, currentHash: string}
     */
    public function getState(DataObject $record, ?GeneratedMetadata $metadata = null): array
    {
        $extracted = $this->contentExtractor->extractPublished($record);
        $currentHash = $this->contentExtractor->computeHash($extracted['content']);
        $stale = false;

        if ($metadata && $metadata->exists() && $metadata->ContentHash) {
            $stale = $metadata->isStale($currentHash);
        }
        return [
            'stale' => $stale,
            'hasUnpublishedChanges' => $extracted['hasUnpublishedChanges'],
            'usedLive' => $extracted['usedLive'],
            'currentHash' => $currentHash,
        ];
    }
}
