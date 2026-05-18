<?php

namespace SilverstripeLtd\AiSeo\Forms;

use SilverstripeLtd\AiSeo\Controllers\AiSeoController;
use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverstripeLtd\AiSeo\Services\AiSeoStateService;
use SilverstripeLtd\AiSeo\Services\JsonLdService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\Convert;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ToggleCompositeField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\HTML;

/**
 * Build the AI SEO form schema.
 */
class AiSeoForm extends Form
{
    private const DEFAULT_META_DESCRIPTION_MAX = 150;
    private const ENV_META_DESCRIPTION_MAX = 'AI_SEO_META_DESCRIPTION_MAX';

    /**
     * Build the AI SEO form for a record.
     */
    public static function createForRecord(
        AiSeoController $controller,
        DataObject $record,
        GeneratedSeo $metadata,
        string $errorMessage = '',
        bool $hasUnpublishedChanges = false
    ): self {
        $reviewConfirmed = self::isReviewConfirmed($metadata);
        $fields = self::buildFields($record, $metadata, $errorMessage, $reviewConfirmed, $hasUnpublishedChanges);
        $actions = self::buildActions($metadata);
        $name = sprintf(AiSeoController::FORM_NAME_TEMPLATE, $record->ID);

        /** @var self $form */
        $form = self::create($controller, $name, $fields, $actions);
        $form->setFormAction($controller->Link(sprintf(
            'aiSeoForm/%d?fqcn=%s',
            $record->ID,
            rawurlencode($record->ClassName)
        )));
        $form->addExtraClass('form--no-dividers');
        $form->loadDataFrom($metadata);
        $form->loadDataFrom([
            'KeyEntities' => $metadata->KeyEntities,
            'KeyTopics' => $metadata->KeyTopics,
            'SuggestedFAQs' => $metadata->SuggestedFAQs,
            'ContentHash' => $metadata->ContentHash,
            'GeneratedAt' => $metadata->GeneratedAt,
            'ReviewedAt' => $metadata->ReviewedAt,
            'GenerationNote' => $metadata->GenerationNote,
            'ReviewConfirmed' => $reviewConfirmed ? 1 : 0,
        ]);
        return $form;
    }

    /**
     * Determine whether metadata is stale against the current record.
     */
    public static function isStale(DataObject $record, GeneratedSeo $metadata): bool
    {
        if (!$metadata->ContentHash) {
            return false;
        }

        $stateService = Injector::inst()->get(AiSeoStateService::class);
        $state = $stateService->getState($record, $metadata);
        return $state['stale'];
    }

