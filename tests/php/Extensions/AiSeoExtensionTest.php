<?php

namespace SilverstripeLtd\AiSeo\Tests\Extensions;

use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverstripeLtd\AiSeo\Tests\RestrictedPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Versioned\Versioned;
use TractorCow\Fluent\Model\Locale;
use TractorCow\Fluent\State\FluentState;

/**
 * Covers extension behaviour for AI SEO.
 */
class AiSeoExtensionTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        GeneratedSeo::class,
        RestrictedPage::class,
    ];

    public static function getExtraDataObjects(): array
    {
        $objects = static::$extra_dataobjects;
        if (class_exists(Locale::class)) {
            $objects[] = Locale::class;
        }
        return $objects;
    }

    private Locale $defaultLocale;
    private Locale $targetLocale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logInWithPermission('ADMIN');
        if (!class_exists(Locale::class)) {
            $this->markTestSkipped('Fluent is required for this test');
        }

        $defaultLocale = Locale::create([
            'Title' => 'English',
            'Locale' => 'en_NZ',
            'IsGlobalDefault' => 1,
        ]);
        $defaultLocale->write();
        $targetLocale = Locale::create([
            'Title' => 'Te Reo Maori',
            'Locale' => 'mi_NZ',
            'IsGlobalDefault' => 0,
        ]);
        $targetLocale->write();

        Locale::clearCached();
        $this->defaultLocale = Locale::get()->filter('Locale', 'en_NZ')->first();
        $this->targetLocale = Locale::get()->filter('Locale', 'mi_NZ')->first();
        FluentState::singleton()->setLocale($this->defaultLocale->Locale);
    }

    protected function tearDown(): void
    {
        if (class_exists(Locale::class)) {
            Locale::clearCached();
            FluentState::singleton()->setLocale(null);
        }
        parent::tearDown();
    }

    /**
     * Ensure metadata is created and linked to the page.
     */
    public function testGetOrCreateAiSeo(): void
    {
        $page = SiteTree::create(['Title' => 'Test page']);
        $page->write();

        $metadata = $page->getOrCreateAiSeo();
        $this->assertTrue($metadata->exists());
        $this->assertEquals($page->ID, $metadata->ParentID);
        $this->assertEquals($page->ClassName, $metadata->ParentClass);
        $this->assertFalse($page->hasField('AiSeoID'));
        $this->assertSame($metadata->ID, $page->getAiSeo()->ID);
    }

    /**
     * Ensure CMS fields use the AI-managed description.
     */
    public function testUpdateCMSFieldsReplacesMetaDescription(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'MetaDescription' => 'Original description']);
        $page->write();

        $fields = $page->getCMSFields();
        $field = $fields->dataFieldByName('MetaDescription');

        $this->assertInstanceOf(ReadonlyField::class, $field);
        $this->assertStringContainsString('Generate SEO with AI modal', (string)$field->getDescription());
        $this->assertStringContainsString('Previous value:', (string)$field->getDescription());
    }

    /**
     * Ensure editable pages expose toolbar context but no old major action button.
     */
    public function testUpdateCMSFieldsAddsToolbarContextWithoutMajorActionButtonInDefaultLocale(): void
    {
        $page = SiteTree::create(['Title' => 'Toolbar test']);
        $page->write();

        $this->assertTrue($page->canAiSeoInCurrentLocale());
        $fields = $page->getCMSFields();
        $actions = $page->getCMSActions();
        $recordClass = $fields->dataFieldByName('AiSeoRecordClass');

        $this->assertInstanceOf(HiddenField::class, $recordClass);
        $this->assertSame($page->ClassName, $recordClass->dataValue());
        $this->assertNull($actions->fieldByName('MajorActions')->fieldByName('action_AiSeoAction'));
    }

    /**
     * Ensure non-default Fluent locales do not expose toolbar context.
     */
    public function testUpdateCMSFieldsSkipsToolbarContextInNonDefaultLocale(): void
    {
        $page = SiteTree::create(['Title' => 'Toolbar test']);
        $page->write();

        FluentState::singleton()->setLocale($this->targetLocale->Locale);
        /** @var SiteTree|null $targetPage */
        $targetPage = SiteTree::get()->setUseCache(false)->byID($page->ID);

        $this->assertNotNull($targetPage);
        $this->assertFalse($targetPage->canAiSeoInCurrentLocale());

        $fields = $targetPage->getCMSFields();
        $actions = $targetPage->getCMSActions();

        $this->assertNull($fields->dataFieldByName('AiSeoRecordClass'));
        $this->assertNotInstanceOf(ReadonlyField::class, $fields->dataFieldByName('MetaDescription'));
        $this->assertNull($actions->fieldByName('MajorActions')->fieldByName('action_AiSeoAction'));
    }

    /**
     * Ensure non-editable pages do not expose toolbar context.
     */
    public function testUpdateCMSFieldsSkipsToolbarContextWhenRecordCannotEdit(): void
    {
        $page = RestrictedPage::create(['Title' => 'Restricted toolbar test']);
        $page->write();

        $fields = $page->getCMSFields();
        $actions = $page->getCMSActions();

        $this->assertNull($fields->dataFieldByName('AiSeoRecordClass'));
        $this->assertNull($actions->fieldByName('MajorActions')->fieldByName('action_AiSeoAction'));
    }

    /**
     * Ensure publishing a page publishes reviewed metadata.
     */
    public function testOnAfterPublishPublishesReviewedMetadata(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiSeo();
        $metadata->MetaDescription = 'AI description';
        $metadata->ReviewedAt = '2026-02-20 10:00:00';
        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $metadata->write();

        $page->publishSingle();

        $liveMetadata = Versioned::withVersionedMode(function () use ($metadata): ?GeneratedSeo {
            Versioned::set_stage(Versioned::LIVE);
            return GeneratedSeo::get()->byID($metadata->ID);
        });

        $this->assertNotNull($liveMetadata);
        $this->assertSame('AI description', $liveMetadata->MetaDescription);
    }

    /**
     * Ensure unreviewed metadata is not published with the page.
     */
    public function testOnAfterPublishSkipsUnreviewedMetadata(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiSeo();
        $metadata->MetaDescription = 'Unreviewed description';
        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $metadata->write();

        $page->publishSingle();

        $liveMetadata = Versioned::withVersionedMode(function () use ($metadata): ?GeneratedSeo {
            Versioned::set_stage(Versioned::LIVE);
            return GeneratedSeo::get()->byID($metadata->ID);
        });

        $this->assertNull($liveMetadata);
    }

    /**
     * Ensure metadata with ReviewedAt before GeneratedAt is not published.
     */
    public function testOnAfterPublishSkipsOutdatedReview(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiSeo();
        $metadata->MetaDescription = 'Stale review description';
        $metadata->ReviewedAt = '2026-02-20 09:00:00';
        $metadata->GeneratedAt = '2026-02-20 10:00:00';
        $metadata->write();

        $page->publishSingle();

        $liveMetadata = Versioned::withVersionedMode(function () use ($metadata): ?GeneratedSeo {
            Versioned::set_stage(Versioned::LIVE);
            return GeneratedSeo::get()->byID($metadata->ID);
        });

        $this->assertNull($liveMetadata);
    }

    /**
     * Ensure unpublishing a page removes live metadata.
     */
    public function testOnBeforeUnpublishRemovesLiveMetadata(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiSeo();
        $metadata->ReviewedAt = '2026-02-20 10:00:00';
        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $metadata->write();

        $page->publishSingle();
        $page->doUnpublish();

        $liveMetadata = Versioned::withVersionedMode(function () use ($metadata): ?GeneratedSeo {
            Versioned::set_stage(Versioned::LIVE);
            return GeneratedSeo::get()->byID($metadata->ID);
        });

        $this->assertNull($liveMetadata);
    }

    /**
     * Ensure archiving a page removes live metadata.
     */
    public function testOnBeforeArchiveRemovesLiveMetadata(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiSeo();
        $metadata->ReviewedAt = '2026-02-20 10:00:00';
        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $metadata->write();

        $page->publishSingle();
        $metadata->publishSingle();

        $page->doArchive();

        $liveMetadata = Versioned::withVersionedMode(function () use ($metadata): ?GeneratedSeo {
            Versioned::set_stage(Versioned::LIVE);
            return GeneratedSeo::get()->byID($metadata->ID);
        });

        $this->assertNull($liveMetadata);
    }
}
