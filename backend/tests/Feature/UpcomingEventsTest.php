<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Homepage "Upcoming events": only events that have not finished (by Philippine date),
 * soonest first, using the dates the admin entered.
 */
class UpcomingEventsTest extends TestCase
{
    use RefreshDatabase;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $role = Role::create(['name' => 'admin', 'description' => 'admin']);
        $this->adminId = User::create([
            'role_id' => $role->id, 'name' => 'Admin', 'email' => 'admin@example.com', 'password' => bcrypt('password'),
        ])->id;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function event(string $title, string $start, ?string $end = null, array $extra = []): Event
    {
        return Event::create(array_merge([
            'title' => $title, 'description' => 'Test event.', 'start_date' => $start, 'end_date' => $end,
            'status' => 'published', 'created_by' => $this->adminId,
        ], $extra));
    }

    private function upcomingTitles(int $perPage = 10): array
    {
        return array_column($this->getJson("/api/v1/events?upcoming=1&per_page={$perPage}")->assertOk()->json('data'), 'title');
    }

    public function test_upcoming_lists_ongoing_and_future_events_soonest_first_and_hides_past_ones(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00', 'Asia/Manila'));

        $this->event('Ended last week', '2026-10-20', '2026-10-23');
        $this->event('Single day yesterday', '2026-10-29');
        $this->event('Far future', '2027-10-12', null, ['is_featured' => true]);
        $this->event('Next month', '2026-11-15', '2026-11-16');
        $this->event('Ongoing festival', '2026-10-01', '2026-10-31');
        $this->event('Today only', '2026-10-30');
        $this->event('Draft is never shown', '2026-11-01', null, ['status' => 'draft']);

        $this->assertSame(['Ongoing festival', 'Today only', 'Next month', 'Far future'], $this->upcomingTitles());
        $this->assertSame(['Ongoing festival', 'Today only', 'Next month'], $this->upcomingTitles(3), 'The homepage takes the 3 soonest.');
    }

    public function test_an_event_drops_out_after_its_last_day_in_philippine_time(): void
    {
        $this->event('Ends Oct 31', '2026-10-01', '2026-10-31');

        // 23:30 on Oct 31 in Manila is still the last day.
        Carbon::setTestNow(Carbon::parse('2026-10-31 23:30', 'Asia/Manila'));
        $this->assertSame(['Ends Oct 31'], $this->upcomingTitles());

        // 00:30 on Nov 1 in Manila (still Oct 31 in UTC): the event is over.
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:30', 'Asia/Manila'));
        $this->assertSame([], $this->upcomingTitles());
    }

    public function test_the_plain_event_list_keeps_its_existing_order(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-30 10:00', 'Asia/Manila'));
        $this->event('Old', '2025-05-01');
        $this->event('New', '2027-05-01');
        $this->event('Featured middle', '2026-06-01', null, ['is_featured' => true]);

        $titles = fn (string $query) => array_column($this->getJson("/api/v1/events{$query}")->json('data'), 'title');
        $this->assertSame(['New', 'Featured middle', 'Old'], $titles(''));
        $this->assertSame(['Featured middle', 'New', 'Old'], $titles('?featured_first=1'));
    }
}
