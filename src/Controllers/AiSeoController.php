<?php

namespace SilverstripeLtd\AiSeo\Controllers;

use SilverstripeLtd\AiSeo\Exceptions\AIProviderException;
use SilverstripeLtd\AiSeo\Extensions\AiSeoExtension;
use SilverstripeLtd\AiSeo\Forms\AiSeoForm;
use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverstripeLtd\AiSeo\Services\AiSeoStateService;
use SilverstripeLtd\AiSeo\Services\AiSeoRegenerateRateLimiter;
use SilverstripeLtd\AiSeo\Services\ContentExtractService;
use SilverstripeLtd\AiSeo\Services\SeoGenerationService;
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
use SilverStripe\Security\Security;

/**
 * Handles AI SEO form interactions.
 */
class AiSeoController extends FormSchemaController
{
    public const FORM_NAME_TEMPLATE = 'AiSeoForm_%s';

    private static $url_segment = 'ai-seo';

    private static $menu_title = 'AI SEO';

    private static $menu_priority = -1;

    private static $url_handlers = [
        // Serves the form schema JSON for FormBuilderModal (inherited from FormSchemaController)
        'GET schema/$FormName/$ItemID/$OtherItemID' => 'schema',
        // Receives POST submissions for both doRegenerate and doSave form actions
        'aiSeoForm/$ItemID' => 'AiSeoForm',
    ];

    private static $allowed_actions = [
        // The form handler that builds the form and receives POST submissions
        'AiSeoForm',
        // FormAction handlers dispatched via the AiSeoForm POST endpoint.
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
        $className = 'ai-seo-modal';
        $modalSelector = '.' . implode('.', preg_split('/\s+/', trim($className)));
        $config['form']['aiSeoForm'] = [
            'schemaUrl' => $this->Link('schema/AiSeoForm'),
            'formNameTemplate' => sprintf(AiSeoController::FORM_NAME_TEMPLATE, '{id}'),
            'modalClassName' => 'form-builder-modal',
            'className' => $className,
            'modalSelector' => $modalSelector,
        ];
        return $config;
    }

    /**
     * Build the CMS modal form for AI SEO editing.
     */
    public function AiSeoForm(HTTPRequest|string|null $request = null): Form
    {
        $resolvedRequest = $request instanceof HTTPRequest ? $request : $this->getRequest();
        $record = $this->getRecordFromFormRequest($resolvedRequest);
        $metadata = $record->getOrCreateAiSeo();
        $hasUnpublishedChanges = $this->detectUnpublishedChanges($record);
        return $this->buildForm($record, $metadata, '', $hasUnpublishedChanges);
    }

    /**
     * Handle regenerate action from the form.
     */
    public function doRegenerate(array $data, Form $form): HTTPResponse
    {
        $record = $this->getRecordFromFormRequest($this->getRequest());
        $generationService = Injector::inst()->get(SeoGenerationService::class);
        $existingMetadata = $record->hasMethod('getAiSeo') ? $record->getAiSeo() : null;
        $hadGeneratedSeo = $existingMetadata && $existingMetadata->GeneratedAt;
        $extraMeta = ['hadGeneratedSeo' => (bool)$hadGeneratedSeo];
        $retryAfter = $this->getRegenerateRateLimiter()->consumeRequest(
            $this->getRequest()->getSession(),
            $this->getCurrentMemberId(),
            (int)$record->ID
        );
        if ($retryAfter > 0) {
            return $this->buildRateLimitedRegenerateResponse($record, $retryAfter, $extraMeta);
        }
        try {
            $metadata = $generationService->generateForRecord($record, GeneratedSeo::create(), false);
        } catch (AIProviderException $exception) {
            $this->logProviderException($exception, $record);
            $message = $this->getProviderErrorMessage($exception);
            $errors = ValidationResult::create()->addError($message);
            $metadata = $record->getOrCreateAiSeo();
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
        $metadata = $record->getOrCreateAiSeo();
        $this->applyPayload($metadata, $data);
        if (empty($data['ReviewConfirmed'])) {
            $errors = ValidationResult::create();
            $errors->addFieldError(
                'ReviewConfirmed',
                'Please confirm you have reviewed the SEO before submitting.'
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
        if (!class_exists($fqcn) || !DataObject::has_extension($fqcn, AiSeoExtension::class)) {
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
     * Build the AI SEO form for the record.
     */
    private function buildForm(
        DataObject $record,
        GeneratedSeo $metadata,
        string $errorMessage = '',
        bool $hasUnpublishedChanges = false
    ): Form {
        $form = AiSeoForm::createForRecord($this, $record, $metadata, $errorMessage, $hasUnpublishedChanges);
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
        GeneratedSeo $metadata,
        ?ValidationResult $errors = null,
        array $extraData = []
    ): HTTPResponse {
        $meta = [
            'stale' => AiSeoForm::isStale($record, $metadata),
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
     * Build the rate-limited schema response for regenerate requests.
     *
     * @param array<string, mixed> $extraMeta
     */
    private function buildRateLimitedRegenerateResponse(
        DataObject $record,
        int $retryAfter,
        array $extraMeta
    ): HTTPResponse {
        $metadata = $record->getOrCreateAiSeo();
        $message = $this->getRateLimitErrorMessage($retryAfter);
        $errors = ValidationResult::create()->addError($message);
        $form = $this->buildForm($record, $metadata, $message, $this->detectUnpublishedChanges($record));
        $response = $this->getSchemaResponseWithMeta($form, $record, $metadata, $errors, ['meta' => $extraMeta]);
        $response->setStatusCode(429);
        $response->addHeader('Retry-After', (string)$retryAfter);
        return $response;
    }

    private function getCurrentMemberId(): int
    {
        return (int)(Security::getCurrentUser()?->ID ?? 0);
    }

    private function getRateLimitErrorMessage(int $retryAfter): string
    {
        return sprintf(
            'Too many AI SEO regenerate requests for this page. Please wait %s and try again.',
            $this->formatCooldownDuration($retryAfter)
        );
    }

    private function formatCooldownDuration(int $retryAfter): string
    {
        if ($retryAfter >= 60) {
            $minutes = (int)ceil($retryAfter / 60);
            return sprintf('%d %s', $minutes, $minutes === 1 ? 'minute' : 'minutes');
        }
        return sprintf('%d %s', $retryAfter, $retryAfter === 1 ? 'second' : 'seconds');
    }

    private function getRegenerateRateLimiter(): AiSeoRegenerateRateLimiter
    {
        return Injector::inst()->get(AiSeoRegenerateRateLimiter::class);
    }

    /**
     * Detect whether the record has unpublished draft changes.
     */
    private function detectUnpublishedChanges(DataObject $record): bool
    {
        $stateService = Injector::inst()->get(AiSeoStateService::class);
        $state = $stateService->getState($record, $record->getAiSeo());
        return $state['hasUnpublishedChanges'];
    }

    /**
     * Apply payload values to the metadata record.
     *
     * @param array<string, mixed> $payload
     */
    private function applyPayload(GeneratedSeo $metadata, array $payload): void
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
    private function ensureContentHash(GeneratedSeo $metadata, DataObject $record): void
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
