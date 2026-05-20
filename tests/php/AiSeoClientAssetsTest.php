<?php

namespace SilverstripeLtd\AiSeo\Tests;

use SilverStripe\Dev\SapphireTest;

/**
 * Ensures client assets avoid the local jQuery shim.
 */
class AiSeoClientAssetsTest extends SapphireTest
{
    /**
     * Resolve the client/src base path whether running standalone or from vendor.
     */
    private function clientSrcPath(): string
    {
        $vendorPath = BASE_PATH . '/vendor/silverstripeltd/ai-seo/client/src';
        return is_dir($vendorPath) ? $vendorPath : BASE_PATH . '/client/src';
    }

    /**
     * Verify jQuery shim is not used in modal and entwine sources.
     */
    public function testClientAssetsAvoidLocalJqueryModule(): void
    {
        $base = $this->clientSrcPath();
        $modal = file_get_contents($base . '/components/AiSeoModal.js');
        $entwine = file_get_contents($base . '/entwine/AiSeoEntwine.js');
        $jqueryHelperExists = file_exists($base . '/lib/jquery.js');

        $this->assertNotFalse($modal);
        $this->assertNotFalse($entwine);
        $this->assertFalse($jqueryHelperExists);

        $this->assertStringNotContainsString("from 'lib/jquery'", $modal);
        $this->assertStringNotContainsString("from 'lib/jquery'", $entwine);
        $this->assertStringNotContainsString("from 'jquery'", $modal);
        $this->assertStringNotContainsString("from 'jquery'", $entwine);
        $this->assertStringNotContainsString("from 'i18n'", $modal);
        $this->assertStringNotContainsString("from 'i18n'", $entwine);
        $this->assertStringContainsString('window.jQuery', $entwine);
    }

    /**
     * Ensure review confirmation uses a direct checkbox field.
     */
    public function testModalReviewCheckbox(): void
    {
        $path = $this->clientSrcPath() . '/components/AiSeoModal.js';
        $modal = file_get_contents($path);
        $this->assertNotFalse($modal);
        $this->assertStringContainsString('ReviewConfirmed', $modal);
        $this->assertStringNotContainsString('ReviewConfirmedToggle', $modal);
        $this->assertStringNotContainsString('syncReviewInputFromToggle', $modal);
        $this->assertStringNotContainsString('action_doReviewConfirmed', $modal);
    }

    /**
     * Ensure save actions keep the modal open so editors close it manually.
     */
    public function testModalSaveDoesNotAutoClose(): void
    {
        $path = $this->clientSrcPath() . '/components/AiSeoModal.js';
        $modal = file_get_contents($path);
        $this->assertNotFalse($modal);
        $this->assertStringContainsString('isSave', $modal);
        $this->assertStringNotContainsString('handleClosed()', $modal);
    }

    /**
     * Ensure submit state responds to editable field changes.
     */
    public function testModalTracksEditableFieldChanges(): void
    {
        $path = $this->clientSrcPath() . '/components/AiSeoModal.js';
        $modal = file_get_contents($path);
        $this->assertNotFalse($modal);
        $this->assertStringContainsString('editableFieldNames', $modal);
        $this->assertStringContainsString('hasChanges', $modal);
    }

    /**
     * Ensure the toolbar button mounts beside Shared Draft Content.
     */
    public function testToolbarButtonUsesSharedDraftPlacement(): void
    {
        $path = $this->clientSrcPath() . '/entwine/AiSeoEntwine.js';
        $entwine = file_get_contents($path);
        $this->assertNotFalse($entwine);
        $this->assertStringContainsString('.preview-mode-selector', $entwine);
        $this->assertStringContainsString('share-draft-content__placeholder', $entwine);
        $this->assertStringContainsString("loadComponent('AiSeoActionButton'", $entwine);
        $this->assertStringContainsString("$('.ai-seo__action').entwine", $entwine);
    }
}
