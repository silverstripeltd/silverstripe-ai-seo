<?php

namespace SilverstripeLtd\AiSeo\Services;

use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;

/**
 * Marks generated SEO metadata as reviewed so it can publish with its parent record.
 */
class SeoReviewService
{
    private ContentExtractService $contentExtractor;

    /**
     * Create the service with optional dependencies.
     */
    public function __construct(?ContentExtractService $contentExtractor = null)
    {
        $this->contentExtractor = $contentExtractor ?: Injector::inst()->get(ContentExtractService::class);
    }

    /**
     * Approve the metadata on behalf of the member and write it to draft.
     *
     * The content hash is filled from the parent record when missing, ReviewedAt is set to now
     * so it is at or after GeneratedAt, and the record is validated and written to the draft
     * stage only. Publishing happens later through the parent record's publish hook. The member
     * identifies who approved the metadata; the caller is responsible for checking that the
     * member may edit the parent record.
     */
    public function approve(GeneratedSeo $seo, Member $member): GeneratedSeo
    {
        $this->ensureContentHash($seo);
        $seo->ReviewedAt = DBDatetime::now()->getValue();
        $validationResult = $seo->validate();
        if (!$validationResult->isValid()) {
            throw ValidationException::create($validationResult);
        }
        $seo->write();
        return $seo;
    }

    /**
     * Fill the content hash from the parent record's published content when it is empty.
     */
    private function ensureContentHash(GeneratedSeo $seo): void
    {
        if ($seo->ContentHash) {
            return;
        }
        $record = $seo->Parent();
        if (!$record || !$record->exists()) {
            return;
        }
        $extracted = $this->contentExtractor->extractPublished($record);
        $seo->ContentHash = $this->contentExtractor->computeHash($extracted['content']);
    }
}
