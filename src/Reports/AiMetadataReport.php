<?php

namespace SilverstripeLtd\AiMetadata\Reports;

use SilverstripeLtd\AiMetadata\Models\GeneratedMetadata;
use SilverstripeLtd\AiMetadata\Services\AiMetadataStateService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\Model\List\PaginatedList;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Reports\Report;
use SilverStripe\Versioned\Versioned;

/**
 * Report on AI metadata status across SiteTree records.
 */
class AiMetadataReport extends Report
{
    private const STATUS_NEEDS_ATTENTION = 'needs_attention';
    private const STATUS_MISSING = 'missing';
    private const STATUS_STALE = 'stale';
    private const STATUS_UNREVIEWED = 'unreviewed';
    private const STATUS_OK = 'ok';
    private const STATUS_NOT_PUBLISHED = 'not_published';
    private const STATUS_ALL = 'all';

    private const LIVE_PUBLISHED = 'published';
    private const LIVE_NOT_PUBLISHED = 'not_published';
    private const LIVE_OUTDATED = 'outdated';

    /**
     * Return the report title.
     */
    public function title(): string
    {
        return 'AI Metadata Status';
    }

    /**
     * Provide parameter fields for filtering.
     */
    public function parameterFields(): FieldList
    {
        return FieldList::create(
            DropdownField::create(
                'Status',
                'Status',
                $this->getStatusOptions()
            )
        );
    }

    /**
     * Return the report columns configuration.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        return [
            'Title' => [
                'title' => 'Page title',
                'formatting' => function (?string $value, ArrayData $item): string {
                    $page = $item->Page;
                    return sprintf(
                        '<a href="%s">%s</a>',
                        Convert::raw2att($page->CMSEditLink()),
                        Convert::raw2xml($page->Title)
                    );
                },
            ],
            'PageType' => [
                'title' => 'Page type',
            ],
            'Status' => [
                'title' => 'Status',
            ],
            'LiveStatus' => [
                'title' => 'Live status',
            ],
            'GeneratedAt' => [
                'title' => 'Last generated',
                'formatting' => function (?string $value): string {
                    if (!$value) {
                        return 'Never';
                    }
                    $field = DBDatetime::create();
                    $field->setValue($value);
                    return $field->Nice();
                },
            ],
            'ReviewedAt' => [
                'title' => 'Last reviewed',
                'formatting' => function (?string $value): string {
                    if (!$value) {
                        return 'Never';
                    }
                    $field = DBDatetime::create();
                    $field->setValue($value);
                    return $field->Nice();
                },
            ],
        ];
    }

    /**
     * Return report records filtered by status.
     */
    public function sourceRecords(array $params = [], ?string $sort = null, array|int|null $limit = null): PaginatedList
    {
        $statusFilter = $params['Status'] ?? AiMetadataReport::STATUS_NEEDS_ATTENTION;
        $records = [];
        $stateService = Injector::inst()->get(AiMetadataStateService::class);

        foreach (SiteTree::get() as $page) {
            $metadata = $page->getAiMetadata();
            $status = $this->determineStatus($page, $metadata, $stateService);
            $liveStatus = $this->determineLiveStatus($metadata);
            if (!$this->matchesFilter($status, $statusFilter, $liveStatus)) {
                continue;
            }

            $records[] = ArrayData::create([
                'Page' => $page,
                'Title' => $page->Title,
                'PageType' => $page->singular_name(),
                'Status' => $this->getStatusLabel($status),
                'StatusKey' => $status,
                'LiveStatus' => $this->getLiveStatusLabel($liveStatus),
                'GeneratedAt' => $metadata ? $metadata->GeneratedAt : null,
                'ReviewedAt' => $metadata ? $metadata->ReviewedAt : null,
            ]);
        }

        usort($records, function (ArrayData $a, ArrayData $b): int {
            $priority = [
                AiMetadataReport::STATUS_MISSING => 1,
                AiMetadataReport::STATUS_STALE => 2,
                AiMetadataReport::STATUS_UNREVIEWED => 3,
                AiMetadataReport::STATUS_OK => 4,
            ];
            $aPriority = $priority[$a->StatusKey] ?? 5;
            $bPriority = $priority[$b->StatusKey] ?? 5;
            if ($aPriority === $bPriority) {
                return strcasecmp($a->Title, $b->Title);
            }
            return $aPriority < $bPriority ? -1 : 1;
        });

        $list = ArrayList::create($records);
        $request = Controller::curr() ? Controller::curr()->getRequest() : [];
        $paginated = PaginatedList::create($list, $request ?? []);
        if (is_array($limit) && isset($limit['limit'])) {
            $paginated->setPageLength($limit['limit']);
            if (isset($limit['start'])) {
                $paginated->setPageStart($limit['start']);
            }
        }
        return $paginated;
    }

