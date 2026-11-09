<?php

namespace Database\Seeders;

use App\Models\Announcement;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Public announcements written from the system's own Events calendar, published safety notices
 * and how SOSOGON itself works. Nothing here is an official order: each one says where to confirm.
 *
 * Idempotent: announcements are matched by title, so running it again creates nothing new and
 * leaves admin edits alone. Listed newest first, so the first entries are the ones Home shows.
 */
class AnnouncementSeeder extends Seeder
{
    private const ANNOUNCEMENTS = [
        [
            'title' => 'Bulan Barangay Fiestas This November and December',
            'content' => "Visiting Bulan before the year ends? These barangay fiestas are on the Municipality of Bulan's official barangay fiesta calendar:\n\n"
                ."• Sigad: November 15, 2026\n"
                ."• Dolos & Sitio Tarhan: November 26–27, 2026\n"
                ."• Zone 5: December 7–8, 2026\n"
                ."• Libertad: December 27, 2026\n\n"
                .'Barangay fiestas are community celebrations open to visitors. Please check with the barangay for the program and schedule of activities. See the Events page for details.',
        ],
        [
            'title' => 'Safety Reminder: Bulusan Volcano Permanent Danger Zone',
            'content' => "Entry into the 4-kilometer Permanent Danger Zone around Bulusan Volcano is prohibited, whatever the alert level.\n\n"
                ."If your trip includes Bulusan or the nearby towns, check the current alert level and the latest bulletin from PHIVOLCS before you travel, and follow the instructions of local authorities. Plans can change on short notice when the volcano's status changes.",
        ],
        [
            'title' => 'Plan Your Trip With SOSOGON',
            'content' => "Not sure where to start? Open Plan Your Trip to get a suggested itinerary for your visit to Sorsogon.\n\n"
                .'Please treat it as a starting point. Travel times, costs and opening hours can change, so confirm the details with each place before you go.',
        ],
        [
            'title' => 'Butanding Arribada Festival in Donsol: April 24–30, 2027',
            'content' => "Our Events calendar lists the Butanding Arribada Festival in Donsol for April 24–30, 2027. The festival marks the peak of Donsol's whale shark (butanding) season.\n\n"
                .'Please confirm the dates and the program with the Municipality of Donsol before you make travel plans.',
        ],
        [
            'title' => 'Seeing Whale Sharks in Donsol: Please Keep Your Distance',
            'content' => "Planning to see the butanding in Donsol? Please respect the rules for interacting with them:\n\n"
                ."• Stay at least 3 meters from the head and 4 meters from the tail.\n"
                ."• Do not feed the whale sharks.\n"
                ."• Follow your boat crew and the local tourism staff.\n\n"
                ."Travel guides generally describe the season as running from around November to May or June, but sightings are never guaranteed. Please confirm the current season, rules and fees with Donsol's tourism office before you go.",
        ],
        [
            'title' => 'SOSOGON Does Not Take Bookings or Payments',
            'content' => "SOSOGON is a tourism information guide. You can't book or pay for a stay, meal or tour on this website.\n\n"
                .'Prices shown are indicative. Please contact the property or business directly to confirm rates and availability, and to make a reservation.',
        ],
    ];

    public function run(): void
    {
        $adminRole = Role::where('name', 'admin')->value('id');
        $admin = $adminRole ? User::where('role_id', $adminRole)->orderBy('id')->first() : null;

        if (! $admin) {
            $this->command?->error('AnnouncementSeeder needs an admin user.');

            return;
        }

        $created = 0;
        $postedAt = Carbon::parse('2026-11-06 12:00:00');

        foreach (self::ANNOUNCEMENTS as $index => $announcement) {
            if (Announcement::where('title', $announcement['title'])->exists()) {
                continue;
            }

            $record = new Announcement([
                ...$announcement,
                'is_pinned' => false,
                'status' => 'published',
                'created_by' => $admin->id,
            ]);
            $record->created_at = $postedAt->copy()->subMinutes($index);
            $record->updated_at = $record->created_at;
            $record->save();
            $created++;
        }

        $this->command?->info("Announcements created: {$created}.");
    }
}
