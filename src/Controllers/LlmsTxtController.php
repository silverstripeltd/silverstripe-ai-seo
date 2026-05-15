<?php

namespace SilverstripeLtd\AiMetadata\Controllers;

use SilverstripeLtd\AiMetadata\Models\GeneratedMetadata;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\Versioned\Versioned;

/**
 * Serves an llms.txt summary for AI crawlers.
 */
class LlmsTxtController extends Controller
{
    private static $allowed_actions = [
        'index',
    ];

    /**
     * Return the llms.txt response.
     */
    public function index(HTTPRequest $request): HTTPResponse
    {
        $content = $this->generateContent();
        $response = HTTPResponse::create($content, 200);
        $response->addHeader('Content-Type', 'text/plain');
        return $response;
    }

    /**
     * Build the llms.txt content body.
     */
    public function generateContent(): string
    {
        $siteConfig = SiteConfig::current_site_config();
        $lines = [];
        $siteTitle = (string)$siteConfig->Title;
        if ($siteTitle !== '') {
            $lines[] = '# ' . $siteTitle;
        } else {
            $lines[] = '# Site';
        }

        $tagline = trim((string)$siteConfig->Tagline);
        if ($tagline !== '') {
            $lines[] = '';
            $lines[] = '> ' . $tagline;
        }

        $lines[] = '';
        $lines[] = '## Pages';

        $pages = Versioned::get_by_stage(SiteTree::class, Versioned::LIVE);
        foreach ($pages as $page) {
            /** @var SiteTree $page */
            if (!$page->canView()) {
                continue;
            }
            $metadata = Versioned::withVersionedMode(function () use ($page): ?GeneratedMetadata {
                Versioned::set_stage(Versioned::LIVE);
                return $page->getAiMetadata();
            });
            if (!$metadata || !$metadata->exists()) {
                continue;
            }

            $summary = trim((string)$metadata->SummaryLong);
            if ($summary === '') {
                continue;
            }

            $summary = preg_replace('/\s+/', ' ', $summary);
            $lines[] = sprintf('- [%s](%s): %s', $page->Title, $page->AbsoluteLink(), $summary);
        }
        return implode("\n", $lines) . "\n";
    }
}
