<?php

namespace SilverstripeLtd\AiMetadata\Tests;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

/**
 * Test page that denies edit permissions.
 */
class RestrictedPage extends SiteTree implements TestOnly
{
    private static $table_name = 'AiMetadata_RestrictedPage';

    /**
     * Disallow edits in permission tests.
     */
    public function canEdit($member = null)
    {
        return false;
    }
}
