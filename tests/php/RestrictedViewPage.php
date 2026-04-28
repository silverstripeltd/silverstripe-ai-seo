<?php

namespace SilverstripeLtd\AiMetadata\Tests;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\TestOnly;

/**
 * Test page that denies view permissions.
 */
class RestrictedViewPage extends SiteTree implements TestOnly
{
    private static $table_name = 'AiMetadata_RestrictedViewPage';

    /**
     * Disallow viewing in permission tests.
     */
    public function canView($member = null): bool
    {
        return false;
    }
}
