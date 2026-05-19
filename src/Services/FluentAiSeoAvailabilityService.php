<?php

namespace SilverstripeLtd\AiSeo\Services;

use SilverStripe\ORM\DataObject;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

/**
 * Restricts AI SEO to Fluent's default locale.
 */
class FluentAiSeoAvailabilityService extends AiSeoAvailabilityService
{
    public function canUseAiSeo(DataObject $record): bool
    {
        $localeCode = (string) FluentState::singleton()->getLocale();
        if ($localeCode === '') {
            return true;
        }

        $defaultLocale = Locale::getDefault();
        if (!$defaultLocale || !$defaultLocale->Locale) {
            return true;
        }
        return $localeCode === (string) $defaultLocale->Locale;
    }
}
