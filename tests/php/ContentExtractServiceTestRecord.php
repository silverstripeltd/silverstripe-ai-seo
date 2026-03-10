<?php

namespace SilverstripeLtd\AiMetadata\Tests;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Data object used for content extraction tests.
 */
class ContentExtractServiceTestRecord extends DataObject implements TestOnly
{
    private static $table_name = 'AiMetadata_ContentExtractServiceTestRecord';

    private static $db = [
        'Title' => 'Varchar',
        'Content' => 'HTMLText',
    ];
}
