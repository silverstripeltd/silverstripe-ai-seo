<?php

namespace SilverstripeLtd\AiMetadata\Tasks;

use SilverstripeLtd\AiMetadata\Models\GeneratedMetadata;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\ORM\DataObject;
use SilverStripe\Dev\BuildTask;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Migrates existing SiteTree metadata into GeneratedMetadata records.
 */
class MigrateExistingMetadataTask extends BuildTask
{
    protected string $title = '';

    protected static string $description = 'Copy SiteTree MetaDescription into GeneratedMetadata records.';

    /**
     * Return the task title for display.
     */
    public function getTitle(): string
    {
        return 'Migrate existing AI metadata';
    }

    /**
     * Execute the migration task.
     */
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        foreach (SiteTree::get() as $page) {
            /** @var SiteTree $page */
            $page = DataObject::get($page->ClassName)->setUseCache(true)->byID($page->ID) ?: $page;
            $metadata = $page->getOrCreateAiMetadata();
            $shouldWrite = false;
            if (!$metadata->exists() || trim((string)$metadata->MetaDescription) === '') {
                $metaDescription = trim((string)$page->MetaDescription);
                if ($metaDescription !== '') {
                    $metadata->MetaDescription = $metaDescription ?: $metadata->MetaDescription;
                    $shouldWrite = true;
                }
            }
            if ($shouldWrite) {
                $metadata->write();
            }
        }
        return Command::SUCCESS;
    }
}
