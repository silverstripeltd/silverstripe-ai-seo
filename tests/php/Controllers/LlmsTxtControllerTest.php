<?php

namespace SilverstripeLtd\AiSeo\Tests\Controllers;

use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverstripeLtd\AiSeo\Tests\RestrictedViewPage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Functional tests for the llms.txt endpoint.
 */
class LlmsTxtControllerTest extends FunctionalTest
{
    protected static $extra_dataobjects = [
        GeneratedSeo::class,
        RestrictedViewPage::class,
    ];

    /**
     * Ensure llms.txt lists only pages with summaries.
     */
    public function testLlmsTxtListsPagesWithSummaries(): void
    {
        $siteConfig = SiteConfig::current_site_config();
        $siteConfig->Title = 'Test Site';
        $siteConfig->Tagline = 'Testing';
        $siteConfig->write();

        $page = SiteTree::create(['Title' => 'Summarised page', 'Content' => 'Content']);
        $page->write();
        $page->publishSingle();

        $metadata = $page->getOrCreateAiSeo();
        $metadata->SummaryLong = 'Summary content';
        $metadata->write();
        $metadata->publishSingle();

        $emptyPage = SiteTree::create(['Title' => 'Empty summary', 'Content' => 'Content']);
        $emptyPage->write();
        $emptyPage->publishSingle();

        $emptyMetadata = $emptyPage->getOrCreateAiSeo();
        $emptyMetadata->SummaryLong = '';
        $emptyMetadata->write();
        $emptyMetadata->publishSingle();

        $response = $this->get('/llms.txt');
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('text/plain', $response->getHeader('Content-Type'));

        $body = (string)$response->getBody();
        $this->assertStringContainsString('# Test Site', $body);
        $this->assertStringContainsString('> Testing', $body);
        $this->assertStringContainsString($page->AbsoluteLink(), $body);
        $this->assertStringContainsString('Summary content', $body);
        $this->assertStringNotContainsString($emptyPage->AbsoluteLink(), $body);
    }

    /**
     * Ensure llms.txt excludes published live pages the visitor cannot view.
     */
    public function testLlmsTxtExcludesRestrictedPages(): void
    {
        $page = RestrictedViewPage::create(['Title' => 'Restricted page', 'Content' => 'Content']);
        $page->write();
        $page->publishSingle();

        $metadata = $page->getOrCreateAiSeo();
        $metadata->SummaryLong = 'Restricted summary';
        $metadata->write();
        $metadata->publishSingle();

        $response = $this->get('/llms.txt');
        $this->assertEquals(200, $response->getStatusCode());

        $body = (string)$response->getBody();
        $this->assertStringNotContainsString('Restricted summary', $body);
        $this->assertStringNotContainsString($page->AbsoluteLink(), $body);
    }
}
