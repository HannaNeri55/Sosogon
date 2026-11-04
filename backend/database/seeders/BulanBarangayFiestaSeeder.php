<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Municipality;
use App\Models\Role;
use App\Models\User;
use App\Support\EventCover;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Bulan barangay fiestas from the Municipality of Bulan's official "Barangay Fiesta"
 * calendar (May–December posters), plus normalisation of the January–May fiestas that
 * were entered by hand. One event per calendar entry, with the exact names it lists.
 *
 * Idempotent: events are matched by their names (including the old hand-entered titles),
 * so running it again creates nothing new and leaves admin edits alone. Placeholder
 * descriptions and missing images are the only things filled in on existing events.
 */
class BulanBarangayFiestaSeeder extends Seeder
{
    /** Description the hand-entered events were created with (replaced with a real one). */
    private const PLACEHOLDER_DESCRIPTION = 'Bulan, Sorsogon Barangay Fiesta';

    /**
     * Each entry: names (as on the calendar), schedule, and the old titles it may already exist under.
     * Schedule: ['days' => [month, startDay, endDay]] or ['nth' => [month, n, weekday]] (n = -1 for "last").
     * 'keep_dates' => true: an existing event whose dates were entered by hand; its dates are not recalculated.
     */
    private array $fiestas = [
        // ---------- Entered by hand before the posters (January–April and early May) ----------
        ['names' => ['Zone 2-A'], 'days' => [1, 8, 9], 'aliases' => ['Zone 2-A, Bulan Fiesta'], 'keep_dates' => true],
        ['names' => ['Taromata'], 'days' => [1, 16, 17], 'aliases' => ['Taromata, Bulan, Sorsogon Fiesta'], 'keep_dates' => true],
        ['names' => ['Cadandanan'], 'days' => [1, 17, 19], 'aliases' => ['Cadandanan, Bulan, Sorsogon Fiesta'], 'keep_dates' => true],
        ['names' => ['Marinab'], 'days' => [1, 19, 20], 'aliases' => ['Marinab, Bulan, Sorsogon Fiesta'], 'keep_dates' => true],
        ['names' => ['Zone 1'], 'days' => [2, 10, 11], 'aliases' => ['Zone 1, Bulan Fiesta'], 'keep_dates' => true],
        ['names' => ['Magsaysay'], 'days' => [2, 11, 11], 'aliases' => ['Magsaysay Fiesta'], 'keep_dates' => true],
        ['names' => ['San Vicente'], 'days' => [4, 3, 5], 'aliases' => ['San Vicente, Bulan Fiesta'], 'keep_dates' => true],
        ['names' => ['Quezon'], 'days' => [4, 19, 20], 'aliases' => ['Quezon, Bulan Fiesta'], 'keep_dates' => true],
        ['names' => ['Sagrada'], 'days' => [4, 29, 30], 'aliases' => ['Sagrada, Bulan Fiesta'], 'keep_dates' => true],

        // ---------- May (poster) ----------
        ['names' => ['Obrero'], 'days' => [5, 1, 1], 'aliases' => ['Obrero, Bulan Fiesta']],
        ['names' => ['Bliss'], 'days' => [5, 1, 2], 'aliases' => ['Bliss, Bulan Fiesta']],
        ['names' => ['Osmeña'], 'days' => [5, 2, 3], 'aliases' => ['Osmena, Bulan Fiesta']],
        ['names' => ['Daganas', 'Sitio San Jose'], 'days' => [5, 3, 4], 'aliases' => ['Daragas Sitio San Jose, Bulan Fiesta']],
        ['names' => ['Abad Santos', 'Sitio Pawa'], 'days' => [5, 4, 5], 'aliases' => ['Abad Santos Sitio Pawa, Bulan Fiesta']],
        ['names' => ['Cocok-Cabitan'], 'days' => [5, 5, 6], 'aliases' => ['Cocok-Cabitan, Bulan Fiesta']],
        ['names' => ['Sitio Beguin Daan', 'Sitio Gabud'], 'days' => [5, 6, 7], 'aliases' => ['Sitio Beguin Daan, Bulan Fiesta']],
        ['names' => ['Bonga'], 'days' => [5, 8, 9], 'aliases' => ['Bonga, Bulan Fiesta']],
        ['names' => ['Aguinaldo'], 'days' => [5, 10, 11]],
        ['names' => ['Montecalvario', 'Sitio Layuan'], 'days' => [5, 11, 12]],
        ['names' => ['M. Roxas', 'Zone 8', 'Sitio Pawic'], 'days' => [5, 12, 13]],
        ['names' => ['Fabrica', 'R. Gerona', 'San Isidro', 'Sitio Buntay'], 'days' => [5, 14, 15]],
        ['names' => ['Danao', 'Sitio Oyango'], 'days' => [5, 15, 16]],
        ['names' => ['Calomagon', 'G. del Pilar', 'J. Gerona'], 'days' => [5, 16, 17]],
        ['names' => ['Gate', 'Magsaysay', 'San Juan Daan', 'Sitio Liman', 'Burabod'], 'days' => [5, 18, 19]],
        ['names' => ['Aquino', 'Sitio Salvacion'], 'days' => [5, 21, 22]],
        ['names' => ['A. Bonifacio'], 'days' => [5, 22, 23]],
        ['names' => ['Lajong', 'Sitio Taromata II'], 'days' => [5, 23, 24]],
        ['names' => ['San Rafael Saday', 'Sitio Lumacao'], 'days' => [5, 24, 25]],
        ['names' => ['Antipolo'], 'days' => [5, 25, 26]],

        // ---------- June ----------
        ['names' => ['Sta. Remedios'], 'days' => [6, 4, 5]],
        ['names' => ['N. Roque'], 'nth' => [6, 2, Carbon::FRIDAY]],
        ['names' => ['San Francisco'], 'days' => [6, 12, 13]],
        ['names' => ['Calpi'], 'days' => [6, 19, 20]],
        ['names' => ['San Juan Bag-o'], 'days' => [6, 23, 24]],

        // ---------- July ----------
        ['names' => ['Butag'], 'days' => [7, 14, 15]],

        // ---------- August ----------
        ['names' => ['Jamorawon'], 'days' => [8, 3, 4]],
        ['names' => ['Palale'], 'days' => [8, 7, 8]],
        ['names' => ['E. Quirino'], 'days' => [8, 10, 11]],
        ['names' => ['Recto'], 'days' => [8, 11, 12]],
        ['names' => ['Beguin', 'Namo', 'Zone 2-B'], 'days' => [8, 14, 15]],
        ['names' => ['Inararan'], 'days' => [8, 15, 16]],
        ['names' => ['Zone 7-B'], 'days' => [8, 20, 21]],
        ['names' => ['Somagongsong'], 'days' => [8, 26, 27]],
        ['names' => ['Otavi'], 'days' => [8, 27, 28]],
        ['names' => ['San Ramon'], 'days' => [8, 30, 31]],

        // ---------- September ----------
        ['names' => ['Immaculate Concepcion Subdivision'], 'days' => [9, 7, 8]],
        ['names' => ['Zone 6'], 'nth' => [9, 3, Carbon::SATURDAY]],
        ['names' => ['Zone 3'], 'days' => [9, 22, 23]],
        ['names' => ['Managa-naga'], 'days' => [9, 27, 28]],

        // ---------- October ----------
        ['names' => ['Sta. Teresita'], 'days' => [10, 2, 3]],
        ['names' => ['Sitio Daan na Bisita (Polot)'], 'days' => [10, 3, 4]],
        ['names' => ['Sitio Inalapan'], 'days' => [10, 14, 15]],
        ['names' => ['Sitio Costanera (Namo)'], 'days' => [10, 22, 23]],
        ['names' => ['Bical', 'Padre Diaz', 'San Rafael', 'Zone 7-A'], 'days' => [10, 23, 24]],
        ['names' => ['Nasuje', 'Sigad'], 'days' => [10, 24, 25]],
        ['names' => ['JP Laurel', 'Zone 7'], 'days' => [10, 25, 26]],
        ['names' => ['Zone 4'], 'days' => [10, 27, 28]],

        // ---------- November ----------
        ['names' => ['Sigad'], 'nth' => [11, 3, Carbon::SUNDAY]],
        ['names' => ['Dolos', 'Sitio Tarhan'], 'days' => [11, 26, 27]],

        // ---------- December ----------
        ['names' => ['Zone 5'], 'days' => [12, 7, 8]],
        ['names' => ['Libertad'], 'nth' => [12, -1, Carbon::SUNDAY]],
    ];