    /**
     * Build the AI SEO form fields with optional error messaging.
     */
    private static function buildFields(
        DataObject $record,
        GeneratedSeo $metadata,
        string $errorMessage,
        bool $reviewConfirmed,
        bool $hasUnpublishedChanges
    ): FieldList {
        $fields = FieldList::create();

        $banner = self::buildStatusBanner($record, $metadata, $errorMessage, $hasUnpublishedChanges);
        if ($banner !== '') {
            $fields->push(LiteralField::create('AiSeoStatus', $banner));
        }

        $keyTopicsDisplay = self::renderKeyTopics($metadata->KeyTopics);
        $fields->push(LiteralField::create(
            'AiSeoKeyTopicsHeader',
            self::renderFieldLabel('Key topics (Helps judge if generated SEO is on track - not shown on frontend)')
        ));
        $fields->push(LiteralField::create('KeyTopicsDisplay', $keyTopicsDisplay));
        $fields->push(HiddenField::create('KeyTopics', '', $metadata->KeyTopics));

        $fields->push(TextField::create(
            'MetaDescription',
            'Meta description (meta tag)'
        )->setDescription(self::buildLengthHint(
            'MetaDescription',
            (string)$metadata->MetaDescription,
            self::getMetaDescriptionMax()
        )));
        $fields->push(TextField::create(
            'OGTitle',
            'OG title (meta tag + JSON-LD)'
        ));
        $fields->push(TextField::create(
            'OGDescription',
            'OG description (meta tag)'
        ));
        $fields->push(TextareaField::create(
            'SummaryLong',
            'Summary - long form (JSON-LD + /llms.txt)'
        )
            ->setRows(3));

        $readonlyFields = FieldList::create(
            HeaderField::create(
                'AiSeoKeyEntitiesHeader',
                'Key entities (JSON-LD)',
                4
            ),
            LiteralField::create('KeyEntitiesDisplay', self::renderKeyEntities($metadata->KeyEntities)),
            HiddenField::create('KeyEntities', '', $metadata->KeyEntities),
            HeaderField::create(
                'AiSeoSuggestedFaqsHeader',
                'Suggested FAQs (JSON-LD)',
                4
            ),
            LiteralField::create('SuggestedFAQsDisplay', self::renderFaqs($metadata->SuggestedFAQs)),
            HiddenField::create('SuggestedFAQs', '', $metadata->SuggestedFAQs),
            HeaderField::create(
                'AiSeoJsonLdHeader',
                'JSON-LD preview',
                4
            ),
            LiteralField::create('JsonLdSchemaDisplay', self::renderJsonLd($record, $metadata))
        );
        $readonlyToggle = ToggleCompositeField::create(
            'AiSeoGeneratedInfo',
            'Generated details',
            $readonlyFields
        );
        $readonlyToggle->setStartClosed(true);
        $readonlyToggle->setHeadingLevel(4);
        $readonlyToggle->setSchemaComponent('ToggleCompositeField');
        $readonlyToggle->addExtraClass('ss-toggle ss-toggle-start-closed ai-seo-modal__readonly');
        $readonlySchemaData = $readonlyToggle->getSchemaData();
        $readonlyToggle->setSchemaData([
            'data' => array_merge($readonlySchemaData['data'] ?? [], [
                'headingLevel' => $readonlyToggle->getHeadingLevel(),
            ]),
        ]);
        $fields->push($readonlyToggle);

        $reviewLabel = $reviewConfirmed
            ? 'Generated AI SEO was reviewed'
            : 'I have reviewed the generated AI SEO';
        $fields->push(CheckboxField::create('ReviewConfirmed', $reviewLabel)
            ->setValue($reviewConfirmed ? 1 : 0));

        $fields->push(HiddenField::create('ContentHash', '', $metadata->ContentHash));
        $fields->push(HiddenField::create('GeneratedAt', '', $metadata->GeneratedAt));
        $fields->push(HiddenField::create('ReviewedAt', '', $metadata->ReviewedAt));
        $fields->push(HiddenField::create('GenerationNote', '', $metadata->GenerationNote));
        return $fields;
    }

    /**
     * Build the AI SEO form actions.
     */
    private static function buildActions(GeneratedSeo $metadata): FieldList
    {
        $regenerateLabel = $metadata->GeneratedAt
            ? 'Regenerate'
            : 'Generate SEO';
        $hasGeneratedSeo = (bool)$metadata->GeneratedAt;
        $reviewRequired = self::isReviewRequired($metadata);

        // Non-submitting form action to trigger regeneration via XHR.
        $regenerateAction = FormAction::create('doRegenerate', $regenerateLabel)
            ->setSchemaData(['data' => ['buttonStyle' => 'info']]);

        $actions = [];
        if ($reviewRequired) {
            $actions[] = LiteralField::create(
                'AiSeoSubmitNote',
                HTML::createTag(
                    'p',
                    ['class' => 'ai-seo-modal__submit-note'],
                    Convert::raw2xml(
                        'Check the "I have reviewed the generated AI SEO" checkbox, then click Apply SEO.'
                        . ' SEO will go live when the page is next published.'
                    )
                )
            );
        }
        $actions[] = $regenerateAction;
        if ($hasGeneratedSeo) {
            $actions[] = FormAction::create('doSave', 'Apply SEO')
                ->setSchemaData(['data' => ['buttonStyle' => 'info']])
                ->setAttribute('tabindex', '-1');
        }
        return FieldList::create(...$actions);
    }

    /**
     * Determine whether metadata can be considered reviewed.
     */
    private static function isReviewConfirmed(GeneratedSeo $metadata): bool
    {
        return $metadata->isReviewed();
    }

    /**
     * Determine whether metadata still requires review.
     */
    private static function isReviewRequired(GeneratedSeo $metadata): bool
    {
        if (!$metadata->GeneratedAt) {
            return false;
        }
        return !$metadata->isReviewed();
    }

