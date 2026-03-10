<?php

namespace SilverstripeLtd\AiMetadata\Models;

/**
 * Elemental-ready page type for AI module test/demo data.
 *
 * This page type is intentionally restricted on live environments so it cannot be created
 * (or publicly viewed) on production sites.
 */
class AiBlocksPage extends AiPage
{
    private static $table_name = 'AiBlocksPage';

    private static $description = 'A modular page composed of content blocks';

    private static $icon_class = 'font-icon-p-alt';
}
