<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Error responses use plain words and never name the app's internal classes. */
class ApiErrorMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_record_gets_a_plain_message(): void
    {
        $response = $this->getJson('/api/v1/destinations/999999')->assertNotFound();

        $response->assertExactJson(['message' => 'This item could not be found. It may have been removed.']);
        $this->assertStringNotContainsString('App\\Models', $response->getContent());
    }

    public function test_an_unknown_address_keeps_the_standard_not_found_response(): void
    {
        $this->getJson('/api/v1/no-such-endpoint')->assertNotFound()->assertJsonMissing(['message' => 'This item could not be found. It may have been removed.']);
    }
}
