<?php

namespace Tests\Feature;

use App\Models\FeedbackSubmission;
use App\Models\Role;
use Database\Seeders\DemoFeedbackSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/** DemoFeedbackSeeder: twenty demo submissions, once; on production only with an explicit yes. */
class DemoFeedbackSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'admin', 'description' => 'Admin']);
    }

    protected function tearDown(): void
    {
        putenv('DEMO_PREVIEW');
        putenv('SEED_DEMO_FEEDBACK_ON_LIVE');
        parent::tearDown();
    }

    public function test_it_adds_twenty_submissions_once(): void
    {
        $this->seed(DemoFeedbackSeeder::class);
        $this->assertSame(20, FeedbackSubmission::count());

        $this->seed(DemoFeedbackSeeder::class);
        $this->assertSame(20, FeedbackSubmission::count(), 'A rerun adds nothing.');
    }

    public function test_a_preview_writes_nothing(): void
    {
        putenv('DEMO_PREVIEW=1');
        $this->seed(DemoFeedbackSeeder::class);

        $this->assertSame(0, FeedbackSubmission::count());
    }

    public function test_production_needs_an_explicit_yes(): void
    {
        $this->app['env'] = 'production';
        try {
            app(DemoFeedbackSeeder::class)->run();
            $this->fail('Production without the yes must be refused.');
        } catch (RuntimeException) {
            $this->assertSame(0, FeedbackSubmission::count());
        }

        putenv('SEED_DEMO_FEEDBACK_ON_LIVE=yes');
        app(DemoFeedbackSeeder::class)->run();
        $this->assertSame(20, FeedbackSubmission::count());
    }
}
