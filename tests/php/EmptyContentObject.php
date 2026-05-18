<?php

namespace SilverstripeLtd\AiSeo\Tests;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Fixture data object with no content body.
 */
class EmptyContentObject extends DataObject implements TestOnly
{
    private static $table_name = 'AiSeo_EmptyContentObject';

    private static $db = [
        'Name' => 'Varchar(255)',
    ];
}