    private const ORDINALS = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', -1 => 'Last'];

    private const WEEKDAYS = [
        Carbon::SUNDAY => 'Sunday', Carbon::MONDAY => 'Monday', Carbon::TUESDAY => 'Tuesday', Carbon::WEDNESDAY => 'Wednesday',
        Carbon::THURSDAY => 'Thursday', Carbon::FRIDAY => 'Friday', Carbon::SATURDAY => 'Saturday',
    ];

    public function run(): void
    {
        $bulan = Municipality::where('name', 'Bulan')->first();
        $adminRole = Role::where('name', 'admin')->value('id');
        $admin = $adminRole ? User::where('role_id', $adminRole)->orderBy('id')->first() : null;

        if (! $bulan || ! $admin) {
            $this->command?->error('BulanBarangayFiestaSeeder needs the Bulan municipality and an admin user.');

            return;
        }

        $today = Carbon::today('Asia/Manila');
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        DB::transaction(function () use ($bulan, $admin, $today, &$counts) {
            foreach ($this->fiestas as $entry) {
                $title = $this->title($entry['names']);
                $schedule = $this->scheduleText($entry);
                $event = $this->findExisting($title, $entry['aliases'] ?? [], $bulan->id);

                if (! $event) {
                    [$start, $end] = $this->nextOccurrence($entry, $today);
                    Event::create([
                        'title' => $title,
                        'description' => $this->description($entry['names'], $schedule),
                        'municipality_id' => $bulan->id,
                        'location' => null,
                        'image_path' => EventCover::store($title, 'Barangay Fiesta', 'Bulan, Sorsogon', $schedule),
                        'start_date' => $start,
                        'end_date' => $end,
                        'is_featured' => false,
                        'status' => 'published',
                        'created_by' => $admin->id,
                    ] + $this->visitorInfo());
                    $counts['created']++;

                    continue;
                }

                // Existing event: fix only what is clearly a placeholder or a hand-entry slip.
                $changes = [];
                if ($event->title !== $title && in_array($event->title, $entry['aliases'] ?? [], true)) {
                    $changes['title'] = $title;
                }
                if ($event->municipality_id === null) {
                    $changes['municipality_id'] = $bulan->id;
                }
                if (trim((string) $event->description) === self::PLACEHOLDER_DESCRIPTION) {
                    $changes['description'] = $this->description($entry['names'], $schedule);
                }
                if (empty($event->what_to_wear) && empty($event->what_to_bring) && empty($event->travel_tips)) {
                    $changes += $this->visitorInfo();
                }
                if (! $event->image_path) {
                    $changes['image_path'] = EventCover::store($title, 'Barangay Fiesta', 'Bulan, Sorsogon', $schedule);
                }

                if ($changes) {
                    $event->forceFill($changes)->save();
                    $counts['updated']++;
                } else {
                    $counts['unchanged']++;
                }
            }

            // The Bulan Town Fiesta runs May 30–31 on the municipal calendar (it was stored as May 30 only).
            $town = Event::where('title', 'Padaraw Festival & Bulan Town Fiesta')->first();
            if ($town && $town->start_date && $town->start_date->format('m-d') === '05-30' && (! $town->end_date || $town->end_date->format('m-d') === '05-30')) {
                $town->forceFill(['end_date' => $town->start_date->copy()->setDay(31)->toDateString()])->save();
                $counts['updated']++;
            }
        });

        $this->command?->info("Bulan barangay fiestas: {$counts['created']} created, {$counts['updated']} updated, {$counts['unchanged']} already up to date.");
    }

