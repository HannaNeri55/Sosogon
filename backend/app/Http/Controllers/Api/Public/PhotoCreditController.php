<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\RetailShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Credits for openly licensed photos on public listings, shown together on the Photo Credits
 * page instead of under each image. Covers destination gallery photos, activity photos, shop and product photos and room
 * type photos that have a stored credit; photos without one (uploads by the tourism office or
 * owners) aren't listed. A credit starting "Representative photo:" is a photo that illustrates
 * the activity or room category rather than the place itself, and is flagged as such.
 */
class PhotoCreditController extends Controller
{
    public function index(): JsonResponse
    {
        $credit = function (string $subject, ?string $text, ?string $url) {
            $representative = Str::startsWith((string) $text, 'Representative photo:');

            return [
                'subject' => $subject,
                'credit' => trim($representative ? Str::after($text, 'Representative photo:') : (string) $text),
                'source_url' => $url,
                'representative' => $representative,
            ];
        };

        $destinations = Destination::published()
            ->with([
                'images' => fn ($q) => $q->whereNotNull('credit')->where('credit', '!=', '')->orderBy('sort_order'),
                'activities' => fn ($q) => $q->whereNotNull('image_credit')->where('image_credit', '!=', '')->orderBy('name'),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn ($d) => [
                'type' => 'destination',
                'id' => $d->id,
                'name' => $d->name,
                'credits' => $d->images->map(fn ($i) => $credit('Gallery photo', $i->credit, $i->source_url))
                    ->concat($d->activities->map(fn ($a) => $credit($a->name, $a->image_credit, $a->image_source_url)))
                    ->values(),
            ]);

        $accommodations = Accommodation::approved()
            ->with(['rooms' => fn ($q) => $q->approved()->with(['images' => fn ($i) => $i->whereNotNull('image_credit')->where('image_credit', '!=', '')])])
            ->orderBy('name')
            ->get()
            ->map(fn ($a) => [
                'type' => 'accommodation',
                'id' => $a->id,
                'name' => $a->name,
                'credits' => $a->rooms->flatMap(fn ($r) => $r->images->map(fn ($i) => $credit($r->name, $i->image_credit, $i->image_source_url)))->values(),
            ]);

        $shops = RetailShop::approved()
            ->with([
                'images' => fn ($q) => $q->whereNotNull('image_credit')->where('image_credit', '!=', '')->orderBy('sort_order'),
                'products' => fn ($q) => $q->approved()->with(['images' => fn ($i) => $i->whereNotNull('image_credit')->where('image_credit', '!=', '')]),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn ($s) => [
                'type' => 'shop',
                'id' => $s->id,
                'name' => $s->name,
                'credits' => $s->images->map(fn ($i) => $credit('Gallery photo', $i->image_credit, $i->image_source_url))
                    ->concat($s->products->flatMap(fn ($p) => $p->images->map(fn ($i) => $credit($p->name, $i->image_credit, $i->image_source_url))))
                    ->values(),
            ]);

        return response()->json([
            'listings' => $destinations->concat($accommodations)->concat($shops)->filter(fn ($l) => $l['credits']->isNotEmpty())->values(),
        ]);
    }
}
