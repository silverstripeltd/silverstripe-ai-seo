<?php

namespace SilverstripeLtd\AiMetadata\Tests\Controllers;

use SilverstripeLtd\AiMetadata\Controllers\AiMetadataController;
use SilverstripeLtd\AiMetadata\ValueObjects\AiMetadataResult;
use SilverstripeLtd\AiMetadata\Forms\AiMetadataForm;
use SilverstripeLtd\AiMetadata\Models\GeneratedMetadata;
use SilverstripeLtd\AiMetadata\Providers\ProviderFactory;
use SilverstripeLtd\AiMetadata\Tests\StubProvider;
use SilverstripeLtd\AiMetadata\Tests\StubProviderFactory;
use SilverstripeLtd\AiMetadata\Tests\RestrictedPage;
use SilverstripeLtd\AiMetadata\Tests\FailingControllerStubProvider;
use SilverStripe\Core\Environment;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FormField;

/**
 * Functional tests for AI metadata controller endpoints.
 */
class AiMetadataControllerTest extends FunctionalTest
{
    protected static $extra_dataobjects = [
        GeneratedMetadata::class,
        RestrictedPage::class,
    ];

    /**
     * Configure a stub provider for controller tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logInWithPermission('ADMIN');

        $provider = new StubProvider(new AiMetadataResult([
            'metaDescription' => 'Generated description',
        ]));
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);
    }

    /**
     * Reset the provider factory after tests.
     */
    protected function tearDown(): void
    {
        Injector::inst()->registerService(new ProviderFactory(), ProviderFactory::class);
        parent::tearDown();
    }

    /**
     * Ensure legacy JSON endpoints are no longer available.
     */
    public function testLegacyJsonEndpointsAreUnavailable(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $fqcn = rawurlencode(SiteTree::class);
        $endpoints = [
            ['GET', '/admin/ai-metadata/' . $fqcn . '/' . $page->ID],
            ['POST', '/admin/ai-metadata/generate/' . $fqcn . '/' . $page->ID],
            ['POST', '/admin/ai-metadata/save/' . $fqcn . '/' . $page->ID],
            ['POST', '/admin/ai-metadata/publish/' . $fqcn . '/' . $page->ID],
            ['POST', '/admin/ai-metadata/unpublish/' . $fqcn . '/' . $page->ID],
        ];

        foreach ($endpoints as [$method, $url]) {
            $response = $method === 'POST'
                ? $this->post($url, [], ['Content-Type' => 'application/json'], null, '{}')
                : $this->get($url);
            $this->assertEquals(404, $response->getStatusCode());
        }
    }

    /**
     * Ensure the meta description hint respects the env-configured maximum.
     */
    public function testMetaDescriptionLengthHintUsesEnvMax(): void
    {
        Environment::setEnv('AI_METADATA_META_DESCRIPTION_MAX', '120');

        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();
        $metadata = $page->getOrCreateAiMetadata();

        $controller = AiMetadataController::create();
        $form = AiMetadataForm::createForRecord($controller, $page, $metadata);
        $field = $form->Fields()->dataFieldByName('MetaDescription');

        $this->assertStringContainsString('data-ai-metadata-max="120"', (string)$field->getDescription());

        Environment::setEnv('AI_METADATA_META_DESCRIPTION_MAX', null);
    }

    /**
     * Ensure the form shows the unpublished changes banner when flagged.
     */
    public function testFormShowsDraftChangesBannerWhenFlagged(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiMetadata();
        $metadata->GeneratedAt = '2026-02-20 10:00:00';
        $metadata->write();

        $controller = AiMetadataController::create();
        $form = AiMetadataForm::createForRecord($controller, $page, $metadata, '', true);
        $banner = $form->Fields()->fieldByName('AiMetadataStatus');
        $this->assertNotNull($banner);
        $this->assertStringContainsString(
            'This page has unpublished changes. AI metadata reflects the draft content'
            . ' and will go live when the page is published.',
            (string)$banner->getContent()
        );
    }

