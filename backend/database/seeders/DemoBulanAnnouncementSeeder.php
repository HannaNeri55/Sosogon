<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Municipality;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Local/demo only. Ten town-wide Bulan announcements of the kind a municipal office posts (power
 * and water interruptions, holiday office hours, town activities), to show the Announcements
 * pages filled in. Their dates and schedules are made up, so they are never seeded in
 * production: residents and tourists would take a made-up brownout or road closure as real.
 *
 * Idempotent: announcements are matched by title, so running it again creates nothing new and
 * leaves admin edits alone. Listed newest first.
 */
class DemoBulanAnnouncementSeeder extends Seeder
{
    private const ANNOUNCEMENTS = [
        [
            'title' => 'Scheduled Power Interruption: November 29, 2026',
            'content' => "Please be advised of a scheduled power interruption on Sunday, November 29, 2026, from 8:00 AM to 5:00 PM.\n\n"
                ."Affected areas: Zone 1, Zone 2-A, Zone 3, Zone 4, Obrero, Aquino and parts of San Francisco.\n\n"
                ."Reason: line maintenance and clearing of tree branches along the power lines.\n\n"
                .'Power may come back earlier once the work is done. Please unplug your appliances during the interruption. We apologize for the inconvenience.',
            'is_pinned' => true,
        ],
        [
            'title' => 'Water Service Interruption: December 2, 2026',
            'content' => "There will be no water service on Wednesday, December 2, 2026, from 9:00 AM to 4:00 PM, due to the repair of a main pipeline.\n\n"
                ."Affected areas: Zone 5, Zone 6, Zone 7-A, Zone 7-B, Zone 8 and Sta. Remedios.\n\n"
                .'Please store enough water before the interruption. Water may look cloudy for a short time when the service comes back; let it run for a few minutes before use.',
        ],
        [
            'title' => 'Christmas Tree Lighting at the Town Plaza: December 1',
            'content' => "Join us for the lighting of the Bulan Christmas tree at the Town Plaza on Tuesday, December 1, 2026, at 6:00 PM.\n\n"
                .'The evening includes performances from local schools, a short program and the switching on of the plaza lights. Everyone is welcome. Bring the whole family!',
        ],
        [
            'title' => 'Traffic Rerouting on December 1 for the Christmas Tree Lighting',
            'content' => "The streets around the Town Plaza will be closed to vehicles on December 1, 2026, from 4:00 PM to 10:00 PM for the Christmas Tree Lighting.\n\n"
                .'Vehicles will be rerouted through the nearby streets. Traffic enforcers will be on site to guide motorists. Please park away from the plaza and walk to the event.',
        ],
        [
            'title' => 'Bulan Christmas Bazaar: December 5, 2026 to January 3, 2027',
            'content' => "The Bulan Christmas Bazaar opens at the Town Plaza on December 5, 2026, and runs until January 3, 2027, every day from 4:00 PM to 11:00 PM.\n\n"
                .'Shop for local food, pasalubong, clothes and gifts from Bulan sellers. Food stalls and live music on weekends. Please keep the area clean and use the trash bins provided.',
        ],
        [
            'title' => 'Free Medical and Dental Mission: December 9, 2026',
            'content' => "A free medical and dental mission will be held at the Bulan Municipal Gymnasium on Wednesday, December 9, 2026, from 8:00 AM to 3:00 PM.\n\n"
                ."Services: general check-up, blood pressure and blood sugar checks, tooth extraction and free medicines while supplies last.\n\n"
                .'Please bring a valid ID. Priority numbers are given starting 7:00 AM. Senior citizens, PWDs and pregnant women are prioritized.',
        ],
        [
            'title' => 'Coastal Clean-Up Drive: December 12, 2026',
            'content' => "Help keep Bulan's shores clean! Join the coastal clean-up on Saturday, December 12, 2026, at 6:00 AM. The assembly point is the Bulan fish port.\n\n"
                .'Please bring gloves, a hat and your own water bottle. Trash bags will be provided. Students, organizations and visitors are all welcome to volunteer.',
        ],
        [
            'title' => 'Gale Warning: Small Sea Vessels Not Allowed to Sail',
            'content' => "Due to strong winds and rough seas along Ticao Pass, small boats and fishing vessels are not allowed to go out to sea until further notice.\n\n"
                .'Island trips and boat tours from Bulan are cancelled while the warning is in effect. Please stay away from the shore during high waves and follow the instructions of local authorities. We will post an update once sea travel is allowed again.',
        ],
        [
            'title' => 'Municipal Hall Holiday Schedule for December 2026',
            'content' => "The Municipal Hall of Bulan will be closed on the following holidays:\n\n"
                ."• December 8 (Tuesday): Feast of the Immaculate Conception\n"
                ."• December 24 (Thursday): Christmas Eve\n"
                ."• December 25 (Friday): Christmas Day\n"
                ."• December 30 (Wednesday): Rizal Day\n"
                ."• December 31 (Thursday): Last Day of the Year\n"
                ."• January 1 (Friday): New Year's Day\n\n"
                .'Regular office hours (8:00 AM to 5:00 PM, Monday to Friday) resume on Monday, January 4, 2027. Emergency services remain available.',
        ],
        [
            'title' => 'Business Permit Renewal for 2027: January 4 to 20',
            'content' => "Business owners in Bulan may renew their business permits for 2027 at the Business Permits and Licensing Office, Municipal Hall, from January 4 to January 20, 2027.\n\n"
                ."Please bring your 2026 business permit, proof of your gross sales for 2026, and your barangay clearance.\n\n"
                .'Renewals after January 20 are charged a penalty. Come early to avoid long lines.',
        ],
    ];

    public function run(): void
    {
        // Made-up brownouts and closures must never reach real residents and tourists.
        if (app()->environment('production')) {
            throw new RuntimeException('Demo announcements cannot be seeded in production.');
        }

        $bulan = Municipality::where('name', 'Bulan')->first();
        $adminRole = Role::where('name', 'admin')->value('id');
        $admins = $adminRole ? User::where('role_id', $adminRole)->orderBy('id') : null;
        // Posted by Bulan's own office when it has one here, else by the first admin.
        $admin = $admins ? ((clone $admins)->where('municipality_id', $bulan?->id)->first() ?? $admins->first()) : null;

        if (! $bulan || ! $admin) {
            $this->command?->error('DemoBulanAnnouncementSeeder needs the Bulan municipality and an admin user.');

            return;
        }

        $created = 0;
        $postedAt = Carbon::parse('2026-11-25');

        foreach (self::ANNOUNCEMENTS as $index => $announcement) {
            if (Announcement::where('title', $announcement['title'])->exists()) {
                continue;
            }

            $record = new Announcement([
                'is_pinned' => false,
                ...$announcement,
                'status' => 'published',
                'created_by' => $admin->id,
                'municipality_id' => $bulan->id,
            ]);
            // About a day apart during office hours, newest first.
            $record->created_at = $postedAt->copy()->subDays(intdiv($index * 3, 2))->setTime(8 + ($index * 3) % 9, ($index * 17) % 60);
            $record->updated_at = $record->created_at;
            $record->save();
            $created++;
        }

        $this->command?->info("Bulan announcements created: {$created}.");
    }
}
