<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\ImageOptimizationService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportEventPhotos extends Command
{
    protected $signature = 'images:import-event-photos';

    protected $description = 'Swap in real event photos placed under storage/app/public/events, replacing the placeholder SVGs';

    public function handle(ImageOptimizationService $images): int
    {
        $found = 0;
        $missing = 0;

        foreach (Event::all() as $event) {
            $slug = Str::slug($event->title);
            $match = collect(['jpg', 'jpeg', 'png', 'webp'])
                ->map(fn ($ext) => "events/{$slug}.{$ext}")
                ->first(fn ($path) => Storage::disk('public')->exists($path));

            if (! $match) {
                $this->line("Missing — name your photo: events/{$slug}.jpg   ({$event->title})");
                $missing++;

                continue;
            }

            $oldPath = $event->image_path;
            $absolutePath = Storage::disk('public')->path($match);
            $uploaded = new UploadedFile($absolutePath, basename($absolutePath), null, null, true);
            $newPaths = $images->store($uploaded, 'events');

            $event->update(['image_path' => $newPaths['image_path']]);

            if ($oldPath && $oldPath !== $match) {
                Storage::disk('public')->delete($oldPath);
            }
            Storage::disk('public')->delete($match);

            $this->info("Updated: {$event->title}");
            $found++;
        }

        $this->newLine();
        $this->info("Done. {$found} updated, {$missing} still on the placeholder.");

        return self::SUCCESS;
    }
}