    /**
     * Ensure review confirmation is required before saving.
     */
    public function testSaveRequiresReviewConfirmation(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $controller = AiMetadataController::create();
        $request = new HTTPRequest(
            'POST',
            '/admin/ai-metadata/aiMetadataForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $request->addHeader('X-Formschema-Request', 'schema,state');
        $controller->setRequest($request);

        $form = $controller->AiMetadataForm($request);
        $controller->doSave(['MetaDescription' => 'Draft', 'ReviewConfirmed' => 0], $form);

        $metadata = GeneratedMetadata::get()->filter('ParentID', $page->ID)->first();
        $this->assertEmpty($metadata->ReviewedAt);
        $this->assertSame('', (string)$metadata->MetaDescription);
    }


    /**
     * Ensure saving metadata sets a content hash when missing.
     */
    public function testDoSaveEnsuresContentHash(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $controller = AiMetadataController::create();
        $request = new HTTPRequest(
            'POST',
            '/admin/ai-metadata/aiMetadataForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $request->addHeader('X-Formschema-Request', 'schema,state');
        $controller->setRequest($request);

        $form = $controller->AiMetadataForm($request);
        $controller->doSave(['MetaDescription' => 'Draft', 'ReviewConfirmed' => 1], $form);

        $metadata = GeneratedMetadata::get()->filter('ParentID', $page->ID)->first();
        $this->assertNotEmpty($metadata->ContentHash);
    }

    /**
     * Ensure doSave strips HTML from plain-text metadata fields only.
     */
    public function testDoSaveSanitizesPlainTextFields(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $controller = AiMetadataController::create();
        $request = new HTTPRequest(
            'POST',
            '/admin/ai-metadata/aiMetadataForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $request->addHeader('X-Formschema-Request', 'schema,state');
        $controller->setRequest($request);

        $form = $controller->AiMetadataForm($request);
        $keyEntities = [
            [
                'type' => 'Organization',
                'name' => '<strong>Acme Corp</strong>',
                'sameAs' => 'https://example.com/acme',
            ],
        ];
        $suggestedFaqs = [
            [
                'question' => '<b>What does Acme do?</b>',
                'answer' => '<i>It builds rockets.</i>',
            ],
        ];
        $controller->doSave([
            'MetaDescription' => 'Plain description',
            'OGTitle' => '<strong>Social title</strong>',
            'OGDescription' => '<p>Social description</p>',
            'SummaryLong' => '<div>Long summary</div>',
            'KeyEntities' => $keyEntities,
            'KeyTopics' => '<span>Topic one</span>, Topic two',
            'SuggestedFAQs' => $suggestedFaqs,
            'ReviewConfirmed' => 1,
        ], $form);

        $metadata = GeneratedMetadata::get()->filter('ParentID', $page->ID)->first();
        $this->assertSame('Plain description', $metadata->MetaDescription);
        $this->assertSame('Social title', $metadata->OGTitle);
        $this->assertSame('Social description', $metadata->OGDescription);
        $this->assertSame('Long summary', $metadata->SummaryLong);
        $this->assertSame('Topic one, Topic two', $metadata->KeyTopics);
        $this->assertSame(json_encode($keyEntities), $metadata->KeyEntities);
        $this->assertSame(json_encode($suggestedFaqs), $metadata->SuggestedFAQs);
    }

    /**
     * Ensure regeneration does not persist until submit.
     */
    public function testRegenerateDoesNotPersist(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $controller = AiMetadataController::create();
        $request = new HTTPRequest(
            'POST',
            '/admin/ai-metadata/aiMetadataForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $request->addHeader('X-Formschema-Request', 'schema,state');
        $controller->setRequest($request);

        $form = $controller->AiMetadataForm($request);
        $response = $controller->doRegenerate([], $form);

        $metadata = GeneratedMetadata::get()->filter('ParentID', $page->ID)->first();
        $this->assertEmpty($metadata->GeneratedAt);
        $this->assertSame('', (string)$metadata->MetaDescription);

        $payload = json_decode((string)$response->getBody(), true);
        $this->assertNotEmpty($payload['meta']['generatedAt'] ?? null);
        $this->assertFalse($payload['meta']['hadGeneratedMetadata'] ?? null);
    }

    /**
     * Ensure regeneration metadata indicates prior generation.
     */
    public function testRegenerateMetaIncludesExistingMetadata(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiMetadata();
        $metadata->GeneratedAt = '2026-02-20 10:00:00';
        $metadata->write();

        $controller = AiMetadataController::create();
        $request = new HTTPRequest(
            'POST',
            '/admin/ai-metadata/aiMetadataForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $request->addHeader('X-Formschema-Request', 'schema,state');
        $controller->setRequest($request);

        $form = $controller->AiMetadataForm($request);
        $response = $controller->doRegenerate([], $form);
        $this->assertEquals(200, $response->getStatusCode());

        $payload = json_decode((string)$response->getBody(), true);
        $this->assertTrue($payload['meta']['hadGeneratedMetadata'] ?? false);
    }

    /**
     * Ensure provider errors use the generic message in test mode.
     */
    public function testRegenerateProviderErrorUsesGenericMessage(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $provider = new FailingControllerStubProvider();
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);

        $controller = AiMetadataController::create();
        $request = new HTTPRequest(
            'POST',
            '/admin/ai-metadata/aiMetadataForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $request->addHeader('X-Formschema-Request', 'schema,state');
        $controller->setRequest($request);

        $form = $controller->AiMetadataForm($request);
        $response = $controller->doRegenerate([], $form);

        $body = (string)$response->getBody();
        $this->assertStringContainsString('There was an error connecting to the AI provider', $body);
        $this->assertStringNotContainsString('Provider boom', $body);
    }

    /**
     * Ensure modal class names are exposed via client config.
     */
    public function testClientConfigIncludesModalClasses(): void
    {
        $controller = AiMetadataController::create();
        $controller->setRequest(new HTTPRequest('GET', '/admin/ai-metadata'));
        $config = $controller->getClientConfig();

        $this->assertSame('form-builder-modal', $config['form']['aiMetadataForm']['modalClassName']);
        $this->assertSame('ai-metadata-modal', $config['form']['aiMetadataForm']['className']);
        $this->assertSame('.ai-metadata-modal', $config['form']['aiMetadataForm']['modalSelector']);
    }

    /**
     * Ensure the schema endpoint returns schema and state.
     */
    public function testFormSchemaEndpointReturnsSchema(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $response = $this->get(
            '/admin/ai-metadata/schema/AiMetadataForm/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            null,
            ['X-FormSchema-Request' => 'schema,state']
        );
        $this->assertEquals(200, $response->getStatusCode());

        $payload = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('schema', $payload);
        $this->assertArrayHasKey('state', $payload);
    }

    /**
     * Ensure the generated details toggle is present in the schema.
     */
    public function testFormSchemaIncludesGeneratedDetailsToggle(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $response = $this->get(
            '/admin/ai-metadata/schema/AiMetadataForm/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            null,
            ['X-FormSchema-Request' => 'schema,state']
        );
        $this->assertEquals(200, $response->getStatusCode());

        $payload = json_decode($response->getBody(), true);
        $fields = $payload['schema']['fields'] ?? [];
        $toggleField = null;
        foreach ($fields as $field) {
            if (($field['name'] ?? null) === 'AiMetadataGeneratedInfo') {
                $toggleField = $field;
                break;
            }
        }

        $this->assertNotNull($toggleField);
        $this->assertSame('ToggleCompositeField', $toggleField['component'] ?? null);

        $childNames = array_map(
            static fn(array $child): ?string => $child['name'] ?? null,
            $toggleField['children'] ?? []
        );
        $this->assertContains('KeyEntitiesDisplay', $childNames);
        $this->assertContains('JsonLdSchemaDisplay', $childNames);
    }

    /**
     * Ensure the schema response includes expected fields and actions.
     */
    public function testFormSchemaIncludesFieldsAndActions(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $response = $this->get(
            '/admin/ai-metadata/schema/AiMetadataForm/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            null,
            ['X-FormSchema-Request' => 'schema,state']
        );
        $this->assertEquals(200, $response->getStatusCode());

        $payload = json_decode($response->getBody(), true);
        $fields = $payload['schema']['fields'] ?? [];
        $fieldNames = array_map(
            static fn(array $field): ?string => $field['name'] ?? null,
            $fields
        );
        $this->assertContains('MetaDescription', $fieldNames);
        $this->assertContains('ReviewConfirmed', $fieldNames);

        $actions = $payload['schema']['actions'] ?? [];
        $actionNames = array_map(
            static fn(array $action): ?string => $action['name'] ?? null,
            $actions
        );
        $this->assertContains('action_doSave', $actionNames);
        $this->assertContains('action_doRegenerate', $actionNames);
    }

    /**
     * Ensure length hints and review checkbox classes are configured.
     */
    public function testFormUsesLengthHintsAndReviewCheckbox(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $controller = AiMetadataController::create();
        $request = new HTTPRequest(
            'GET',
            '/admin/ai-metadata/aiMetadataForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $controller->setRequest($request);

        $form = $controller->AiMetadataForm($request);
        $fields = $form->Fields();

        $this->assertNull($fields->dataFieldByName('MetaTitle'));

        $metaDescription = $fields->dataFieldByName('MetaDescription');
        $this->assertNotNull($metaDescription);
        $this->assertStringContainsString(
            'Recommended max 150 characters. Current:',
            (string)$metaDescription->getDescription()
        );
        $this->assertNull($fields->dataFieldByName('MetaDescriptionCount'));

        $reviewField = $fields->dataFieldByName('ReviewConfirmed');
        $this->assertNotNull($reviewField);
        $this->assertInstanceOf(CheckboxField::class, $reviewField);
        $this->assertSame(0, (int)$reviewField->getValue());
        $this->assertSame('I have reviewed the AI metadata', $reviewField->Title());

        $this->assertNull($form->Actions()->fieldByName('ReviewConfirmedToggle'));

        $submitNote = $form->Actions()->fieldByName('AiMetadataSubmitNote');
        $this->assertNull($submitNote);

        $summaryLong = $fields->dataFieldByName('SummaryLong');
        $this->assertNotNull($summaryLong);
        $this->assertSame(6, $summaryLong->getRows());

        $metadata = $page->getOrCreateAiMetadata();
        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $metadata->write();

        $form = $controller->AiMetadataForm($request);
        $fields = $form->Fields();
        $reviewField = $fields->dataFieldByName('ReviewConfirmed');
        $this->assertNotNull($reviewField);
        $this->assertInstanceOf(CheckboxField::class, $reviewField);
        $this->assertSame(0, (int)$reviewField->getValue());
        $this->assertSame('I have reviewed the AI metadata', $reviewField->Title());

        $submitNote = $form->Actions()->fieldByName('AiMetadataSubmitNote');
        $this->assertNotNull($submitNote);
        $this->assertStringContainsString(
            'Metadata will go live when the page is next published',
            (string)$submitNote->getContent()
        );
        $actionNames = array_map(
            static fn(FormField $field): string => $field->getName(),
            $form->Actions()->toArray()
        );
        $this->assertSame(['AiMetadataSubmitNote', 'action_doSave', 'action_doRegenerate'], $actionNames);

        $metadata->ReviewedAt = '2026-02-20 10:00:00';
        $metadata->write();

        $form = $controller->AiMetadataForm($request);
        $fields = $form->Fields();
        $reviewField = $fields->dataFieldByName('ReviewConfirmed');
        $this->assertNotNull($reviewField);
        $this->assertInstanceOf(CheckboxField::class, $reviewField);
        $this->assertSame(1, (int)$reviewField->getValue());
        $this->assertSame('Metadata was reviewed', $reviewField->Title());
        $this->assertNull($form->Actions()->fieldByName('AiMetadataSubmitNote'));
    }

    /**
     * Ensure the status banner reflects generation and review state.
     */
    public function testStatusBannerReflectsReviewState(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $controller = AiMetadataController::create();
        $request = new HTTPRequest(
            'GET',
            '/admin/ai-metadata/aiMetadataForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $controller->setRequest($request);

        $form = $controller->AiMetadataForm($request);
        $banner = $form->Fields()->fieldByName('AiMetadataStatus');
        $this->assertNotNull($banner);
        $this->assertStringContainsString('No AI metadata yet.', (string)$banner->getContent());

        $metadata = $page->getOrCreateAiMetadata();
        $metadata->GeneratedAt = '2026-02-20 10:00:00';
        $metadata->write();

        $form = $controller->AiMetadataForm($request);
        $banner = $form->Fields()->fieldByName('AiMetadataStatus');
        $this->assertNotNull($banner);
        $this->assertStringContainsString('AI metadata ready for review', (string)$banner->getContent());
        $this->assertStringContainsString('Last generated', (string)$banner->getContent());
        $this->assertStringContainsString('data-ai-metadata-timestamp', (string)$banner->getContent());

        $metadata->ReviewedAt = '2026-02-21 09:00:00';
        $metadata->write();

        $form = $controller->AiMetadataForm($request);
        $banner = $form->Fields()->fieldByName('AiMetadataStatus');
        $this->assertNotNull($banner);
        $this->assertStringContainsString('AI metadata reviewed and saved', (string)$banner->getContent());
        $this->assertStringContainsString('Last generated', (string)$banner->getContent());
        $this->assertStringContainsString('Status: Draft only', (string)$banner->getContent());
    }

    /**
     * Ensure stale banner includes the regenerate instruction.
     */
    public function testStaleBannerIncludesRegenerateInstruction(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiMetadata();
        $metadata->ContentHash = 'oldhash';
        $metadata->GeneratedAt = '2026-02-20 10:00:00';
        $metadata->write();

        $controller = AiMetadataController::create();
        $request = new HTTPRequest(
            'GET',
            '/admin/ai-metadata/aiMetadataForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $controller->setRequest($request);

        $form = $controller->AiMetadataForm($request);
        $banner = $form->Fields()->fieldByName('AiMetadataStatus');
        $this->assertNotNull($banner);
        $this->assertStringContainsString(
            'Page content has changed since metadata was generated.'
            . ' To regenerate, click the "Regenerate metadata using AI" button.',
            (string)$banner->getContent()
        );
    }

    /**
     * Ensure schema requests fail when the user cannot edit the record.
     */
    public function testSchemaReturnsForbiddenWhenCannotEdit(): void
    {
        $page = RestrictedPage::create(['Title' => 'Restricted page', 'Content' => 'Content']);
        $page->write();

        $response = $this->get(
            '/admin/ai-metadata/schema/AiMetadataForm/' . $page->ID . '?fqcn=' . rawurlencode(RestrictedPage::class),
            null,
            ['X-FormSchema-Request' => 'schema,state']
        );
        $this->assertEquals(403, $response->getStatusCode());
    }

    /**
     * Ensure regenerate button label reflects generation state.
     */
    public function testRegenerateButtonLabelChanges(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $controller = AiMetadataController::create();
        $request = new HTTPRequest(
            'GET',
            '/admin/ai-metadata/aiMetadataForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $controller->setRequest($request);

        $form = $controller->AiMetadataForm($request);
        $action = $form->Actions()->fieldByName('action_doRegenerate');
        $this->assertSame('Generate metadata using AI', $action->Title());

        $metadata = $page->getOrCreateAiMetadata();
        $metadata->GeneratedAt = '2026-02-20 10:00:00';
        $metadata->write();

        $form = $controller->AiMetadataForm($request);
        $action = $form->Actions()->fieldByName('action_doRegenerate');
        $this->assertSame('Regenerate metadata using AI', $action->Title());
    }
}