    /**
     * Build the status banner HTML for metadata state and errors.
     */
    private static function buildStatusBanner(
        DataObject $record,
        GeneratedSeo $metadata,
        string $errorMessage,
        bool $hasUnpublishedChanges
    ): string {
        $items = [];

        if ($errorMessage !== '') {
            $items[] = HTML::createTag(
                'div',
                ['class' => 'ai-seo-modal__banner ai-seo-modal__banner--error'],
                Convert::raw2xml($errorMessage)
            );
        }

        $items[] = self::buildGenerationStatusBanner($metadata);

        if (self::isStale($record, $metadata)) {
            $items[] = HTML::createTag(
                'div',
                ['class' => 'ai-seo-modal__banner ai-seo-modal__banner--stale'],
                'Page content has changed since SEO was generated.'
                . ' To regenerate, click the "Regenerate" button.'
            );
        }

        if ($hasUnpublishedChanges) {
            $items[] = HTML::createTag(
                'div',
                ['class' => 'ai-seo-modal__banner ai-seo-modal__banner--info'],
                'This page has unpublished changes. Generated AI SEO reflects the draft content'
                . ' and will go live when the page is published.'
            );
        }
        return implode('', $items);
    }

    /**
     * Build the generation status banner for the current metadata state.
     */
    private static function buildGenerationStatusBanner(GeneratedSeo $metadata): string
    {
        if (!$metadata->GeneratedAt) {
            return HTML::createTag(
                'div',
                ['class' => 'ai-seo-modal__banner ai-seo-modal__banner--status-never'],
                'No generated AI SEO yet.'
            );
        }

        $timestamp = self::buildGeneratedTimestamp($metadata);

        if (!$metadata->isReviewed()) {
            return HTML::createTag(
                'div',
                ['class' => 'ai-seo-modal__banner ai-seo-modal__banner--status-review'],
                sprintf('Generated AI SEO ready for review. Last generated: %s', $timestamp)
            );
        }

        $statusNote = self::buildVersionedStatusNote($metadata);
        $message = sprintf('Generated AI SEO reviewed and saved. Last generated: %s', $timestamp);
        if ($statusNote !== '') {
            $message .= ' ' . $statusNote;
        }
        return HTML::createTag(
            'div',
            ['class' => 'ai-seo-modal__banner ai-seo-modal__banner--status-reviewed'],
            $message
        );
    }

    /**
     * Build the formatted timestamp markup for banner output.
     */
    private static function buildGeneratedTimestamp(GeneratedSeo $metadata): string
    {
        $raw = (string)$metadata->GeneratedAt;
        $field = DBDatetime::create();
        $field->setValue($raw);
        $formatted = Convert::raw2xml($field->Nice());
        return HTML::createTag(
            'span',
            [
                'class' => 'ai-seo-modal__timestamp',
                'data-ai-seo-timestamp' => Convert::raw2att($raw),
            ],
            $formatted
        );
    }

    /**
     * Build the draft/published status note for versioned metadata.
     */
    private static function buildVersionedStatusNote(GeneratedSeo $metadata): string
    {
        if (!$metadata->exists() || !$metadata->hasExtension(Versioned::class)) {
            return '';
        }

        if ($metadata->isOnDraftOnly()) {
            return 'Status: Draft only (not published yet).';
        }
        if ($metadata->isModifiedOnDraft()) {
            return 'Status: Draft changes not published.';
        }
        if ($metadata->isPublished()) {
            return 'Status: Published.';
        }
        return '';
    }

    /**
     * Render key entities as HTML.
     */
    private static function renderKeyEntities(?string $value): string
    {
        $decoded = self::decodeJsonArray($value);
        if (!$decoded) {
            return self::renderFallback($value);
        }

        $items = [];
        foreach ($decoded as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $type = Convert::raw2xml($entity['type'] ?? 'Entity');
            $name = Convert::raw2xml($entity['name'] ?? 'Unknown');
            $sameAs = '';
            if (!empty($entity['sameAs'])) {
                $sameAs = sprintf(' (%s)', Convert::raw2xml($entity['sameAs']));
            }
            $items[] = HTML::createTag('li', [], sprintf('<strong>%s:</strong> %s%s', $type, $name, $sameAs));
        }

        if (!$items) {
            return self::renderFallback($value);
        }
        return self::wrapDetailValue(HTML::createTag('ul', [], implode('', $items)));
    }

