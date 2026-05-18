<?php

namespace SilverstripeLtd\AiSeo\Tests\Services;

use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverstripeLtd\AiSeo\Services\JsonLdService;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;

/**
 * Tests JSON-LD generation outputs.
 */
class JsonLdServiceTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        GeneratedSeo::class,
    ];

    /**
     * Ensure FAQ entities are included when present.
     */
    public function testGraphIncludesFaqWhenPresent(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiSeo();
        $metadata->OGTitle = 'OG Title';
        $metadata->SummaryLong = 'Long summary';
        $metadata->KeyEntities = json_encode([
            ['type' => 'Organization', 'name' => 'Example Org'],
        ]);
        $metadata->KeyTopics = 'Topic';
        $metadata->SuggestedFAQs = json_encode([
            ['question' => 'Q1', 'answer' => 'A1'],
        ]);
        $metadata->write();

        $service = new JsonLdService();
        $json = $service->generateJsonLd($page, $metadata);
        $payload = json_decode($json, true);

        $types = array_column($payload['@graph'], '@type');
        $this->assertContains('FAQPage', $types);
        $this->assertContains('WebPage', $types);
        $this->assertContains('Article', $types);
        $this->assertContains('BreadcrumbList', $types);

        $webPage = array_values(array_filter(
            $payload['@graph'],
            static fn(array $item): bool => ($item['@type'] ?? null) === 'WebPage'
        ));
        $article = array_values(array_filter(
            $payload['@graph'],
            static fn(array $item): bool => ($item['@type'] ?? null) === 'Article'
        ));
        $this->assertSame('OG Title', $webPage[0]['name'] ?? null);
        $this->assertSame('OG Title', $article[0]['headline'] ?? null);
    }

    /**
     * Ensure JSON-LD output is pretty printed for readability.
     */
    public function testJsonLdIsPrettyPrinted(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiSeo();
        $metadata->OGTitle = 'OG Title';
        $metadata->write();

        $service = new JsonLdService();
        $json = $service->generateJsonLd($page, $metadata);

        $this->assertNotNull($json);
        $this->assertStringContainsString("\n", $json);
        $this->assertStringContainsString('    "@context"', $json);
    }

    /**
     * Ensure attacker-controlled script breakouts are hex-escaped in the payload.
     */
    public function testJsonLdEscapesScriptBreakoutSequences(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiSeo();
        $metadata->SummaryLong = 'Safe text </script><script>alert(1)</script>';
        $metadata->write();

        $service = new JsonLdService();
        $json = $service->generateJsonLd($page, $metadata);
        $payload = json_decode($json, true);
        $webPage = array_values(array_filter(
            $payload['@graph'],
            static fn(array $item): bool => ($item['@type'] ?? null) === 'WebPage'
        ));

        $this->assertNotNull($json);
        $this->assertStringContainsString('\u003C/script\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E', $json);
        $this->assertStringNotContainsString('</script>', $json);
        $this->assertSame(
            'Safe text </script><script>alert(1)</script>',
            $webPage[0]['description'] ?? null
        );
    }

    /**
     * Ensure FAQ entities are omitted when empty.
     */
    public function testGraphOmitsFaqWhenEmpty(): void
    {
        $page = SiteTree::create(['Title' => 'Test page', 'Content' => 'Content']);
        $page->write();

        $metadata = $page->getOrCreateAiSeo();
        $metadata->OGTitle = 'OG Title';
        $metadata->SummaryLong = 'Long summary';
        $metadata->write();

        $service = new JsonLdService();
        $json = $service->generateJsonLd($page, $metadata);
        $payload = json_decode($json, true);

        $types = array_column($payload['@graph'], '@type');
        $this->assertNotContains('FAQPage', $types);
    }
}
