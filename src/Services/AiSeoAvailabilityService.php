<?php

namespace SilverstripeLtd\AiSeo\Services;

use SilverStripe\ORM\DataObject;

/**
 * Reports whether AI SEO is available for the current record context.
 */
class AiSeoAvailabilityService
{
    public const UNSUPPORTED_LOCALE_MESSAGE = 'AI SEO is only available in the default locale';

    public function canUseAiSeo(DataObject $record): bool
    {
        return true;
    }

    public function getUnavailableMessage(): string
    {
        return AiSeoAvailabilityService::UNSUPPORTED_LOCALE_MESSAGE;
    }
}
