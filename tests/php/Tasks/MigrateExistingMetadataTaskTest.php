<?php

namespace SilverstripeLtd\AiMetadata\Tests\Tasks;

use SilverstripeLtd\AiMetadata\Models\GeneratedMetadata;
use SilverstripeLtd\AiMetadata\Tasks\MigrateExistingMetadataTask;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Covers migration of existing metadata fields.
 */
class MigrateExistingMetadataTaskTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        GeneratedMetadata::class,
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

        $task = new MigrateExistingMetadataTask();
        $task->run(new ArrayInput([]), new PolyOutput(PolyOutput::FORMAT_ANSI));

        $metadata = GeneratedMetadata::get()->filter('ParentID', $page->ID)->first();
        $this->assertSame('Legacy meta description', $metadata->MetaDescription);

        $metadata->MetaDescription = 'Updated description';
        $metadata->write();

        $task->run(new ArrayInput([]), new PolyOutput(PolyOutput::FORMAT_ANSI));
        $metadata = GeneratedMetadata::get()->filter('ParentID', $page->ID)->first();
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

        $metadata = $page->getOrCreateAiMetadata();
        DB::prepared_query(
            'UPDATE "AiMetadata" SET "ClassName" = ? WHERE "ID" = ?',
            ['SilverstripeLtd\\AiMetadata\\Models\\ObsoleteGeneratedMetadata', $metadata->ID]
        );

        $task = new MigrateExistingMetadataTask();
        $task->run(new ArrayInput([]), new PolyOutput(PolyOutput::FORMAT_ANSI));

        $metadata = GeneratedMetadata::get()->filter('ParentID', $page->ID)->first();
        $this->assertSame('Legacy meta description', $metadata->MetaDescription);
        $this->assertSame(GeneratedMetadata::class, $metadata->ClassName);
    }
}
