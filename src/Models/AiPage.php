<?php

namespace SilverstripeLtd\AiMetadata\Models;

use Page;
use SilverStripe\Control\Director;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;

/**
 * Simple page type used exclusively for AI module test/demo content.
 *
 * This page type is intentionally restricted on live environments so it cannot be created
 * (or publicly viewed) on production sites.
 */
class AiPage extends Page
{
    private static $table_name = 'AiPage';

    private static $description = 'A simple page used for AI module demo/test content';

    private static $icon_class = 'font-icon-p-alt';

    /**
     * Prevent this page type from being created on live environments.
     *
     * @param \SilverStripe\Security\Member|int|null $member
     * @param array<string, mixed> $context
     * @return bool
     */
    public function canCreate($member = null, $context = [])
    {
        if (Director::isLive()) {
            return false;
        }
        return parent::canCreate($member, $context);
    }

    /**
     * Prevent this page type from being viewed on live environments.
     *
     * @param \SilverStripe\Security\Member|int|null $member
     * @return bool
     */
    public function canView($member = null)
    {
        if (!Director::isLive()) {
            return parent::canView($member);
        }

        if (!$member) {
            $member = Security::getCurrentUser();
        }

        if ($member && Permission::checkMember($member, ['ADMIN', 'SITETREE_VIEW_ALL'])) {
            return true;
        }
        return false;
    }
}
