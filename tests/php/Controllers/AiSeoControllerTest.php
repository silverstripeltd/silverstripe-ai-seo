<?php

namespace SilverstripeLtd\AiSeo\Tests\Controllers;

use SilverstripeLtd\AiSeo\Controllers\AiSeoController;
use SilverstripeLtd\AiSeo\ValueObjects\AiSeoResult;
use SilverstripeLtd\AiSeo\Forms\AiSeoForm;
use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverstripeLtd\AiSeo\Providers\ProviderFactory;
use SilverstripeLtd\AiSeo\Services\AiSeoRegenerateRateLimiter;
use SilverstripeLtd\AiSeo\Tests\StubProvider;
use SilverstripeLtd\AiSeo\Tests\StubProviderFactory;
use SilverstripeLtd\AiSeo\Tests\RestrictedPage;
use SilverstripeLtd\AiSeo\Tests\FailingControllerStubProvider;
use SilverStripe\Core\Environment;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FormField;

/**
 * Functional tests for AI SEO controller endpoints.
 */
class AiSeoControllerTest extends FunctionalTest
{
    protected static $extra_dataobjects = [
        GeneratedSeo::class,
        RestrictedPage::class,
    ];

    /**
     * Configure a stub provider for controller tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->logInWithPermission('ADMIN');

        $provider = new StubProvider(new AiSeoResult([
            'metaDescription' => 'Generated description',
        ]));
        Injector::inst()->registerService(new StubProviderFactory($provider), ProviderFactory::class);
    }

    /**
     * Reset the provider factory after tests.
     */
    protected function tearDown(): void
    {
        Config::modify()->set(AiSeoRegenerateRateLimiter::class, 'max_requests', 10);
        Config::modify()->set(AiSeoRegenerateRateLimiter::class, 'window_seconds', 300);
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
            ['GET', '/admin/ai-seo/' . $fqcn . '/' . $page->ID],
            ['POST', '/admin/ai-seo/generate/' . $fqcn . '/' . $page->ID],
            ['POST', '/admin/ai-seo/save/' . $fqcn . '/' . $page->ID],
            ['POST', '/admin/ai-seo/publish/' . $fqcn . '/' . $page->ID],
            ['POST', '/admin/ai-seo/unpublish/' . $fqcn . '/' . $page->ID],
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
        Environment::setEnv('AI_SEO_META_DESCRIPTION_MAX', '120');

        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();
        $metadata = $page->getOrCreateAiSeo();

        $controller = AiSeoController::create();
        $form = AiSeoForm::createForRecord($controller, $page, $metadata);
        $field = $form->Fields()->dataFieldByName('MetaDescription');

        $this->assertStringContainsString('data-ai-seo-max="120"', (string)$field->getDescription());

        Environment::setEnv('AI_SEO_META_DESCRIPTION_MAX', null);
    }

