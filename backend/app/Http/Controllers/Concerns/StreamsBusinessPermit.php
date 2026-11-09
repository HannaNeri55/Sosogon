<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a business permit from the private disk. Callers must check the
 * requester first. PDFs open inline (browser preview); DOC/DOCX download.
 * The storage path never leaves the server.
 */
trait StreamsBusinessPermit
{
    protected function streamPermit(?string $path, string $baseName): StreamedResponse
    {
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'Business permit not found.');

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION)) ?: 'pdf';
        $filename = "{$baseName}.{$extension}";
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];

        return $extension === 'pdf'
            ? Storage::disk('local')->response($path, $filename, $headers + ['Content-Type' => 'application/pdf'])
            : Storage::disk('local')->download($path, $filename, $headers);
    }
}
