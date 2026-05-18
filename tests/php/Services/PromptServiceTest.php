<?php

namespace SilverstripeLtd\AiSeo\Tests\Services;

use SilverstripeLtd\AiSeo\Services\PromptService;
use SilverStripe\Dev\SapphireTest;

/**
 * Tests prompt content generation.
 */
class PromptServiceTest extends SapphireTest
{
    public function testPromptTemplatesLoadFromModuleRootDirectory(): void
    {
        $service = new PromptService();

        $this->assertSame(
            trim((string) file_get_contents(dirname(__DIR__, 3) . '/prompts/system.md')),
            $service->getSystemPrompt()
        );
        $this->assertSame(
            str_replace(
                ['{pageTitle}', '{pageUrl}', '{content}'],
                ['Page Title', 'https://example.com/page', 'Content body'],
                (string) file_get_contents(dirname(__DIR__, 3) . '/prompts/user.md')
            ),
            $service->getUserPrompt('Content body', 'Page Title', 'https://example.com/page')
        );
    }

    /**
     * Ensure system prompt sets the role.
     */
    public function testSystemPromptSetsRole(): void
    {
        $service = new PromptService();
        $systemPrompt = $service->getSystemPrompt();

        $this->assertStringContainsString('SEO', $systemPrompt);
        $this->assertStringContainsString('JSON', $systemPrompt);
    }

    /**
     * Ensure user prompt includes field definitions and page context.
     */
    public function testUserPromptIncludesFieldDefinitions(): void
    {
        $service = new PromptService();
        $prompt = $service->getUserPrompt('Content body', 'Page Title', 'https://example.com/page');

        $this->assertStringContainsString('metaDescription', $prompt);
        $this->assertStringContainsString('Max 150 characters', $prompt);
        $this->assertStringContainsString('ogTitle', $prompt);
        $this->assertStringContainsString('summaryLong', $prompt);
        $this->assertStringContainsString('keyEntities', $prompt);
        $this->assertStringContainsString('suggestedFAQs', $prompt);
        $this->assertStringContainsString('EXAMPLE OUTPUT', $prompt);
    }

    /**
     * Ensure user prompt includes page context.
     */
    public function testUserPromptIncludesContext(): void
    {
        $service = new PromptService();
        $prompt = $service->getUserPrompt('Content body', 'Page Title', 'https://example.com/page');

        $this->assertStringContainsString('Page Title', $prompt);
        $this->assertStringContainsString('https://example.com/page', $prompt);
        $this->assertStringContainsString('Content body', $prompt);
    }
}
