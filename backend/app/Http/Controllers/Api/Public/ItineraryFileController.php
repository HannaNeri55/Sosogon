<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Services\ItineraryPdfDownloads;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A prepared itinerary PDF (ItineraryPdfDownloads), requested by the browser itself: "download"
 * sends it as an attachment, which the browser's download manager saves to Downloads; "view"
 * shows it in the browser's PDF viewer. These are page loads rather than API calls, so an
 * expired link answers with a short page instead of JSON.
 */
class ItineraryFileController extends Controller
{
    public function __construct(private ItineraryPdfDownloads $downloads) {}

    public function show(Request $request, string $ticket, string $mode): Response
    {
        $download = $this->downloads->find($ticket);

        if (! $download) {
            return response($this->expiredPage(), 410, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
        }

        // The page also reads the file to keep its own offline copy (My Downloads); only the
        // browser's own request means the download to the device began.
        if ($mode === 'download' && ! $request->hasHeader('X-Sosogon-Copy')) {
            $this->downloads->markStarted($ticket);
        }

        $response = response()->file($this->downloads->path($ticket), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
        $response->setContentDisposition($mode === 'download' ? 'attachment' : 'inline', $download['filename']);

        return $response;
    }

    private function expiredPage(): string
    {
        $back = e(rtrim(config('app.frontend_url'), '/').'/my-itineraries');

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Download link expired · SOSOGON</title></head>
            <body style="font-family:system-ui,sans-serif;max-width:32rem;margin:0 auto;padding:2rem 1.25rem;color:#1f2937;line-height:1.6">
            <h1 style="font-size:1.4rem">This download link has expired</h1>
            <p>For your privacy, itinerary download links work for a few minutes only. Go back to SOSOGON, open your itinerary and press <strong>Download PDF</strong> again.</p>
            <p><a href="{$back}" style="color:#0f766e;font-weight:600">Go to My Itineraries</a></p>
            </body>
            </html>
            HTML;
    }
}
