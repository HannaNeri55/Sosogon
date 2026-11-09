<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Never reach a paid AI from a test, whatever the local .env says: Gemini (whose
        // endpoint tests fake) generates, and Claude has no key, so it fails before any request.
        config([
            'services.itinerary.ai_provider' => 'gemini',
            'services.anthropic.api_key' => null,
        ]);
    }
}
