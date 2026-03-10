<?php

namespace SilverstripeLtd\AiMetadata\Tests;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

/**
 * Versioned test page that suppresses modified state.
 */
class ContentExtractServiceDraftDiffPage extends SiteTree implements TestOnly
{
    private static $table_name = 'AiMetadata_ContentExtractServiceDraftDiffPage';

    /**
     * Simulate a draft page that is not flagged as modified.
     */
    public function isModifiedOnDraft()
    {
        return false;
    }
}
