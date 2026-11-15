<?php

namespace App\Services;

use App\Models\Itinerary;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Itinerary PDFs prepared for a real browser download. The PDF is made once and kept on the
 * server's own disk for a few minutes under a random ticket; the ticket's link is opened by the
 * browser itself (not fetched by the page), so the phone's download manager saves the file to
 * Downloads. Phones and in-app browsers that can't save a file made in the page (a blob: link)
 * can still save one sent by a server as an attachment.
 *
 * The ticket is the only key: whoever has the link can download that one file until it expires,
 * which is what lets "Open in Chrome" work from Messenger's browser without signing in again.
 */
class ItineraryPdfDownloads
{
    public const MINUTES = 10;

    public function __construct(private ItineraryDocumentService $documents) {}

    /** @return array{ticket: string, filename: string, expires_at: string} */
    public function prepare(Itinerary $itinerary): array
    {
        $this->prune();

        $ticket = Str::random(40);
        $filename = $this->documents->filename($itinerary);
        $this->disk()->put("{$ticket}.pdf", $this->documents->pdf($itinerary)->output());

        $expiresAt = now()->addMinutes(self::MINUTES);
        Cache::put($this->key($ticket), [
            'user_id' => $itinerary->user_id,
            'itinerary_id' => $itinerary->id,
            'filename' => $filename,
            'expires_at' => $expiresAt->getTimestamp(),
            'started' => false,
        ], $expiresAt);

        return ['ticket' => $ticket, 'filename' => $filename, 'expires_at' => $expiresAt->toIso8601String()];
    }

    /** The ticket's details while it is valid and its file is still there, otherwise null. */
    public function find(string $ticket): ?array
    {
        $download = Cache::get($this->key($ticket));

        return $download && $this->disk()->exists("{$ticket}.pdf") ? $download : null;
    }

    public function path(string $ticket): string
    {
        return $this->disk()->path("{$ticket}.pdf");
    }

    /** Remembers that the browser has fetched the file, so the page can say the download started. */
    public function markStarted(string $ticket): void
    {
        if ($download = Cache::get($this->key($ticket))) {
            Cache::put($this->key($ticket), ['started' => true] + $download, now()->setTimestamp($download['expires_at']));
        }
    }

    /** Files older than their link: links last MINUTES, so anything past twice that is unreachable. */
    private function prune(): void
    {
        $disk = $this->disk();
        $cutoff = now()->subMinutes(self::MINUTES * 2)->getTimestamp();

        foreach ($disk->files() as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
            }
        }
    }

    private function key(string $ticket): string
    {
        return "itinerary-pdf:{$ticket}";
    }

    /** Always the server's own disk, even where 'local' is a cloud bucket: the files live minutes. */
    private function disk(): Filesystem
    {
        return Storage::build(['driver' => 'local', 'root' => storage_path('app/itinerary-downloads'), 'throw' => false]);
    }
}
