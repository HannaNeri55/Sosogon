<?php

namespace Tests\Feature;

use App\Models\Itinerary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\ExperienceTagFixtures;
use Tests\TestCase;

/**
 * Preferred destinations are checked again when the trip is generated: one the planner can't
 * include (unpublished, in another town, or not matching the interests) is left out with a
 * non-blocking notice, and the trip is still planned from the other eligible places.
 */
class ItineraryPreferredNoticeTest extends TestCase
{
    use ExperienceTagFixtures;
    use RefreshDatabase;

    private const MESSAGE = 'Some of your preferred destinations could not be included because they are unavailable, outside your selected municipalities, '
        .'or do not match your selected interests. Your itinerary will continue using other eligible destinations.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExperienceFixtures();
    }

    private function visited(int $itineraryId): array
    {
        return Itinerary::with('items')->find($itineraryId)->items->where('item_type', 'destination')->pluck('destination_id')->map(fn ($id) => (int) $id)->all();
    }

    public function test_eligible_preferred_destinations_are_kept_without_a_notice(): void
    {
        $response = $this->generateTrip(['beaches'], ['alpha'], ['preferred_destinations' => [$this->places['plainBeach']]])->assertCreated();

        $this->assertNull($response->json('preferred_notice'));
        $this->assertMatchesRegularExpression('/^ID:'.$this->places['plainBeach'].' \|[^\n]*The tourist asked for this place$/m', end($this->ai->prompts));
    }

    public function test_ineligible_preferred_destinations_are_reported_and_the_trip_continues(): void
    {
        $this->matchByTags(true);
        $asked = [$this->places['plainBeach'], $this->places['hiddenBeach'], $this->places['ruins'], $this->places['churchBeach']];

        $response = $this->generateTrip(['beaches'], ['alpha'], ['preferred_destinations' => $asked])->assertCreated();

        $this->assertSame(self::MESSAGE, $response->json('preferred_notice.message'));
        $this->assertSame([
            ['id' => null, 'name' => null, 'reason' => 'not_published'],
            ['id' => $this->places['ruins'], 'name' => 'Bravo Ruins', 'reason' => 'outside_towns'],
            ['id' => $this->places['churchBeach'], 'name' => 'Reviewed Church Beach', 'reason' => 'no_interest_match'],
        ], $response->json('preferred_notice.destinations'));
        $this->assertStringNotContainsString('Hidden Reviewed Beach', $response->getContent(), 'An unpublished place is never named.');

        $visited = $this->visited($response->json('itinerary.id'));
        $this->assertNotEmpty($visited, 'The trip is planned from the other eligible places.');
        $this->assertSame([], array_intersect($visited, [$this->places['hiddenBeach'], $this->places['ruins'], $this->places['churchBeach']]));
        $prompt = end($this->ai->prompts);
        $this->assertStringNotContainsString('ID:'.$this->places['churchBeach'].' |', $prompt);
        $this->assertMatchesRegularExpression('/^ID:'.$this->places['plainBeach'].' \|[^\n]*The tourist asked for this place$/m', $prompt);
    }

    public function test_with_the_flag_off_a_reviewed_place_still_counts_by_its_category(): void
    {
        $response = $this->generateTrip(['beaches'], ['alpha'], ['preferred_destinations' => [$this->places['churchBeach']]])->assertCreated();

        $this->assertNull($response->json('preferred_notice'));
    }

    public function test_guests_get_the_notice_too(): void
    {
        $start = now()->addDays(3);
        $response = $this->postJson('/api/v1/itineraries/guest-generate', [
            'municipality_ids' => $this->townIds(['alpha']), 'num_days' => 2, 'interests' => ['beaches'], 'travel_pace' => 'moderate', 'budget' => 'moderate',
            'accommodation_needed' => false, 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDay()->toDateString(),
            'preferred_destinations' => [$this->places['ruins']],
        ])->assertCreated();

        $this->assertSame([['id' => $this->places['ruins'], 'name' => 'Bravo Ruins', 'reason' => 'outside_towns']], $response->json('preferred_notice.destinations'));
    }

    public function test_unknown_preferred_ids_are_still_rejected_as_before(): void
    {
        $this->generateTrip(['beaches'], ['alpha'], ['preferred_destinations' => [999999]])
            ->assertStatus(422)->assertJsonValidationErrors('preferred_destinations.0');
    }
}
