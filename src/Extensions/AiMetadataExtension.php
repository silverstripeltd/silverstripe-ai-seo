<?php

namespace SilverstripeLtd\AiMetadata\Extensions;

use SilverstripeLtd\AiMetadata\Models\GeneratedMetadata;
use SilverstripeLtd\AiMetadata\Services\JsonLdService;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\View\HTML;
use SilverStripe\View\Requirements;

/**
 * Adds AI metadata behaviours to data objects.
 */
class AiMetadataExtension extends Extension
{
    /**
     * Fetch the related AI metadata record if it exists.
     */
    public function getAiMetadata(): ?GeneratedMetadata
    {
        if (!$this->owner->exists()) {
            return null;
        }

        return GeneratedMetadata::get()
            ->filter([
                'ParentID' => $this->owner->ID,
                'ParentClass' => $this->owner->ClassName,
            ])
            ->first();
    }

    /**
     * Return existing metadata or create a new record for the owner.
     */
    public function getOrCreateAiMetadata(): GeneratedMetadata
    {
        $metadata = $this->getAiMetadata();
        if ($metadata && $metadata->exists()) {
            $this->ensureMetadataClassName($metadata);
            return $metadata;
        }

        $metadata = GeneratedMetadata::create();
        if ($this->owner->exists()) {
            $metadata->ParentID = $this->owner->ID;
            $metadata->ParentClass = $this->owner->ClassName;
            $metadata->write();
        }

        return $metadata;
    }

    /**
     * Ensure metadata uses a valid class name before writing.
     */
    private function ensureMetadataClassName(GeneratedMetadata $metadata): void
    {
        if ($metadata->getObsoleteClassName()) {
            $metadata->setClassName(GeneratedMetadata::class);
        }
    }

    /**
     * Replace CMS fields with AI-managed variants.
     */
    public function updateCMSFields(FieldList $fields): void
    {
        $metaField = $fields->dataFieldByName('MetaDescription');
        if (!$metaField) {
            return;
        }

        $metadata = $this->getOrCreateAiMetadata();
        $aiDescription = $metadata->MetaDescription ?? '';
        $readonly = ReadonlyField::create('MetaDescription', $metaField->Title(), $aiDescription);

        $original = (string)$this->owner->getField('MetaDescription');
        $description = 'This value is managed by the AI Metadata module. Open the AI Metadata modal to edit.';
        if (trim($original) !== '') {
            $description .= ' ' . sprintf('Previous value: %s', Convert::raw2xml($original));
        }
        $readonly->setDescription($description);

        $fields->replaceField('MetaDescription', $readonly);
    }

    /**
     * Add the AI metadata action button in the CMS.
     */
    public function updateCMSActions(FieldList $actions): void
    {
        if (!$this->owner->exists() || !$this->owner->canEdit()) {
            return;
        }

        $majorActions = $actions->fieldByName('MajorActions');
        if (!$majorActions || $majorActions->fieldByName('AiMetadataAction')) {
            return;
        }

        $button = FormAction::create('AiMetadataAction', 'AI Metadata')
            ->removeExtraClass('btn-primary')
            ->addExtraClass('btn-outline-primary ai-metadata__action')
            ->setAttribute('type', 'button')
            ->setAttribute('data-fqcn', $this->owner->ClassName)
            ->setAttribute('data-record-id', (string)$this->owner->ID);

        $majorActions->push($button);
    }

    /**
     * Publish reviewed metadata when the parent record is published.
     */
    public function onAfterPublish(): void
    {
        $metadata = $this->getAiMetadata();
        if (!$metadata || !$metadata->exists()) {
            return;
        }

        if (!$metadata->isReviewed()) {
            return;
        }

        $metadata->publishSingle();
    }

    /**
     * Unpublish metadata when the parent record is unpublished.
     */
    public function onBeforeUnpublish(): void
    {
        $metadata = $this->getAiMetadata();
        if (!$metadata || !$metadata->exists()) {
            return;
        }

        if ($metadata->isPublished()) {
            $metadata->doUnpublish();
        }
    }

    /**
     * Clean up live metadata when the parent record is archived.
     */
    public function onBeforeArchive(): void
    {
        $metadata = $this->getAiMetadata();
        if (!$metadata || !$metadata->exists()) {
            return;
        }

        if ($metadata->isPublished()) {
            $metadata->doUnpublish();
        }
    }

    /**
     * Provide the AI-managed meta description if available.
     */
    public function getMetaDescription(): ?string
    {
        $metadata = $this->getAiMetadata();
        if ($metadata && $metadata->exists() && $metadata->MetaDescription) {
            return $metadata->MetaDescription;
        }

        return $this->owner->getField('MetaDescription');
    }

    /**
     * Inject additional meta tags for AI metadata fields.
     */
    public function updateMetaTags(?string &$tags): void
    {
        $metadata = $this->getAiMetadata();
        if (!$metadata || !$metadata->exists()) {
            return;
        }

        $additionalTags = [];
        if ($metadata->OGTitle) {
            $additionalTags[] = HTML::createTag('meta', [
                'property' => 'og:title',
                'content' => $metadata->OGTitle,
            ]);
        }
        if ($metadata->OGDescription) {
            $additionalTags[] = HTML::createTag('meta', [
                'property' => 'og:description',
                'content' => $metadata->OGDescription,
            ]);
        }

        if ($additionalTags) {
            $tags = rtrim($tags ?? '');
            if ($tags !== '') {
                $tags .= "\n";
            }
            $tags .= implode("\n", $additionalTags);
        }
    }

    /**
     * Inject JSON-LD into the page response when available.
     */
    public function contentcontrollerInit(ContentController $controller): void
    {
        $metadata = $this->getAiMetadata();
        if (!$metadata || !$metadata->exists() || !$metadata->GeneratedAt) {
            return;
        }

        $jsonLdService = Injector::inst()->get(JsonLdService::class);
        $payload = $jsonLdService->generateJsonLd($this->owner, $metadata);
        if (!$payload) {
            return;
        }

        $tag = HTML::createTag('script', ['type' => 'application/ld+json'], $payload);
        Requirements::insertHeadTags($tag, 'ai-metadata-jsonld');
    }
}
