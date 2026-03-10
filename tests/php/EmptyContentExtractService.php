<?php

namespace SilverstripeLtd\AiMetadata\Tests;

use SilverstripeLtd\AiMetadata\Services\ContentExtractService;
use SilverStripe\ORM\DataObject;

/**
 * Content extractor that always returns empty content.
 */
class EmptyContentExtractService extends ContentExtractService
{
    /**
     * Return an empty content payload.
     */
    public function extract(DataObject $record): string
    {
        return '';
    }
}
