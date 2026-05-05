<?php

namespace SilverstripeLtd\AiMetadata\Services;

use SilverStripe\Core\Extensible;

/**
 * Builds system and user prompts for AI providers.
 */
class PromptService
{
    use Extensible;

    /**
     * Build system and user prompts for the given page context.
     *
     * @return array{0: string, 1: string}
     */
    public function buildPrompts(string $content, string $pageTitle, string $pageUrl): array
    {
        $systemPrompt = $this->getSystemPrompt();
        $userPrompt = $this->getUserPrompt($content, $pageTitle, $pageUrl);
        $this->extend('updatePrompts', $systemPrompt, $userPrompt);
        return [$systemPrompt, $userPrompt];
    }

    /**
     * Return the system prompt instructing the model.
     */
    public function getSystemPrompt(): string
    {
        return trim((string) file_get_contents(dirname(__DIR__, 2) . '/prompts/system.md'));
    }

    /**
     * Build the user prompt for the supplied page data.
     */
    public function getUserPrompt(string $content, string $pageTitle, string $pageUrl): string
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/prompts/user.md');
        return str_replace(
            ['{pageTitle}', '{pageUrl}', '{content}'],
            [$pageTitle, $pageUrl, trim($content)],
            $template
        );
    }
}
