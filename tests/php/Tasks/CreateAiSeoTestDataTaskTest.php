<?php

namespace SilverstripeLtd\AiSeo\Tests\Tasks;

use DNADesign\Elemental\Models\ElementContent;
use DNADesign\Elemental\Models\ElementalArea;
use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverstripeLtd\AiSeo\Models\AiBlocksPage;
use SilverstripeLtd\AiSeo\Models\AiPage;
use SilverstripeLtd\AiSeo\Tasks\CreateAiSeoTestDataTask;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Kernel;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\Versioned\Versioned;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * Ensures the test data build task creates the expected records.
 */
class CreateAiSeoTestDataTaskTest extends SapphireTest
{
    private const SCI_FI_TITLE = 'AI SEO Test - Sci-Fi Spaceport Briefing';
    private const FANTASY_TITLE = 'AI SEO Test - Fantasy Guild Charter';
    private const COUNCIL_BLOCKS_TITLE = 'AI SEO Blocks - Local Council Services';
    private const FINANCE_BLOCKS_TITLE = 'AI SEO Blocks - Financial Services Updates';

    protected static $extra_dataobjects = [
        GeneratedSeo::class,
        ElementalArea::class,
        ElementContent::class,
        AiPage::class,
        AiBlocksPage::class,
    ];

    /**
     * Ensure the task creates pages and removes all existing AiPage/AiBlocksPage records.
     */
    public function testTaskCreatesFixturePagesAndRemovesAllDemoPages(): void
    {
        $legacyPage = AiPage::create(['Title' => 'Legacy AI Demo Page']);
        $legacyPage->write();
        $metadata = $legacyPage->getOrCreateAiSeo();
        $metadata->MetaDescription = 'Legacy description';
        $metadata->write();

        $legacyArea = ElementalArea::create();
        $legacyArea->write();
        $legacyBlocksPage = AiBlocksPage::create([
            'Title' => 'Legacy AI Blocks Demo Page',
            'ElementalAreaID' => $legacyArea->ID,
        ]);
        $legacyBlocksPage->write();
        $legacyBlock = ElementContent::create([
            'Title' => 'Legacy block',
            'HTML' => '<p>Legacy block</p>',
            'ParentID' => $legacyArea->ID,
        ]);
        $legacyBlock->write();

        $task = new CreateAiSeoTestDataTask();
        $task->run(new ArrayInput([]), new PolyOutput(PolyOutput::FORMAT_ANSI));

        $this->assertNull(GeneratedSeo::get()->filter('ParentID', $legacyPage->ID)->first());
        $this->assertNull(AiPage::get()->byID($legacyPage->ID));
        $this->assertNull(AiBlocksPage::get()->byID($legacyBlocksPage->ID));
        $this->assertNull(ElementalArea::get()->byID($legacyArea->ID));
        $this->assertNull(ElementContent::get()->byID($legacyBlock->ID));

        $this->assertSame(2, AiPage::get()->filter('ClassName', AiPage::class)->count());
        $this->assertSame(2, AiBlocksPage::get()->count());

        $sciFi = AiPage::get()->filter('Title', CreateAiSeoTestDataTaskTest::SCI_FI_TITLE)->first();
        $this->assertNotNull($sciFi);
        $this->assertSame(
            'A sci-fi briefing page describing spaceport operations, docking procedures, and traffic management.',
            $sciFi->MetaDescription
        );
        $sciFiLive = Versioned::get_by_stage(AiPage::class, Versioned::LIVE)
            ->filter('Title', CreateAiSeoTestDataTaskTest::SCI_FI_TITLE)
            ->first();
        $this->assertNotNull($sciFiLive);

        $fantasy = AiPage::get()->filter('Title', CreateAiSeoTestDataTaskTest::FANTASY_TITLE)->first();
        $this->assertNotNull($fantasy);
        $fantasyLive = Versioned::get_by_stage(AiPage::class, Versioned::LIVE)
            ->filter('Title', CreateAiSeoTestDataTaskTest::FANTASY_TITLE)
            ->first();
        $this->assertNotNull($fantasyLive);

        $council = AiBlocksPage::get()
            ->filter('Title', CreateAiSeoTestDataTaskTest::COUNCIL_BLOCKS_TITLE)->first();
        $this->assertNotNull($council);
        $this->assertSame(
            'Local council service updates including waste collection, roadworks, and community notices.',
            $council->MetaDescription
        );
        $this->assertSame(2, $council->ElementalArea()->Elements()->count());
        $this->assertAreaPublished($council->ElementalArea());
        $this->assertElementsPublished($council->ElementalArea()->Elements());
        $councilLive = Versioned::get_by_stage(AiBlocksPage::class, Versioned::LIVE)
            ->filter('Title', CreateAiSeoTestDataTaskTest::COUNCIL_BLOCKS_TITLE)
            ->first();
        $this->assertNotNull($councilLive);

        $finance = AiBlocksPage::get()
            ->filter('Title', CreateAiSeoTestDataTaskTest::FINANCE_BLOCKS_TITLE)->first();
        $this->assertNotNull($finance);
        $this->assertSame(
            'Financial services content including performance updates, risk management, and customer protections.',
            $finance->MetaDescription
        );
        $this->assertSame(2, $finance->ElementalArea()->Elements()->count());
        $this->assertAreaPublished($finance->ElementalArea());
        $this->assertElementsPublished($finance->ElementalArea()->Elements());
        $financeLive = Versioned::get_by_stage(AiBlocksPage::class, Versioned::LIVE)
            ->filter('Title', CreateAiSeoTestDataTaskTest::FINANCE_BLOCKS_TITLE)
            ->first();
        $this->assertNotNull($financeLive);
    }

    /**
     * Ensure the task does not run on live environments.
     */
    public function testTaskIsBlockedOnLiveEnvironment(): void
    {
        $injector = Injector::inst();
        $originalKernel = $injector->get(Kernel::class);

        $injector->registerService(new class {
            public function getEnvironment(): string
            {
                return 'live';
            }
        }, Kernel::class);

        $beforePages = AiPage::get()->count();
        $beforeBlocks = AiBlocksPage::get()->count();

        try {
            $task = new CreateAiSeoTestDataTask();
            $task->run(new ArrayInput([]), new PolyOutput(PolyOutput::FORMAT_ANSI));

            $this->assertSame($beforePages, AiPage::get()->count());
            $this->assertSame($beforeBlocks, AiBlocksPage::get()->count());
        } finally {
            $injector->registerService($originalKernel, Kernel::class);
        }
    }

    /**
     * Assert that a versioned ElementalArea exists on live.
     */
    private function assertAreaPublished(ElementalArea $area): void
    {
        $live = Versioned::get_by_stage(ElementalArea::class, Versioned::LIVE)->byID($area->ID);
        $this->assertNotNull($live);
    }

    /**
     * Assert that versioned elements exist on live.
     *
     * @param iterable<DataObject> $elements
     */
    private function assertElementsPublished(iterable $elements): void
    {
        foreach ($elements as $element) {
            if (!DataObject::has_extension($element->ClassName, Versioned::class)) {
                continue;
            }

            $live = Versioned::get_by_stage($element->ClassName, Versioned::LIVE)->byID($element->ID);
            $this->assertNotNull($live);
        }
    }
}