    /**
     * Render key topics as comma-separated text.
     */
    private static function renderKeyTopics(?string $value): string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '') {
            return self::renderMutedMessage('None');
        }

        // Handle legacy JSON array format from before comma-separated change.
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            $text = implode(', ', array_map('trim', array_filter(array_map('strval', $decoded))));
        }

        if ($text === '') {
            return self::renderMutedMessage('None');
        }
        return self::wrapDetailValue(HTML::createTag('p', [], Convert::raw2xml($text)));
    }

    /**
     * Render suggested FAQs as HTML.
     */
    private static function renderFaqs(?string $value): string
    {
        $decoded = self::decodeJsonArray($value);
        if (!$decoded) {
            return self::renderFallback($value);
        }

        $items = [];
        foreach ($decoded as $faq) {
            if (!is_array($faq)) {
                continue;
            }
            $question = Convert::raw2xml($faq['question'] ?? 'Question');
            $answer = Convert::raw2xml($faq['answer'] ?? 'Answer');
            $items[] = HTML::createTag('li', [], sprintf('<strong>%s:</strong> %s', $question, $answer));
        }

        if (!$items) {
            return self::renderFallback($value);
        }
        return self::wrapDetailValue(HTML::createTag('ul', [], implode('', $items)));
    }

    /**
     * Render the JSON-LD preview for the record.
     */
    private static function renderJsonLd(DataObject $record, GeneratedSeo $metadata): string
    {
        if (!$record instanceof SiteTree) {
            return self::renderMutedMessage('Not available', false);
        }

        $jsonLdService = Injector::inst()->get(JsonLdService::class);
        $payload = $jsonLdService->generateJsonLd($record, $metadata);
        if (!$payload) {
            return self::renderMutedMessage('Not available', false);
        }

        $decoded = json_decode($payload, true);
        $pretty = is_array($decoded)
            ? json_encode($decoded, JsonLdService::ENCODING_OPTIONS)
            : $payload;
        return HTML::createTag(
            'pre',
            ['class' => 'ai-seo-modal__json'],
            Convert::raw2xml($pretty)
        );
    }

    /**
     * Build a length hint element for a field.
     */
    private static function buildLengthHint(string $fieldName, string $value, int $max): string
    {
        $length = strlen($value);
        $classes = 'ai-seo-modal__help';
        if ($length > $max) {
            $classes .= ' text-primary';
        }
        return HTML::createTag(
            'div',
            [
                'class' => $classes,
                'data-ai-seo-count' => $fieldName,
                'data-ai-seo-max' => (string)$max,
            ],
            sprintf('Recommended max %d characters. Current: %d', $max, $length)
        );
    }

    /**
     * Resolve the recommended meta description length.
     */
    private static function getMetaDescriptionMax(): int
    {
        $env = Environment::getEnv(self::ENV_META_DESCRIPTION_MAX);
        if ($env !== null && $env !== '') {
            $max = (int)$env;
            if ($max > 0) {
                return $max;
            }
        }
        return self::DEFAULT_META_DESCRIPTION_MAX;
    }

    /**
     * Render a fallback display for empty JSON fields.
     */
    private static function renderFallback(?string $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return self::wrapDetailValue(HTML::createTag(
                'pre',
                ['class' => 'ai-seo-modal__json'],
                Convert::raw2xml($value)
            ));
        }
        return self::renderMutedMessage('None');
    }

    /**
     * Render muted helper text.
     */
    private static function renderMutedMessage(string $message, bool $wrap = true): string
    {
        $content = HTML::createTag(
            'p',
            ['class' => 'text-muted'],
            Convert::raw2xml($message)
        );
        return $wrap ? self::wrapDetailValue($content) : $content;
    }

    /**
     * Wrap detail values with consistent indentation.
     */
    private static function wrapDetailValue(string $content): string
    {
        return HTML::createTag('div', ['class' => 'ai-seo-modal__detail-value'], $content);
    }

    /**
     * Render a label-style heading that matches regular CMS field labels.
     */
    private static function renderFieldLabel(string $label): string
    {
        return HTML::createTag(
            'label',
            ['class' => 'form__field-label form-label'],
            Convert::raw2xml($label)
        );
    }

    /**
     * Decode a JSON array string into a PHP array.
     *
     * @return array<int, mixed>
     */
    private static function decodeJsonArray(?string $value): array
    {
        if (!$value) {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
