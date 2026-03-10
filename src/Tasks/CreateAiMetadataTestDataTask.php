<?php

namespace SilverstripeLtd\AiMetadata\Tasks;

use SilverstripeLtd\AiMetadata\Models\GeneratedMetadata;
use SilverstripeLtd\AiMetadata\Models\AiBlocksPage;
use SilverstripeLtd\AiMetadata\Models\AiPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Dev\FixtureFactory;
use SilverStripe\Dev\YamlFixture;
use SilverStripe\ORM\DataObject;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Yaml\Parser;

/**
 * Builds AI module sample pages and elemental blocks for manual testing.
 */
class CreateAiMetadataTestDataTask extends BuildTask
{
    private const FIXTURE_RELATIVE_PATH = 'src/Fixtures/AiMetadataTestData.yml';

    /**
     * @var array<int, class-string>
     */
    private const PAGE_CLASSES = [AiPage::class, AiBlocksPage::class];

    protected string $title = '';

    protected static string $description = 'Create AI metadata test pages and blocks.';

    /**
     * Return the task title for display.
     */
    public function getTitle(): string
    {
        return 'Create AI metadata test data';
    }

    /**
     * Execute the test data build task.
     */
    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        if (Director::isLive()) {
            $output->writeln('This task is only intended for non-production environments.');
            return Command::FAILURE;
        }

        $fixturePath = $this->resolveFixturePath();
        if ($fixturePath === null) {
            $output->writeln('Fixture file was not found.');
            return Command::FAILURE;
        }

        $fixture = new YamlFixture($fixturePath);
        $fixtureData = $this->parseFixtureData($fixture);

        $deletedCount = $this->deleteExistingRecords();
        if ($deletedCount > 0) {
            $output->writeln(sprintf('Removed %d existing pages.', $deletedCount));
        }

        $factory = new FixtureFactory();
        $fixture->writeInto($factory);
        $this->publishFixturePages($fixtureData);

        $output->writeln('Test data created.');
        return Command::SUCCESS;
    }

    /**
     * Resolve the absolute fixture path.
     */
    private function resolveFixturePath(): ?string
    {
        $module = ModuleLoader::getModule('silverstripeltd/ai-metadata');
        if (!$module) {
            return null;
        }

        $path = $module->getPath() . '/' . CreateAiMetadataTestDataTask::FIXTURE_RELATIVE_PATH;
        return file_exists($path) ? $path : null;
    }

    /**
     * Parse fixture YAML into an array.
     *
     * @return array<string, mixed>
     */
    private function parseFixtureData(YamlFixture $fixture): array
    {
        $parser = new Parser();
        $contents = $fixture->getFixtureString();
        if ($contents === null) {
            $path = $fixture->getFixtureFile();
            $contents = $path ? (string)file_get_contents($path) : '';
        }

        $parsed = $parser->parse($contents ?? '');
        return is_array($parsed) ? $parsed : [];
    }

    /**
     * Remove all existing AI module demo pages.
     */
    private function deleteExistingRecords(): int
    {
        $deletedCount = 0;

        foreach (CreateAiMetadataTestDataTask::PAGE_CLASSES as $class) {
            if (!class_exists($class)) {
                continue;
            }

            $pages = DataObject::get($class)->filter('ClassName', $class);
            foreach ($pages->toArray() as $page) {
                if (!$page instanceof SiteTree) {
                    continue;
                }

                $this->deletePageWithMetadata($page);
                $deletedCount++;
            }
        }

        return $deletedCount;
    }

    /**
     * Extract page titles from the fixture definitions.
     *
     * @param array<string, mixed> $fixtureData
     * @param array<int, string>|null $classNames
     * @return array<string, array<int, string>>
     */
    private function extractTitlesByClass(array $fixtureData, ?array $classNames = null): array
    {
        $titlesByClass = [];
        $classes = $classNames ?? array_keys($fixtureData);
        foreach ($classes as $class) {
            $titles = [];
            $records = $fixtureData[$class] ?? [];
            if (!is_array($records)) {
                continue;
            }
            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }
                $title = $record['Title'] ?? null;
                if (is_string($title) && $title !== '') {
                    $titles[] = $title;
                }
            }
            if ($titles) {
                $titlesByClass[$class] = array_values(array_unique($titles));
            }
        }

        return $titlesByClass;
    }

    /**
     * Publish all fixture pages to live.
     *
     * @param array<string, mixed> $fixtureData
     */
    private function publishFixturePages(array $fixtureData): void
    {
        $titlesByClass = $this->extractTitlesByClass($fixtureData, CreateAiMetadataTestDataTask::PAGE_CLASSES);
        foreach ($titlesByClass as $class => $titles) {
            if (!class_exists($class)) {
                continue;
            }
            foreach ($titles as $title) {
                $pages = DataObject::get($class)
                    ->filter('ClassName', $class)
                    ->filter('Title', $title);
                foreach ($pages as $page) {
                    if ($page instanceof SiteTree) {
                        $this->publishPage($page);
                    }
                }
            }
        }
    }

    /**
     * Publish a page if it is versioned.
     */
    private function publishPage(SiteTree $page): void
    {
        if (!DataObject::has_extension($page->ClassName, Versioned::class)) {
            return;
        }

        Versioned::withVersionedMode(function () use ($page): void {
            $page->publishSingle();
            $this->publishElementalBlocks($page);
        });
    }

    /**
     * Publish any elemental blocks linked to the page.
     */
    private function publishElementalBlocks(SiteTree $page): void
    {
        if (!$page->hasMethod('ElementalArea')) {
            return;
        }

        $area = $page->ElementalArea();
        if (!$area || !$area->exists()) {
            return;
        }

        if (DataObject::has_extension($area->ClassName, Versioned::class)) {
            $area->publishSingle();
        }

        $elements = $area->Elements();
        foreach ($elements as $element) {
            if (!DataObject::has_extension($element->ClassName, Versioned::class)) {
                continue;
            }
            $element->publishSingle();
        }
    }

    /**
     * Remove a page along with any associated AI metadata.
     */
    private function deletePageWithMetadata(SiteTree $page): void
    {
        $this->deleteElementalData($page);
        GeneratedMetadata::get()->filter([
            'ParentID' => $page->ID,
            'ParentClass' => $page->ClassName,
        ])->removeAll();

        if (DataObject::has_extension($page->ClassName, Versioned::class)) {
            Versioned::withVersionedMode(function () use ($page): void {
                foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
                    Versioned::set_stage($stage);
                    $record = DataObject::get_by_id($page->ClassName, $page->ID);
                    if ($record) {
                        $record->delete();
                    }
                }
            });
            return;
        }

        $page->delete();
    }

    /**
     * Remove elemental data linked to a page.
     */
    private function deleteElementalData(SiteTree $page): void
    {
        if (!$page->hasMethod('ElementalArea')) {
            return;
        }

        $area = $page->ElementalArea();
        if (!$area || !$area->exists()) {
            return;
        }

        $elements = $area->Elements();
        foreach ($elements as $element) {
            $this->deleteRecord($element);
        }

        $this->deleteRecord($area);
    }

    /**
     * Remove a record, deleting any versioned stages if needed.
     */
    private function deleteRecord(DataObject $record): void
    {
        if (DataObject::has_extension($record->ClassName, Versioned::class)) {
            Versioned::withVersionedMode(function () use ($record): void {
                foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
                    Versioned::set_stage($stage);
                    $existing = DataObject::get_by_id($record->ClassName, $record->ID);
                    if ($existing) {
                        $existing->delete();
                    }
                }
            });
            return;
        }

        $record->delete();
    }
}