    /** "Obrero Barangay Fiesta", "Daganas & Sitio San Jose Barangay Fiesta", "A, B & C Barangay Fiesta". */
    private function title(array $names): string
    {
        return $this->joinNames($names).' Barangay Fiesta';
    }

    private function joinNames(array $names, string $last = '&'): string
    {
        if (count($names) === 1) {
            return $names[0];
        }

        return implode(', ', array_slice($names, 0, -1))." {$last} ".end($names);
    }

    private function findExisting(string $title, array $aliases, int $bulanId): ?Event
    {
        return Event::whereIn('title', array_merge([$title], $aliases))
            ->where(fn ($q) => $q->where('municipality_id', $bulanId)->orWhereNull('municipality_id'))
            ->orderBy('id')
            ->first();
    }

    /** Recurring schedule in words: "May 3–4", "May 1", "2nd Friday of June", "Last Sunday of December". */
    private function scheduleText(array $entry): string
    {
        if (isset($entry['nth'])) {
            [$month, $n, $weekday] = $entry['nth'];

            return self::ORDINALS[$n].' '.self::WEEKDAYS[$weekday].' of '.Carbon::create(2000, $month, 1)->format('F');
        }
        [$month, $from, $to] = $entry['days'];
        $name = Carbon::create(2000, $month, 1)->format('F');

        return $from === $to ? "{$name} {$from}" : "{$name} {$from}–{$to}";
    }

