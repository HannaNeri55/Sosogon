<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Owner listings start as the placeholder type "Accommodation" with no categories, so their
 * badge read "Accommodation". This sets the category only where the listing's own name or
 * published description says what kind of place it is (quoted beside each one). Listings
 * that don't say, such as a lodge, a hostel or a "resort-style stay", are left unclassified
 * for the owner or an admin to set. A listing is changed only while it is still unclassified.
 */
return new class extends Migration
{
    private const STATED = [
        'Casa Buenas Hotel' => ['Hotel', '"Casa Buenas Hotel is a hotel in Bulan"'],
        'Caree Boutique Hotel' => ['Hotel', '"opened in December 2016 as the first hotel in Bulan"'],
        "Karl's Hotel" => ['Hotel', '"an affordable hotel in central Bulan"'],
        "Vivi's Hotel and Resto Bar" => ['Hotel', '"combines a small hotel with an evening resto bar"'],
        'Villa Isabel Hotel' => ['Hotel', '"a hotel complex with rooms"'],
        'Balay Talisay' => ['Guesthouse', '"a home-style guesthouse"'],
        "Uno's Krib" => ['Guesthouse', '"a budget guesthouse"'],
        'Isla de Blas' => ['Resort', '"a water resort"'],
        "Lim's Resort" => ['Resort', '"a resort in Barangay Bagsangan"'],
        'Siama Surf Resort' => ['Resort', 'Its name, and "a boutique beachfront resort"'],
        'Modesta Resort' => ['Resort', '"a Bali-inspired nature resort"'],
        'Recidencia Del Hamor Full View' => ['Resort', '"a 37-room resort"'],
        'Elysia Beach Resort' => ['Beach Resort', 'Its name, and "a beachfront resort"'],
        'Arcadia Crystal Subic Beach Resort' => ['Beach Resort', 'Its name, and "a beachfront island resort"'],
        'Pilar Marina Inn' => ['Inn', '"a coastal inn"'],
        'Altarejos Homestay' => ['Homestay', '"a local homestay"'],
        'Midz Homestay' => ['Homestay', '"a family homestay"'],
    ];

    public function up(): void
    {
        foreach (self::STATED as $name => [$category]) {
            DB::table('accommodations')
                ->where('name', $name)
                ->where('type', 'Accommodation')
                ->where(fn ($q) => $q->whereNull('classifications')->orWhereIn('classifications', ['', '[]']))
                ->update(['type' => $category, 'classifications' => json_encode([$category])]);
        }
    }

    public function down(): void
    {
        foreach (self::STATED as $name => [$category]) {
            DB::table('accommodations')
                ->where('name', $name)
                ->where('type', $category)
                ->where('classifications', json_encode([$category]))
                ->update(['type' => 'Accommodation', 'classifications' => null]);
        }
    }
};
