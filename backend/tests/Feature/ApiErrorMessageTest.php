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

    public function test_an_unknown_address_gets_a_plain_message(): void
    {
        $this->getJson('/api/v1/no-such-endpoint')->assertNotFound()->assertExactJson(['message' => 'This page could not be found.']);
    }

    public function test_a_non_numeric_id_is_not_found_instead_of_a_server_error(): void
    {
        $this->getJson('/api/v1/destinations/abc')->assertNotFound()->assertExactJson(['message' => 'This page could not be found.']);
        $this->getJson('/api/v1/events/1abc')->assertNotFound();
    }

    public function test_a_bad_page_size_is_kept_in_range_instead_of_breaking_the_query(): void
    {
        foreach (['events', 'announcements', 'destinations', 'accommodations', 'food-beverages', 'retail-shops'] as $list) {
            $this->getJson("/api/v1/{$list}?per_page=-1")->assertOk()->assertJsonPath('per_page', 1);
            $this->getJson("/api/v1/{$list}?per_page=abc")->assertOk();
            $this->getJson("/api/v1/{$list}?per_page=100000")->assertOk()->assertJsonPath('per_page', 50);
        }
    }

    public function test_validation_messages_use_the_names_on_the_forms(): void
    {
        $errors = validator(
            ['municipality_id' => null, 'activities' => [['name' => '']], 'arrival_time' => '25:99'],
            ['municipality_id' => 'required', 'activities.*.name' => 'required', 'arrival_time' => 'date_format:H:i'],
        )->errors();

        $this->assertSame('The municipality field is required.', $errors->first('municipality_id'));
        $this->assertSame('The activity name field is required.', $errors->first('activities.0.name'));
        $this->assertSame('Enter the arrival time like 14:30.', $errors->first('arrival_time'));
    }
}
