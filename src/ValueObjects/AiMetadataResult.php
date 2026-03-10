<?php

namespace SilverstripeLtd\AiMetadata\ValueObjects;

/**
 * Holds AI-generated metadata results.
 */
class AiMetadataResult
{
    public ?string $metaDescription = null;
    public ?string $ogTitle = null;
    public ?string $ogDescription = null;
    public ?string $summaryLong = null;
    public ?array $keyEntities = null;
    public ?string $keyTopics = null;
    public ?array $suggestedFAQs = null;

    /**
     * Populate the result from a decoded metadata payload.
     *
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->metaDescription = $data['metaDescription'] ?? null;
        $this->ogTitle = $data['ogTitle'] ?? null;
        $this->ogDescription = $data['ogDescription'] ?? null;
        $this->summaryLong = $data['summaryLong'] ?? null;
        $this->keyEntities = $data['keyEntities'] ?? null;
        $this->keyTopics = $data['keyTopics'] ?? null;
        $this->suggestedFAQs = $data['suggestedFAQs'] ?? null;
    }
}
