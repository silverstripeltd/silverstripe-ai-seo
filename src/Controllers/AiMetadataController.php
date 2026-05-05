<?php

namespace SilverstripeLtd\AiMetadata\Controllers;

use SilverstripeLtd\AiMetadata\Exceptions\AIProviderException;
use SilverstripeLtd\AiMetadata\Extensions\AiMetadataExtension;
use SilverstripeLtd\AiMetadata\Forms\AiMetadataForm;
use SilverstripeLtd\AiMetadata\Models\GeneratedMetadata;
use SilverstripeLtd\AiMetadata\Services\AiMetadataStateService;
use SilverstripeLtd\AiMetadata\Services\ContentExtractService;
use SilverstripeLtd\AiMetadata\Services\MetadataGenerationService;
use Psr\Log\LoggerInterface;
use SilverStripe\Admin\FormSchemaController;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\Form;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Handles AI metadata form interactions.
 */
class AiMetadataController extends FormSchemaController
{
    public const FORM_NAME_TEMPLATE = 'AiMetadataForm_%s';

    private static $url_segment = 'ai-metadata';

    private static $menu_title = 'AI Metadata';

    private static $menu_priority = -1;

    private static $url_handlers = [
        // Serves the form schema JSON for FormBuilderModal (inherited from FormSchemaController)
        'GET schema/$FormName/$ItemID/$OtherItemID' => 'schema',
        // Receives POST submissions for both doRegenerate and doSave form actions
        'aiMetadataForm/$ItemID' => 'AiMetadataForm',
    ];

    private static $allowed_actions = [
        // The form handler that builds the form and receives POST submissions
        'AiMetadataForm',
        // FormAction handlers dispatched via the AiMetadataForm POST endpoint.
        // They must be listed here because Silverstripe requires form actions in allowed_actions
        // even though they are routed through the form, not as standalone URL endpoints.
        'doRegenerate',
        'doSave',
    ];

    /**
     * Provide client configuration for the modal form schema.
     */
    public function getClientConfig(): array
    {
        $config = parent::getClientConfig();
        $className = 'ai-metadata-modal';
        $modalSelector = '.' . implode('.', preg_split('/\s+/', trim($className)));
        $config['form']['aiMetadataForm'] = [
            'schemaUrl' => $this->Link('schema/AiMetadataForm'),
            'formNameTemplate' => sprintf(AiMetadataController::FORM_NAME_TEMPLATE, '{id}'),
            'modalClassName' => 'form-builder-modal',
            'className' => $className,
            'modalSelector' => $modalSelector,
        ];
        return $config;
    }

    /**
     * Build the CMS modal form for AI metadata editing.
     */
    public function AiMetadataForm(HTTPRequest|string|null $request = null): Form
    {
        $resolvedRequest = $request instanceof HTTPRequest ? $request : $this->getRequest();
        $record = $this->getRecordFromFormRequest($resolvedRequest);
        $metadata = $record->getOrCreateAiMetadata();
        $hasUnpublishedChanges = $this->detectUnpublishedChanges($record);
        return $this->buildForm($record, $metadata, '', $hasUnpublishedChanges);
    }

    /**
     * Handle regenerate action from the form.
     */
    public function doRegenerate(array $data, Form $form): HTTPResponse
    {
        $record = $this->getRecordFromFormRequest($this->getRequest());
        $generationService = Injector::inst()->get(MetadataGenerationService::class);
        $existingMetadata = $record->hasMethod('getAiMetadata') ? $record->getAiMetadata() : null;
        $hadGeneratedMetadata = $existingMetadata && $existingMetadata->GeneratedAt;
        $extraMeta = ['hadGeneratedMetadata' => (bool)$hadGeneratedMetadata];
        try {
            $metadata = $generationService->generateForRecord($record, GeneratedMetadata::create(), false);
        } catch (AIProviderException $exception) {
            $this->logProviderException($exception, $record);
            $message = $this->getProviderErrorMessage($exception);
            $errors = ValidationResult::create()->addError($message);
            $metadata = $record->getOrCreateAiMetadata();
            $form = $this->buildForm($record, $metadata, $message);
            return $this->getSchemaResponseWithMeta($form, $record, $metadata, $errors, ['meta' => $extraMeta]);
        }
        $form = $this->buildForm($record, $metadata, '', (bool)$metadata->hasUnpublishedChanges);
        return $this->getSchemaResponseWithMeta($form, $record, $metadata, null, ['meta' => $extraMeta]);
    }