    /**
     * Ensure the form shows the unpublished changes banner when flagged.
     */
    public function testFormShowsDraftChangesBannerWhenFlagged(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiSeo();
        $metadata->GeneratedAt = '2026-02-20 10:00:00';
        $metadata->write();

        $controller = AiSeoController::create();
        $form = AiSeoForm::createForRecord($controller, $page, $metadata, '', true);
        $banner = $form->Fields()->fieldByName('AiSeoStatus');
        $this->assertNotNull($banner);
        $this->assertStringContainsString(
            'This page has unpublished changes. Generated AI SEO reflects the draft content'
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

        $controller = AiSeoController::create();
        $request = new HTTPRequest(
            'POST',
            '/admin/ai-seo/aiSeoForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $request->addHeader('X-Formschema-Request', 'schema,state');
        $controller->setRequest($request);

        $form = $controller->AiSeoForm($request);
        $controller->doSave(['MetaDescription' => 'Draft', 'ReviewConfirmed' => 0], $form);

        $metadata = GeneratedSeo::get()->filter('ParentID', $page->ID)->first();
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

        $controller = AiSeoController::create();
        $request = new HTTPRequest(
            'POST',
            '/admin/ai-seo/aiSeoForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $request->addHeader('X-Formschema-Request', 'schema,state');
        $controller->setRequest($request);

        $form = $controller->AiSeoForm($request);
        $controller->doSave(['MetaDescription' => 'Draft', 'ReviewConfirmed' => 1], $form);

        $metadata = GeneratedSeo::get()->filter('ParentID', $page->ID)->first();
        $this->assertNotEmpty($metadata->ContentHash);
    }

    /**
     * Ensure doSave strips HTML from plain-text metadata fields only.
     */
    public function testDoSaveSanitizesPlainTextFields(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $controller = AiSeoController::create();
        $request = new HTTPRequest(
            'POST',
            '/admin/ai-seo/aiSeoForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $request->addHeader('X-Formschema-Request', 'schema,state');
        $controller->setRequest($request);

        $form = $controller->AiSeoForm($request);
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

        $metadata = GeneratedSeo::get()->filter('ParentID', $page->ID)->first();
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

        $controller = AiSeoController::create();
        $request = new HTTPRequest(
            'POST',
            '/admin/ai-seo/aiSeoForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $request->addHeader('X-Formschema-Request', 'schema,state');
        $controller->setRequest($request);

        $form = $controller->AiSeoForm($request);
        $response = $controller->doRegenerate([], $form);

        $metadata = GeneratedSeo::get()->filter('ParentID', $page->ID)->first();
        $this->assertEmpty($metadata->GeneratedAt);
        $this->assertSame('', (string)$metadata->MetaDescription);

        $payload = json_decode((string)$response->getBody(), true);
        $this->assertNotEmpty($payload['meta']['generatedAt'] ?? null);
        $this->assertFalse($payload['meta']['hadGeneratedSeo'] ?? null);
    }

    /**
     * Ensure regeneration metadata indicates prior generation.
     */
    public function testRegenerateMetaIncludesExistingMetadata(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiSeo();
        $metadata->GeneratedAt = '2026-02-20 10:00:00';
        $metadata->write();

        $controller = AiSeoController::create();
        $request = new HTTPRequest(
            'POST',
            '/admin/ai-seo/aiSeoForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $request->addHeader('X-Formschema-Request', 'schema,state');
        $controller->setRequest($request);

        $form = $controller->AiSeoForm($request);
        $response = $controller->doRegenerate([], $form);
        $this->assertEquals(200, $response->getStatusCode());

        $payload = json_decode((string)$response->getBody(), true);
        $this->assertTrue($payload['meta']['hadGeneratedSeo'] ?? false);
    }

    /**
     * Ensure regenerate rate-limit failures return schema errors and the cooldown banner.
     */
    public function testRegenerateReturnsRateLimitSchemaError(): void
    {
        Config::modify()->set(AiSeoRegenerateRateLimiter::class, 'max_requests', 1);

        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $session = new Session([]);
        $controller = $this->createRegenerateController($page, $session);

        $firstResponse = $controller->doRegenerate([], $controller->AiSeoForm($controller->getRequest()));
        $secondResponse = $controller->doRegenerate([], $controller->AiSeoForm($controller->getRequest()));

        $this->assertEquals(200, $firstResponse->getStatusCode());
        $this->assertEquals(429, $secondResponse->getStatusCode());
        $this->assertNotEmpty($secondResponse->getHeader('Retry-After'));
        $this->assertStringContainsString(
            'Too many AI SEO regenerate requests for this page.',
            (string)$secondResponse->getBody()
        );
        $this->assertStringContainsString('ai-seo-modal__banner--error', (string)$secondResponse->getBody());
    }

    /**
     * Ensure regenerate limits are tracked separately for each page.
     */
    public function testRegenerateRateLimitIsScopedPerPage(): void
    {
        Config::modify()->set(AiSeoRegenerateRateLimiter::class, 'max_requests', 1);

        $firstPage = SiteTree::create(['Title' => 'First page', 'Content' => 'Content']);
        $firstPage->write();
        $secondPage = SiteTree::create(['Title' => 'Second page', 'Content' => 'Content']);
        $secondPage->write();

        $session = new Session([]);

        $firstController = $this->createRegenerateController($firstPage, $session);
        $firstResponse = $firstController->doRegenerate(
            [],
            $firstController->AiSeoForm($firstController->getRequest())
        );

        $secondController = $this->createRegenerateController($secondPage, $session);
        $secondResponse = $secondController->doRegenerate(
            [],
            $secondController->AiSeoForm($secondController->getRequest())
        );

        $repeatFirstResponse = $firstController->doRegenerate(
            [],
            $firstController->AiSeoForm($firstController->getRequest())
        );

        $this->assertEquals(200, $firstResponse->getStatusCode());
        $this->assertEquals(200, $secondResponse->getStatusCode());
        $this->assertEquals(429, $repeatFirstResponse->getStatusCode());
    }

    /**
     * Ensure rate-limit config overrides change both the threshold and cooldown window.
     */
    public function testRegenerateRateLimitHonoursConfigOverrides(): void
    {
        Config::modify()->set(AiSeoRegenerateRateLimiter::class, 'max_requests', 1);
        Config::modify()->set(AiSeoRegenerateRateLimiter::class, 'window_seconds', 1);

        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $session = new Session([]);
        $controller = $this->createRegenerateController($page, $session);

        $firstResponse = $controller->doRegenerate([], $controller->AiSeoForm($controller->getRequest()));
        $secondResponse = $controller->doRegenerate([], $controller->AiSeoForm($controller->getRequest()));
        sleep(2);
        $thirdResponse = $controller->doRegenerate([], $controller->AiSeoForm($controller->getRequest()));

        $this->assertEquals(200, $firstResponse->getStatusCode());
        $this->assertEquals(429, $secondResponse->getStatusCode());
        $this->assertSame('1', $secondResponse->getHeader('Retry-After'));
        $this->assertEquals(200, $thirdResponse->getStatusCode());
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

        $controller = AiSeoController::create();
        $request = new HTTPRequest(
            'POST',
            '/admin/ai-seo/aiSeoForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $request->addHeader('X-Formschema-Request', 'schema,state');
        $controller->setRequest($request);

        $form = $controller->AiSeoForm($request);
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
        $controller = AiSeoController::create();
        $controller->setRequest(new HTTPRequest('GET', '/admin/ai-seo'));
        $config = $controller->getClientConfig();

        $this->assertSame('form-builder-modal', $config['form']['aiSeoForm']['modalClassName']);
        $this->assertSame('ai-seo-modal', $config['form']['aiSeoForm']['className']);
        $this->assertSame('.ai-seo-modal', $config['form']['aiSeoForm']['modalSelector']);
    }

    /**
     * Ensure the schema endpoint returns schema and state.
     */
    public function testFormSchemaEndpointReturnsSchema(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $response = $this->get(
            '/admin/ai-seo/schema/AiSeoForm/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
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
            '/admin/ai-seo/schema/AiSeoForm/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
            null,
            ['X-FormSchema-Request' => 'schema,state']
        );
        $this->assertEquals(200, $response->getStatusCode());

        $payload = json_decode($response->getBody(), true);
        $fields = $payload['schema']['fields'] ?? [];
        $toggleField = null;
        foreach ($fields as $field) {
            if (($field['name'] ?? null) === 'AiSeoGeneratedInfo') {
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
            '/admin/ai-seo/schema/AiSeoForm/' . $page->ID . '?fqcn=' . rawurlencode(SiteTree::class),
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
        $this->assertNotContains('action_doSave', $actionNames);
        $this->assertContains('action_doRegenerate', $actionNames);
    }

    /**
     * Ensure length hints and review checkbox classes are configured.
     */
    public function testFormUsesLengthHintsAndReviewCheckbox(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $controller = AiSeoController::create();
        $request = new HTTPRequest(
            'GET',
            '/admin/ai-seo/aiSeoForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $controller->setRequest($request);

        $form = $controller->AiSeoForm($request);
        $fields = $form->Fields();

        $this->assertNull($fields->dataFieldByName('MetaTitle'));

        $metaDescription = $fields->dataFieldByName('MetaDescription');
        $this->assertNotNull($metaDescription);
        $this->assertStringContainsString(
            'Recommended max 150 characters. Current:',
            (string)$metaDescription->getDescription()
        );
        $this->assertNull($fields->dataFieldByName('MetaDescriptionCount'));

        $keyTopicsHeader = $fields->fieldByName('AiSeoKeyTopicsHeader');
        $this->assertNotNull($keyTopicsHeader);
        $this->assertStringContainsString('form__field-label form-label', (string)$keyTopicsHeader->getContent());
        $this->assertStringContainsString('Key topics', (string)$keyTopicsHeader->getContent());

        $reviewField = $fields->dataFieldByName('ReviewConfirmed');
        $this->assertNotNull($reviewField);
        $this->assertInstanceOf(CheckboxField::class, $reviewField);
        $this->assertSame(0, (int)$reviewField->getValue());
        $this->assertSame('I have reviewed the generated AI SEO', $reviewField->Title());

        $this->assertNull($form->Actions()->fieldByName('ReviewConfirmedToggle'));

        $submitNote = $form->Actions()->fieldByName('AiSeoSubmitNote');
        $this->assertNull($submitNote);

        $summaryLong = $fields->dataFieldByName('SummaryLong');
        $this->assertNotNull($summaryLong);
        $this->assertSame(3, $summaryLong->getRows());
        $this->assertNull($form->Actions()->fieldByName('action_doSave'));
        $regenerateAction = $form->Actions()->fieldByName('action_doRegenerate');
        $this->assertNotNull($regenerateAction);
        $this->assertSame('Generate SEO', $regenerateAction->Title());
        $this->assertSame('info', $regenerateAction->getSchemaData()['data']['buttonStyle'] ?? null);

        $metadata = $page->getOrCreateAiSeo();
        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $metadata->write();

        $form = $controller->AiSeoForm($request);
        $fields = $form->Fields();
        $reviewField = $fields->dataFieldByName('ReviewConfirmed');
        $this->assertNotNull($reviewField);
        $this->assertInstanceOf(CheckboxField::class, $reviewField);
        $this->assertSame(0, (int)$reviewField->getValue());
        $this->assertSame('I have reviewed the generated AI SEO', $reviewField->Title());

        $submitNote = $form->Actions()->fieldByName('AiSeoSubmitNote');
        $this->assertNotNull($submitNote);
        $this->assertStringContainsString(
            'SEO will go live when the page is next published',
            (string)$submitNote->getContent()
        );
        $this->assertStringContainsString('click Apply SEO', (string)$submitNote->getContent());
        $actionNames = array_map(
            static fn(FormField $field): string => $field->getName(),
            $form->Actions()->toArray()
        );
        $this->assertSame(['AiSeoSubmitNote', 'action_doRegenerate', 'action_doSave'], $actionNames);
        $regenerateAction = $form->Actions()->fieldByName('action_doRegenerate');
        $this->assertNotNull($regenerateAction);
        $this->assertSame('Regenerate', $regenerateAction->Title());
        $this->assertSame('info', $regenerateAction->getSchemaData()['data']['buttonStyle'] ?? null);
        $saveAction = $form->Actions()->fieldByName('action_doSave');
        $this->assertNotNull($saveAction);
        $this->assertSame('Apply SEO', $saveAction->Title());
        $this->assertSame('info', $saveAction->getSchemaData()['data']['buttonStyle'] ?? null);

        $metadata->ReviewedAt = '2026-02-20 10:00:00';
        $metadata->write();

        $form = $controller->AiSeoForm($request);
        $fields = $form->Fields();
        $reviewField = $fields->dataFieldByName('ReviewConfirmed');
        $this->assertNotNull($reviewField);
        $this->assertInstanceOf(CheckboxField::class, $reviewField);
        $this->assertSame(1, (int)$reviewField->getValue());
        $this->assertSame('Generated AI SEO was reviewed', $reviewField->Title());
        $this->assertNull($form->Actions()->fieldByName('AiSeoSubmitNote'));
        $this->assertNotNull($form->Actions()->fieldByName('action_doSave'));
    }

    /**
     * Ensure the status banner reflects generation and review state.
     */
    public function testStatusBannerReflectsReviewState(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $controller = AiSeoController::create();
        $request = new HTTPRequest(
            'GET',
            '/admin/ai-seo/aiSeoForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $controller->setRequest($request);

        $form = $controller->AiSeoForm($request);
        $banner = $form->Fields()->fieldByName('AiSeoStatus');
        $this->assertNotNull($banner);
        $this->assertStringContainsString('No generated AI SEO yet.', (string)$banner->getContent());

        $metadata = $page->getOrCreateAiSeo();
        $metadata->GeneratedAt = '2026-02-20 10:00:00';
        $metadata->write();

        $form = $controller->AiSeoForm($request);
        $banner = $form->Fields()->fieldByName('AiSeoStatus');
        $this->assertNotNull($banner);
        $this->assertStringContainsString('Generated AI SEO ready for review', (string)$banner->getContent());
        $this->assertStringContainsString('Last generated', (string)$banner->getContent());
        $this->assertStringContainsString('data-ai-seo-timestamp', (string)$banner->getContent());

        $metadata->ReviewedAt = '2026-02-21 09:00:00';
        $metadata->write();

        $form = $controller->AiSeoForm($request);
        $banner = $form->Fields()->fieldByName('AiSeoStatus');
        $this->assertNotNull($banner);
        $this->assertStringContainsString('Generated AI SEO reviewed and saved', (string)$banner->getContent());
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

        $metadata = $page->getOrCreateAiSeo();
        $metadata->ContentHash = 'oldhash';
        $metadata->GeneratedAt = '2026-02-20 10:00:00';
        $metadata->write();

        $controller = AiSeoController::create();
        $request = new HTTPRequest(
            'GET',
            '/admin/ai-seo/aiSeoForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $controller->setRequest($request);

        $form = $controller->AiSeoForm($request);
        $banner = $form->Fields()->fieldByName('AiSeoStatus');
        $this->assertNotNull($banner);
        $this->assertStringContainsString(
            'Page content has changed since SEO was generated.'
            . ' To regenerate, click the "Regenerate" button.',
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
            '/admin/ai-seo/schema/AiSeoForm/' . $page->ID . '?fqcn=' . rawurlencode(RestrictedPage::class),
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

        $controller = AiSeoController::create();
        $request = new HTTPRequest(
            'GET',
            '/admin/ai-seo/aiSeoForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession(new Session([]));
        $request->setRouteParams(['ItemID' => $page->ID]);
        $controller->setRequest($request);

        $form = $controller->AiSeoForm($request);
        $action = $form->Actions()->fieldByName('action_doRegenerate');
        $this->assertSame('Generate SEO', $action->Title());

        $metadata = $page->getOrCreateAiSeo();
        $metadata->GeneratedAt = '2026-02-20 10:00:00';
        $metadata->write();

        $form = $controller->AiSeoForm($request);
        $action = $form->Actions()->fieldByName('action_doRegenerate');
        $this->assertSame('Regenerate', $action->Title());
    }

    private function createRegenerateController(SiteTree $page, Session $session): AiSeoController
    {
        $controller = AiSeoController::create();
        $request = new HTTPRequest(
            'POST',
            '/admin/ai-seo/aiSeoForm/' . $page->ID,
            ['fqcn' => SiteTree::class]
        );
        $request->setSession($session);
        $request->setRouteParams(['ItemID' => $page->ID]);
        $request->addHeader('X-Formschema-Request', 'schema,state');
        $controller->setRequest($request);
        return $controller;
    }
}
