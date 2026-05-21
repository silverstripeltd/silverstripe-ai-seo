<?php

namespace SilverstripeLtd\AiSeo\Services;

use DNADesign\Elemental\Extensions\ElementalPageExtension;
use SilverStripe\Core\Extensible;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Extracts relevant content from a record for metadata generation.
 */
class ContentExtractService
{
    use Extensible;

    /**
     * Build a content string from the record, retaining HTML when present.
     */
    public function extract(DataObject $record): string
    {
        $parts = [];
        $title = $record->hasField('Title') ? (string)$record->getField('Title') : '';
        if ($title !== '') {
            $parts[] = $title;
        }
        $content = '';
        if ($record->hasMethod('getElementsForSearch')) {
            $content = (string)$record->getElementsForSearch();
        }
        if (trim($content) === '' && $record->hasField('Content')) {
            $content = (string)$record->Content;
        }
        $content = trim((string)$content);
        if ($content !== '') {
            $parts[] = $content;
        }
        $extracted = trim(implode("\n\n", $parts));
        $this->extend('updateExtractedContent', $extracted, $record);
        return trim((string)$extracted);
    }

    /**
     * Build a content string from the draft record when possible, retaining HTML when present.
     *
     * @return array{content: string, usedLive: bool, hasUnpublishedChanges: bool}
     */
    public function extractPublished(DataObject $record): array
    {
        $content = '';
        $usedLive = false;
        $draftContent = '';
        $liveContent = '';
        $draftExists = false;
        $liveExists = false;
        Versioned::withVersionedMode(function () use (
            $record,
            &$content,
            &$usedLive,
            &$draftContent,
            &$liveContent,
            &$draftExists,
            &$liveExists
        ): void {
            Versioned::set_stage(Versioned::DRAFT);
            $this->resetElementalCache();
            $draftRecord = DataObject::get($record->ClassName)->byID($record->ID);
            if ($draftRecord) {
                $draftExists = true;
                $draftContent = $this->extract($draftRecord);
                $content = $draftContent;
            }
            Versioned::set_stage(Versioned::LIVE);
            $this->resetElementalCache();
            $liveRecord = DataObject::get($record->ClassName)->byID($record->ID);
            if ($liveRecord) {
                $liveExists = true;
                $liveContent = $this->extract($liveRecord);
                if (!$draftExists) {
                    $content = $liveContent;
                    $usedLive = true;
                }
            }
        });
        $hasUnpublishedChanges = false;
        if ($draftExists && !$liveExists) {
            $hasUnpublishedChanges = true;
        }
        if ($draftExists && $liveExists && $record->hasExtension(Versioned::class)) {
            $hasUnpublishedChanges = $record->isModifiedOnDraft();
        }
        if ($draftExists && $liveExists) {
            $hasUnpublishedChanges = $hasUnpublishedChanges
                || $this->computeHash($draftContent) !== $this->computeHash($liveContent);
        }
        return [
            'content' => $content,
            'usedLive' => $usedLive,
            'hasUnpublishedChanges' => $hasUnpublishedChanges,
        ];
    }

    /**
     * Compute a hash for content staleness checks.
     */
    public function computeHash(string $content): string
    {
        return md5($content);
    }

    /**
     * Reset Elemental area cache so draft/live extraction reflects stage changes.
     */
    private function resetElementalCache(): void
    {
        if (!class_exists(ElementalPageExtension::class)) {
            return;
        }
        if (!property_exists(ElementalPageExtension::class, 'elementalAreas')) {
            return;
        }
        $property = new \ReflectionProperty(ElementalPageExtension::class, 'elementalAreas');
        if (!$property->isStatic()) {
            return;
        }
        $property->setAccessible(true);
        $property->setValue(null, null);
    }
}
