<?php

namespace SilverstripeLtd\AiMetadata\Tests;

use SilverStripe\Core\Extension;

/**
 * Extension providing search content to the test record.
 */
class ContentExtractServiceTestExtension extends Extension
{
    /**
     * Provide elemental search content for extraction.
     */
    public function getElementsForSearch(): string
    {
        return 'Elemental content';
    }
}