    /** Next date range on or after today (Philippine time), or this year's if it is still running. */
    private function nextOccurrence(array $entry, Carbon $today): array
    {
        foreach ([$today->year, $today->year + 1] as $year) {
            if (isset($entry['nth'])) {
                [$month, $n, $weekday] = $entry['nth'];
                $first = Carbon::create($year, $month, 1);
                $start = $n === -1 ? $first->lastOfMonth($weekday) : $first->nthOfMonth($n, $weekday);
                $end = $start->copy();
            } else {
                [$month, $from, $to] = $entry['days'];
                $start = Carbon::create($year, $month, $from);
                $end = Carbon::create($year, $month, $to);
            }
            if ($end->gte($today->copy()->startOfDay())) {
                return [$start->toDateString(), $end->toDateString()];
            }
        }

        return [$start->toDateString(), $end->toDateString()];
    }

    private function description(array $names, string $schedule): string
    {
        $who = $this->joinNames($names, 'and');
        $verb = count($names) === 1 ? 'celebrates its' : 'celebrate their';
        $together = count($names) === 1 ? '' : ' together';
        // "on May 3–4", but "on the 3rd Sunday of November" / "on the last Sunday of December".
        $when = preg_match('/^(\d|Last )/', $schedule) ? 'the '.lcfirst($schedule) : $schedule;

        return "{$who} in Bulan, Sorsogon {$verb} annual barangay fiesta{$together} on {$when}, "
            ."as listed in the Municipality of Bulan's official barangay fiesta calendar.\n\n"
            .'Barangay fiestas are community celebrations open to visitors, and they are a good chance to experience '
            .'local Bicolano hospitality. Schedules and activities are set by each barangay, so please confirm the '
            .'details with the barangay hall or the Bulan Tourism Office before you travel.';
    }

    private function visitorInfo(): array
    {
        return [
            'what_to_wear' => "Light, comfortable clothing suited to Bicol's tropical heat",
            'what_to_bring' => 'Water, sun protection, cash, and a light rain jacket',
            'travel_tips' => [
                'Confirm the schedule with the barangay hall or the Bulan Tourism Office before traveling.',
                'Streets near the barangay center may be busy during the celebration; plan extra travel time.',
                'Modest dress is appreciated if you join any church services.',
            ],
        ];
    }
}
