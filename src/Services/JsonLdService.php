<?php

namespace SilverstripeLtd\AiMetadata\Services;

use SilverstripeLtd\AiMetadata\Models\GeneratedMetadata;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use SilverStripe\SiteConfig\SiteConfig;

/**
 * Builds JSON-LD schema payloads for pages.
 */
class JsonLdService
{
    /**
     * Generate JSON-LD for the given page and metadata.
     */
    public function generateJsonLd(SiteTree $page, GeneratedMetadata $metadata): ?string
    {
        $graph = $this->buildGraph($page, $metadata);
        if (!$graph) {
            return null;
        }

        $payload = [
            '@context' => 'https://schema.org',
            '@graph' => $graph,
        ];

        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Assemble the JSON-LD graph.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildGraph(SiteTree $page, GeneratedMetadata $metadata): array
    {
        $graph = [];
        $siteConfig = SiteConfig::current_site_config();
        $siteName = (string)$siteConfig->Title;
        $siteUrl = Director::absoluteBaseURL();
        $orgId = rtrim($siteUrl, '/') . '#organization';

        if ($siteName !== '') {
            $graph[] = [
                '@type' => 'Organization',
                '@id' => $orgId,
                'name' => $siteName,
                'url' => $siteUrl,
            ];
        }

        $summary = (string)$metadata->SummaryLong;
        $webPage = [
            '@type' => 'WebPage',
            '@id' => $page->AbsoluteLink(),
            'url' => $page->AbsoluteLink(),
            'name' => $metadata->OGTitle ?: $page->Title,
        ];
        if ($summary !== '') {
            $webPage['description'] = $summary;
        }
        if ($page->Created) {
            $webPage['datePublished'] = $page->obj('Created')->Rfc3339();
        }
        if ($page->LastEdited) {
            $webPage['dateModified'] = $page->obj('LastEdited')->Rfc3339();
        }

        $entities = $this->decodeJsonArray($metadata->KeyEntities);
        if ($entities) {
            $entityItems = $this->mapEntities($entities);
            if ($entityItems) {
                $webPage['about'] = $entityItems[0] ?? null;
                $mentions = array_slice($entityItems, 1);
                if ($mentions) {
                    $webPage['mentions'] = $mentions;
                }
            }
        }

        $graph[] = $webPage;

        $article = [
            '@type' => 'Article',
            'headline' => $metadata->OGTitle ?: $page->Title,
            'mainEntityOfPage' => ['@id' => $page->AbsoluteLink()],
        ];
        if ($summary !== '') {
            $article['description'] = $summary;
            $article['abstract'] = $summary;
        }
        if ($page->Created) {
            $article['datePublished'] = $page->obj('Created')->Rfc3339();
        }
        if ($page->LastEdited) {
            $article['dateModified'] = $page->obj('LastEdited')->Rfc3339();
        }
        if ($siteName !== '') {
            $article['author'] = ['@id' => $orgId];
            $article['publisher'] = ['@id' => $orgId];
        }

        $graph[] = $article;

        $faqs = $this->decodeJsonArray($metadata->SuggestedFAQs);
        if ($faqs) {
            $faqEntities = [];
            foreach ($faqs as $faq) {
                if (empty($faq['question']) || empty($faq['answer'])) {
                    continue;
                }
                $faqEntities[] = [
                    '@type' => 'Question',
                    'name' => $faq['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $faq['answer'],
                    ],
                ];
            }
            if ($faqEntities) {
                $graph[] = [
                    '@type' => 'FAQPage',
                    'mainEntity' => $faqEntities,
                ];
            }
        }

        $breadcrumbs = $page->getBreadcrumbItems();
        if ($breadcrumbs && $breadcrumbs->count()) {
            $items = [];
            $position = 1;
            foreach ($breadcrumbs as $crumb) {
                $items[] = [
                    '@type' => 'ListItem',
                    'position' => $position++,
                    'name' => $crumb->Title,
                    'item' => $crumb->AbsoluteLink(),
                ];
            }
            if ($items) {
                $graph[] = [
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => $items,
                ];
            }
        }

        return $graph;
    }

    /**
     * Decode a JSON array field into PHP data.
     *
     * @return array<int, mixed>
     */
    private function decodeJsonArray(?string $value): array
    {
        if (!$value) {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * Map entity arrays into schema.org objects.
     *
     * @param array<int, array<string, string>> $entities
     * @return array<int, array<string, string>>
     */
    private function mapEntities(array $entities): array
    {
        $mapped = [];
        foreach ($entities as $entity) {
            if (empty($entity['name']) || empty($entity['type'])) {
                continue;
            }
            $item = [
                '@type' => $entity['type'],
                'name' => $entity['name'],
            ];
            if (!empty($entity['sameAs'])) {
                $item['sameAs'] = $entity['sameAs'];
            }
            $mapped[] = $item;
        }

        return $mapped;
    }
}
