<?php

namespace SilverstripeLtd\AiMetadata\Tests;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Fixture data object with no content body.
 */
class EmptyContentObject extends DataObject implements TestOnly
{
    private static $table_name = 'AiMetadata_EmptyContentObject';

    private static $db = [
        'Name' => 'Varchar(255)',
    ];
}
