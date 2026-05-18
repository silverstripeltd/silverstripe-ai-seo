<?php

namespace SilverstripeLtd\AiSeo\Tests\Tasks;

use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverstripeLtd\AiSeo\Tasks\MigrateExistingSeoTask;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Covers migration of existing metadata fields.
 */
class MigrateExistingSeoTaskTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        GeneratedSeo::class,
    ];

    /**
     * Ensure MetaDescription migration is idempotent.
     */
    public function testMigrationAndIdempotency(): void
    {
        $page = \SilverStripe\CMS\Model\SiteTree::create([
            'Title' => 'Legacy meta description',
            'MetaDescription' => 'Legacy meta description',
        ]);
        $page->write();

        $task = new MigrateExistingSeoTask();
        $task->run(new ArrayInput([]), new PolyOutput(PolyOutput::FORMAT_ANSI));

        $metadata = GeneratedSeo::get()->filter('ParentID', $page->ID)->first();
        $this->assertSame('Legacy meta description', $metadata->MetaDescription);

        $metadata->MetaDescription = 'Updated description';
        $metadata->write();

        $task->run(new ArrayInput([]), new PolyOutput(PolyOutput::FORMAT_ANSI));
        $metadata = GeneratedSeo::get()->filter('ParentID', $page->ID)->first();
        $this->assertSame('Updated description', $metadata->MetaDescription);
    }

    /**
     * Ensure obsolete class names are corrected during migration.
     */
    public function testMigrationHandlesObsoleteClassName(): void
    {
        $page = \SilverStripe\CMS\Model\SiteTree::create([
            'Title' => 'Legacy page',
            'MetaDescription' => 'Legacy meta description',
        ]);
        $page->write();

        $metadata = $page->getOrCreateAiSeo();
        DB::prepared_query(
            'UPDATE "GeneratedSeo" SET "ClassName" = ? WHERE "ID" = ?',
            ['SilverstripeLtd\\AiSeo\\Models\\ObsoleteGeneratedSeo', $metadata->ID]
        );

        $task = new MigrateExistingSeoTask();
        $task->run(new ArrayInput([]), new PolyOutput(PolyOutput::FORMAT_ANSI));

        $metadata = GeneratedSeo::get()->filter('ParentID', $page->ID)->first();
        $this->assertSame('Legacy meta description', $metadata->MetaDescription);
        $this->assertSame(GeneratedSeo::class, $metadata->ClassName);
    }
}
