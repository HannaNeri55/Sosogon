<?php

namespace Tests\Feature;

use App\Models\Destination;
use App\Models\FoodBeverage;
use App\Services\ItineraryService;
use Tests\TestCase;

/**
 * A place a couple of hundred metres from the last stop (a kainan inside a camp, a shop next door)
 * is a short walk: never "by road", whatever the tourist travels by.
 */
class ItineraryShortWalkTest extends TestCase
{
    private function helper(ItineraryService $planner, string $method, ...$args)
    {
        return (new \ReflectionMethod(ItineraryService::class, $method))->invoke($planner, ...$args);
    }

    private function plannerFor(string $transport): ItineraryService
    {
        $planner = app(ItineraryService::class);
        (new \ReflectionProperty(ItineraryService::class, 'transport'))->setValue($planner, $transport);

        return $planner;
    }

    public function test_a_kainan_inside_the_camp_is_a_short_walk(): void
    {
        $camp = new Destination(['name' => 'Camp Hiraya', 'latitude' => 12.60000, 'longitude' => 123.90000]);
        $kainan = new FoodBeverage(['name' => "Aling Nena's Kainan", 'latitude' => 12.60120, 'longitude' => 123.90050]); // ~140 m
        $town = new FoodBeverage(['name' => 'Town Grill', 'latitude' => 12.62000, 'longitude' => 123.90000]); // ~2.2 km

        foreach (['private_car', 'public_transport', 'motorcycle'] as $transport) {
            $planner = $this->plannerFor($transport);
            $this->assertSame(5, $this->helper($planner, 'travelMinutes', $camp, $kainan), $transport);
            $note = $this->helper($planner, 'withTravelNote', 'Lunch break during your visit to Camp Hiraya.', $camp, $kainan, 690);
            $this->assertSame('Lunch break during your visit to Camp Hiraya. Walk over from Camp Hiraya at 11:25 AM (a short walk, about 140 m).', $note, $transport);
        }

        $planner = $this->plannerFor('private_car');
        $this->assertStringContainsString('min by road', $this->helper($planner, 'withTravelNote', 'Lunch near Camp Hiraya.', $camp, $town, 690), 'Farther is still a drive.');

        // After an edit the walk sentence is recognised and rewritten from the new times.
        $note = 'Lunch near Camp Hiraya. Walk over from Camp Hiraya at 11:25 AM (a short walk, about 140 m).';
        $this->assertSame('Lunch near Camp Hiraya. Walk over from Camp Hiraya at 11:55 AM (a short walk, about 140 m).',
            $this->helper($planner, 'refreshedTravelNotes', $note, $camp, $kainan, '12:00 PM – 1:00 PM'));
    }
}
