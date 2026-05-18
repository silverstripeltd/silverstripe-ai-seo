<?php

namespace SilverstripeLtd\AiSeo\Tests\Models;

use SilverstripeLtd\AiSeo\Models\GeneratedSeo;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Dev\SapphireTest;

/**
 * Tests core GeneratedSeo model helpers.
 */
class GeneratedSeoTest extends SapphireTest
{
    protected static $extra_dataobjects = [
        GeneratedSeo::class,
    ];

    /**
     * Ensure staleness detection works with hashes.
     */
    public function testIsStale(): void
    {
        $metadata = GeneratedSeo::create(['ContentHash' => 'abc']);
        $this->assertTrue($metadata->isStale('def'));
        $this->assertFalse($metadata->isStale(''));
        $metadata->ContentHash = '';
        $this->assertFalse($metadata->isStale('def'));
    }

    /**
     * Ensure review status checks ReviewedAt against GeneratedAt.
     */
    public function testIsReviewed(): void
    {
        $metadata = GeneratedSeo::create();
        $this->assertFalse($metadata->isReviewed());

        $metadata->ReviewedAt = '2026-02-20 10:00:00';
        $this->assertFalse($metadata->isReviewed());

        $metadata->GeneratedAt = '2026-02-20 09:00:00';
        $this->assertTrue($metadata->isReviewed());

        $metadata->GeneratedAt = '2026-02-20 11:00:00';
        $this->assertFalse($metadata->isReviewed());
    }

    /**
     * Ensure polymorphic parent relationships resolve.
     */
    public function testPolymorphicRelationship(): void
    {
        $page = SiteTree::create(['Title' => 'Test page']);
        $page->write();

        $metadata = GeneratedSeo::create([
            'ParentID' => $page->ID,
            'ParentClass' => $page->ClassName,
        ]);
        $metadata->write();

        $this->assertEquals($page->ID, $metadata->Parent()->ID);
    }
}
