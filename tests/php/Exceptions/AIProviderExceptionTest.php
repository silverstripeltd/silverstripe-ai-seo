<?php

namespace SilverstripeLtd\AiMetadata\Tests\Exceptions;

use SilverstripeLtd\AiMetadata\Exceptions\AIProviderException;
use SilverStripe\Dev\SapphireTest;

/**
 * Tests AIProviderException flag handling.
 */
class AIProviderExceptionTest extends SapphireTest
{
    /**
     * Ensure transient/blocking flags are retained.
     */
    public function testFlagsPersist(): void
    {
        $exception = new AIProviderException('Boom', true, true);
        $this->assertTrue($exception->isTransient());
        $this->assertTrue($exception->isBlocking());
    }
}
