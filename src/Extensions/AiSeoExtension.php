<?php

namespace SilverstripeLtd\AiSeo\Extensions;

use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverstripeLtd\AiSeo\Services\AiSeoAvailabilityService;
use SilverstripeLtd\AiSeo\Services\JsonLdService;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\View\HTML;
use SilverStripe\View\Requirements;

/**
 * Adds AI SEO behaviours to data objects.
 */
class AiSeoExtension extends Extension
{
    /**
     * Fetch the related AI SEO record if it exists.
     */
    public function getAiSeo(): ?GeneratedSeo
    {
        if (!$this->owner->exists()) {
            return null;
        }
        return GeneratedSeo::get()
            ->filter([
                'ParentID' => $this->owner->ID,
                'ParentClass' => $this->owner->ClassName,
            ])
            ->first();
    }

    /**
     * Return existing metadata or create a new record for the owner.
     */
    public function getOrCreateAiSeo(): GeneratedSeo
    {
        $metadata = $this->getAiSeo();
        if ($metadata && $metadata->exists()) {
            $this->ensureMetadataClassName($metadata);
            return $metadata;
        }

        $metadata = GeneratedSeo::create();
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
    private function ensureMetadataClassName(GeneratedSeo $metadata): void
    {
        if ($metadata->getObsoleteClassName()) {
            $metadata->setClassName(GeneratedSeo::class);
        }
    }

    /**
     * Replace CMS fields with AI-managed variants.
     */
    public function updateCMSFields(FieldList $fields): void
    {
        $this->updateToolbarContext($fields);
        if (!$this->canAiSeoInCurrentLocale()) {
            return;
        }

        $metaField = $fields->dataFieldByName('MetaDescription');
        if (!$metaField) {
            return;
        }

        $metadata = $this->getOrCreateAiSeo();
        $aiDescription = $metadata->MetaDescription ?? '';
        $readonly = ReadonlyField::create('MetaDescription', $metaField->Title(), $aiDescription);

        $original = (string)$this->owner->getField('MetaDescription');
        $description = 'This value is managed by the AI SEO module.'
            . ' Open the Generate SEO with AI modal to edit.';
        if (trim($original) !== '') {
            $description .= ' ' . sprintf('Previous value: %s', Convert::raw2xml($original));
        }
        $readonly->setDescription($description);

        $fields->replaceField('MetaDescription', $readonly);
    }

    /**
     * Report whether AI SEO can run for the owner's current locale context.
     */
    public function canAiSeoInCurrentLocale(): bool
    {
        return $this->getAvailabilityService()->canUseAiSeo($this->owner);
    }

    /**
     * Add hidden record context so the toolbar button can mount beside Share.
     */
    private function updateToolbarContext(FieldList $fields): void
    {
        if (!$this->owner->exists() || !$this->owner->canEdit()) {
            return;
        }
        if (!$this->canAiSeoInCurrentLocale()) {
            return;
        }

        if ($fields->dataFieldByName('AiSeoRecordClass')) {
            return;
        }

        $fields->push(HiddenField::create(
            'AiSeoRecordClass',
            null,
            $this->owner->ClassName
        ));
    }

    /**
     * Publish reviewed metadata when the parent record is published.
     */
    public function onAfterPublish(): void
    {
        $metadata = $this->getAiSeo();
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
        $metadata = $this->getAiSeo();
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
        $metadata = $this->getAiSeo();
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
        $metadata = $this->getAiSeo();
        if ($metadata && $metadata->exists() && $metadata->MetaDescription) {
            return $metadata->MetaDescription;
        }
        return $this->owner->getField('MetaDescription');
    }

    /**
     * Inject additional meta tags for AI SEO fields.
     */
    public function updateMetaTags(?string &$tags): void
    {
        $metadata = $this->getAiSeo();
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
        $metadata = $this->getAiSeo();
        if (!$metadata || !$metadata->exists() || !$metadata->GeneratedAt) {
            return;
        }

        $jsonLdService = Injector::inst()->get(JsonLdService::class);
        $payload = $jsonLdService->generateJsonLd($this->owner, $metadata);
        if (!$payload) {
            return;
        }

        $tag = HTML::createTag('script', ['type' => 'application/ld+json'], $payload);
        Requirements::insertHeadTags($tag, 'ai-seo-jsonld');
    }

    private function getAvailabilityService(): AiSeoAvailabilityService
    {
        return Injector::inst()->get(AiSeoAvailabilityService::class);
    }
}