    /**
     * Handle save action from the form.
     */
    public function doSave(array $data, Form $form): HTTPResponse
    {
        $record = $this->getRecordFromFormRequest($this->getRequest());
        $metadata = $record->getOrCreateAiMetadata();
        $this->applyPayload($metadata, $data);
        if (empty($data['ReviewConfirmed'])) {
            $errors = ValidationResult::create();
            $errors->addFieldError(
                'ReviewConfirmed',
                'Please confirm you have reviewed the metadata before submitting.'
            );
            $hasUnpublishedChanges = $this->detectUnpublishedChanges($record);
            $form = $this->buildForm($record, $metadata, '', $hasUnpublishedChanges);
            return $this->getSchemaResponseWithMeta($form, $record, $metadata, $errors);
        }
        $this->ensureContentHash($metadata, $record);
        $metadata->ReviewedAt = DBDatetime::now()->getValue();
        $validationResult = $metadata->validate();
        if (!$validationResult->isValid()) {
            throw ValidationException::create($validationResult);
        }
        $metadata->write();
        $hasUnpublishedChanges = $this->detectUnpublishedChanges($record);
        $form = $this->buildForm($record, $metadata, '', $hasUnpublishedChanges);
        return $this->getSchemaResponseWithMeta($form, $record, $metadata, $validationResult);
    }

    /**
     * Resolve a record from the form request context.
     */
    private function getRecordFromFormRequest(HTTPRequest $request): DataObject
    {
        $fqcn = (string)($request->getVar('fqcn') ?: $request->param('FQCN'));
        $id = (int)($request->param('ItemID') ?: $request->param('ID'));
        if ($fqcn === '' || $id <= 0) {
            $this->jsonError(400, 'Invalid request parameters');
        }
        $fqcn = urldecode($fqcn);
        if (!class_exists($fqcn) || !DataObject::has_extension($fqcn, AiMetadataExtension::class)) {
            $this->jsonError(400, 'Invalid record class');
        }
        $record = DataObject::get($fqcn)->byID($id);
        if (!$record) {
            $this->jsonError(404, 'Record not found');
        }
        if (!$record->canEdit()) {
            $this->jsonError(403, 'Access denied');
        }
        return $record;
    }

    /**
     * Build the AI metadata form for the record.
     */
    private function buildForm(
        DataObject $record,
        GeneratedMetadata $metadata,
        string $errorMessage = '',
        bool $hasUnpublishedChanges = false
    ): Form {
        $form = AiMetadataForm::createForRecord($this, $record, $metadata, $errorMessage, $hasUnpublishedChanges);
        $form->setValidationResponseCallback(
            function (ValidationResult $errors) use ($form, $record, $metadata): HTTPResponse {
                return $this->getSchemaResponseWithMeta($form, $record, $metadata, $errors);
            }
        );
        return $form;
    }

    /**
     * Build a schema response including metadata state.
     *
     * @param array<string, mixed> $extraData
     */
    private function getSchemaResponseWithMeta(
        Form $form,
        DataObject $record,
        GeneratedMetadata $metadata,
        ?ValidationResult $errors = null,
        array $extraData = []
    ): HTTPResponse {
        $meta = [
            'stale' => AiMetadataForm::isStale($record, $metadata),
            'generatedAt' => $metadata->GeneratedAt,
        ];
        if (isset($extraData['meta']) && is_array($extraData['meta'])) {
            $meta = array_merge($meta, $extraData['meta']);
            unset($extraData['meta']);
        }
        return $this->getSchemaResponse(
            $form->FormAction(),
            $form,
            $errors,
            array_merge(['meta' => $meta], $extraData)
        );
    }

