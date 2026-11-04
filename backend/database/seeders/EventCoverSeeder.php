<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Support\EventCover;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Gives every event without a photo the same designed cover card: events with no
 * image, and events still using the old plain placeholder. Uploaded photos and
 * cards already in the new style are left alone, so it is safe to run again.
 */
class EventCoverSeeder extends Seeder
{
    public function run(): void
    {
        $updated = 0;

        Event::with('municipality:id,name')->orderBy('id')->each(function (Event $event) use (&$updated) {
            $path = $event->image_path;
            $isOldPlaceholder = $path && preg_match('#^events/[a-z0-9-]+\.svg$#', $path);
            if ($path && ! $isOldPlaceholder) {
                return; // A real upload, or already a new-style card.
            }

            $event->forceFill([
                'image_path' => EventCover::store($event->title, self::eyebrow($event->title), self::place($event), self::schedule($event)),
            ])->save();
            $updated++;
        });

        $this->command?->info("Event covers: {$updated} event(s) given the standard cover card.");
    }

    public static function eyebrow(string $title): string
    {
        return match (true) {
            str_contains($title, 'Barangay Fiesta') => 'Barangay Fiesta',
            str_contains($title, 'Festival') => 'Festival',
            str_contains($title, 'Fiesta') => 'Town Fiesta',
            default => 'Event',
        };
    }

    public static function place(Event $event): string
    {
        if ($event->municipality?->name) {
            return "{$event->municipality->name}, Sorsogon";
        }

        return $event->location ?: 'Province of Sorsogon';
    }

    /** "October 1–31", "May 19", "September 30 – October 7". */
    public static function schedule(Event $event): string
    {
        $start = Carbon::parse($event->start_date);
        $end = $event->end_date ? Carbon::parse($event->end_date) : $start;

        if ($start->isSameDay($end)) {
            return $start->format('F j');
        }
        if ($start->month === $end->month) {
            return $start->format('F j').'–'.$end->format('j');
        }

        return $start->format('F j').' – '.$end->format('F j');
    }
}