    /**
     * Determine the status label for a page.
     */
    private function determineStatus(
        SiteTree $page,
        ?GeneratedMetadata $metadata,
        AiMetadataStateService $stateService
    ): string {
        if (!$metadata || !$metadata->exists() || !$metadata->GeneratedAt) {
            return AiMetadataReport::STATUS_MISSING;
        }

        if ($metadata->ContentHash) {
            $state = $stateService->getState($page, $metadata);
            if ($state['stale']) {
                return AiMetadataReport::STATUS_STALE;
            }
        }

        if (!$metadata->isReviewed()) {
            return AiMetadataReport::STATUS_UNREVIEWED;
        }

        return AiMetadataReport::STATUS_OK;
    }

    /**
     * Determine the live status of metadata.
     */
    private function determineLiveStatus(?GeneratedMetadata $metadata): string
    {
        if (!$metadata || !$metadata->exists()) {
            return AiMetadataReport::LIVE_NOT_PUBLISHED;
        }

        if (!$metadata->hasExtension(Versioned::class)) {
            return AiMetadataReport::LIVE_NOT_PUBLISHED;
        }

        if (!$metadata->isPublished()) {
            return AiMetadataReport::LIVE_NOT_PUBLISHED;
        }

        if ($metadata->isModifiedOnDraft()) {
            return AiMetadataReport::LIVE_OUTDATED;
        }

        return AiMetadataReport::LIVE_PUBLISHED;
    }

    /**
     * Check whether a status matches the filter.
     */
    private function matchesFilter(string $status, string $filter, string $liveStatus): bool
    {
        $filter = strtolower($filter);
        if ($filter === AiMetadataReport::STATUS_ALL) {
            return true;
        }

        if ($filter === AiMetadataReport::STATUS_NEEDS_ATTENTION) {
            return in_array(
                $status,
                [
                    AiMetadataReport::STATUS_MISSING,
                    AiMetadataReport::STATUS_STALE,
                    AiMetadataReport::STATUS_UNREVIEWED,
                ],
                true
            )
                || ($status === AiMetadataReport::STATUS_OK && $liveStatus === AiMetadataReport::LIVE_NOT_PUBLISHED);
        }

        if ($filter === AiMetadataReport::STATUS_NOT_PUBLISHED) {
            return $liveStatus === AiMetadataReport::LIVE_NOT_PUBLISHED;
        }

        if ($filter === AiMetadataReport::STATUS_OK) {
            return $status === $filter && $liveStatus !== AiMetadataReport::LIVE_NOT_PUBLISHED;
        }

        return $status === $filter;
    }

    /**
     * Return the available status filter options.
     *
     * @return array<string, string>
     */
    private function getStatusOptions(): array
    {
        return [
            AiMetadataReport::STATUS_NEEDS_ATTENTION => 'Needs attention',
            AiMetadataReport::STATUS_MISSING => 'Missing',
            AiMetadataReport::STATUS_STALE => 'Stale',
            AiMetadataReport::STATUS_UNREVIEWED => 'Unreviewed',
            AiMetadataReport::STATUS_OK => 'OK',
            AiMetadataReport::STATUS_NOT_PUBLISHED => 'Not published',
            AiMetadataReport::STATUS_ALL => 'All',
        ];
    }

    /**
     * Translate a status key into a display label.
     */
    private function getStatusLabel(string $status): string
    {
        $options = $this->getStatusOptions();
        return $options[$status] ?? $status;
    }

    /**
     * Translate a live status key into a display label.
     */
    private function getLiveStatusLabel(string $status): string
    {
        $labels = [
            AiMetadataReport::LIVE_PUBLISHED => 'Published',
            AiMetadataReport::LIVE_NOT_PUBLISHED => 'Not published',
            AiMetadataReport::LIVE_OUTDATED => 'Outdated',
        ];
        return $labels[$status] ?? $status;
    }
}