    /**
     * Resolve the provider error message based on the environment.
     */
    private function getProviderErrorMessage(AIProviderException $exception): string
    {
        $runningTests = defined('PHPUNIT_COMPOSER_INSTALL');
        if (Director::isDev() && !$runningTests) {
            return $exception->getMessage();
        }
        return 'There was an error connecting to the AI provider';
    }

    /**
     * Log provider exceptions with context.
     */
    private function logProviderException(AIProviderException $exception, DataObject $record): void
    {
        $logger = Injector::inst()->get(LoggerInterface::class);
        $logger->error('AI provider request failed', [
            'exception' => $exception,
            'recordClass' => $record->ClassName,
            'recordId' => $record->ID,
        ]);
    }

    /**
     * Detect whether the record has unpublished draft changes.
     */
    private function detectUnpublishedChanges(DataObject $record): bool
    {
        $stateService = Injector::inst()->get(AiMetadataStateService::class);
        $state = $stateService->getState($record, $record->getAiMetadata());
        return $state['hasUnpublishedChanges'];
    }

    /**
     * Apply payload values to the metadata record.
     *
     * @param array<string, mixed> $payload
     */
    private function applyPayload(GeneratedMetadata $metadata, array $payload): void
    {
        $metadata->MetaDescription = $this->sanitizePlainTextField(
            $payload['MetaDescription'] ?? null,
            $metadata->MetaDescription
        );
        $metadata->OGTitle = $this->sanitizePlainTextField($payload['OGTitle'] ?? null, $metadata->OGTitle);
        $metadata->OGDescription = $this->sanitizePlainTextField(
            $payload['OGDescription'] ?? null,
            $metadata->OGDescription
        );
        $metadata->SummaryLong = $this->sanitizePlainTextField(
            $payload['SummaryLong'] ?? null,
            $metadata->SummaryLong
        );
        $metadata->KeyEntities = $this->normalizeJsonField($payload['KeyEntities'] ?? null, $metadata->KeyEntities);
        $metadata->KeyTopics = $this->sanitizePlainTextField($payload['KeyTopics'] ?? null, $metadata->KeyTopics);
        $metadata->SuggestedFAQs = $this->normalizeJsonField(
            $payload['SuggestedFAQs'] ?? null,
            $metadata->SuggestedFAQs
        );
        $metadata->ContentHash = $payload['ContentHash'] ?? $metadata->ContentHash;
        $metadata->GeneratedAt = $payload['GeneratedAt'] ?? $metadata->GeneratedAt;
        $metadata->GenerationNote = $payload['GenerationNote'] ?? $metadata->GenerationNote;
    }

    /**
     * Ensure metadata content hash is populated.
     */
    private function ensureContentHash(GeneratedMetadata $metadata, DataObject $record): void
    {
        if ($metadata->ContentHash) {
            return;
        }

        $contentExtractor = Injector::inst()->get(ContentExtractService::class);
        $extracted = $contentExtractor->extractPublished($record);
        $metadata->ContentHash = $contentExtractor->computeHash($extracted['content']);
    }

    /**
     * Normalize JSON-like input into a string payload.
     */
    private function normalizeJsonField(mixed $value, ?string $fallback): ?string
    {
        if (is_array($value)) {
            return json_encode($value);
        }
        if (is_string($value)) {
            return $value;
        }
        return $fallback;
    }

    /**
     * Sanitize a plain-text field from the save payload.
     */
    private function sanitizePlainTextField(mixed $value, ?string $fallback): ?string
    {
        if (!is_string($value)) {
            return $fallback;
        }
        return strip_tags($value);
    }
}
