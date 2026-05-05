<?php

namespace SilverstripeLtd\AiMetadata\Models;

use SilverStripe\ORM\DataObject;

/**
 * Stores AI-generated metadata for a record.
 */
class GeneratedMetadata extends DataObject
{
    private static $table_name = 'GeneratedMetadata';

    private static $db = [
        'MetaDescription' => 'Text',
        'OGTitle' => 'Varchar(255)',
        'OGDescription' => 'Text',
        'SummaryLong' => 'Text',
        'KeyEntities' => 'Text',
        'KeyTopics' => 'Text',
        'SuggestedFAQs' => 'Text',
        'ContentHash' => 'Varchar(32)',
        'ReviewedAt' => 'Datetime',
        'GeneratedAt' => 'Datetime',
        'GenerationNote' => 'Varchar(255)',
    ];

    private static $has_one = [
        'Parent' => DataObject::class,
    ];

    /**
     * Indicates metadata was generated from Live.
     */
    public ?bool $usedLiveVersion = null;

    /**
     * Indicates draft changes exist beyond Live.
     */
    public ?bool $hasUnpublishedChanges = null;

    /**
     * Determine whether metadata content has drifted from the current hash.
     */
    public function isStale(string $currentHash): bool
    {
        $storedHash = (string)$this->ContentHash;
        if ($storedHash === '' || $currentHash === '') {
            return false;
        }
        return $storedHash !== $currentHash;
    }

    /**
     * Determine whether metadata has been reviewed since the last generation.
     */
    public function isReviewed(): bool
    {
        if (empty($this->GeneratedAt) || empty($this->ReviewedAt)) {
            return false;
        }
        return $this->ReviewedAt >= $this->GeneratedAt;
    }
}
