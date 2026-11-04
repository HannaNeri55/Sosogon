<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\Rating;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Local/demo only. Seeds five sample reviews for each accommodation that
 * existed when demo content was added; accommodations created later are
 * deliberately not included.
 *
 * Every seeded comment starts with "Demo Review:" so sample data stays
 * identifiable in the database (the public UI strips the prefix). Each review
 * has its own demo Tourist account, so names do not repeat across listings.
 *
 * Idempotent: accounts are matched by email and reviews by
 * (user, accommodation), which the ratings unique index also enforces.
 */
class DemoAccommodationReviewSeeder extends Seeder
{
    private const PREFIX = 'Demo Review: ';

    private const CASA_ANGELA_ID = 16;

    /** Snapshot of accommodation ID => expected name; guards against seeding the wrong records. */
    private const ACCOMMODATIONS = [
        1 => 'Siama Hotel',
        2 => 'RedDoorz @ Caree Boutique Hotel',
        3 => 'Recidencia Del Hamor',
        4 => 'Sibol Private Resort',
        5 => 'DonPicaso Malawmawan Sands Resort',
        6 => 'Vitton Beach Resort',
        7 => "Dreamink farm og Myl's place",
        8 => 'Caliente Spring Resort',
        9 => 'Casa Roja (Juban Heritage House)',
        10 => 'Touch of Grace Re-Creation Center',
        11 => "Annabelle's Bed & Breakfast",
        12 => 'Chestnut Hill Hotel',
        13 => 'Christian John Ecotourism Resort and Hotel',
        14 => 'G Seascape Resort',
        15 => "Karl's Hotel",
        16 => 'Casa Angela Hotel & Suites',
    ];

    /**
     * The original Casa Angela reviewers (sosogon.demo.guest1-5@example.com,
     * user IDs 6-10) and their reviews, kept verbatim.
     */
    private const CASA_ANGELA_REVIEWS = [
        ['Maria Cristina Reyes', 5, 'The rooms were clean and comfortable. The location was convenient, and the staff made the stay feel welcoming.'],
        ['John Michael Santos', 4, 'A comfortable place to rest after exploring Sorsogon. The room was spacious and the overall experience was pleasant.'],
        ['Angela Mae Villanueva', 5, 'I liked the peaceful atmosphere and the comfortable room. It would be a nice choice for a relaxing weekend trip.'],
        ['Patricia Anne Mendoza', 4, 'The accommodation was comfortable and easy to locate. A few small improvements would make the experience even better.'],
        ['Daniel Luis Garcia', 5, 'A pleasant stay with a relaxing atmosphere. I especially appreciated having a comfortable place to unwind after a long day.'],
    ];

    /**
     * One entry per review for accommodations 1-15, five per listing in ID
     * order: [reviewer name, score, comment]. Comments stay general and avoid
     * claims about specific facilities that have not been verified.
     */
    private const REVIEWS = [
        ['Jasmine Dela Cruz', 5, 'Very relaxing stay. The staff greeted us warmly and checked on us during our visit.'],
        ['Mark Anthony Bautista', 4, 'Clean room and a calm environment. It was easy to find using the map.'],
        ['Kristine Joy Ramos', 5, 'We came for a weekend and left wishing we had booked more nights.'],
        ['Rafael Aquino', 3, 'Okay for a short overnight stop. Nothing fancy, but it served its purpose.'],
        ['Liza Marie Fernandez', 4, 'Friendly people and a quiet place to rest after a full day of travel.'],

        ['Joshua Castillo', 5, 'The room was neat when we arrived and the bed was comfortable. Highly recommended.'],
        ['Camille Navarro', 4, 'Check-in was quick and the staff answered all our questions patiently.'],
        ['Jerome Villareal', 5, 'A peaceful spot that made our trip to Sorsogon even better.'],
        ['Bea Soriano', 4, 'Good experience overall. We would consider staying here again.'],
        ['Carlo Domingo', 3, 'The place was fine, though it took a bit of time to get assistance when we needed it.'],

        ['Hazel Tolentino', 5, 'Our family felt at home here. Thank you to the staff for the warm welcome.'],
        ['Miguel Pascual', 4, 'Fair price for what you get. The surroundings were quiet at night.'],
        ['Rose Ann Lim', 5, 'Everything was in order from arrival to check-out. Smooth and pleasant.'],
        ['Paolo Gonzales', 4, 'Comfortable stay and helpful staff. A good option if you are touring the area.'],
        ['Diane Cruzado', 4, 'We liked how calm it was. It was nice to rest without much noise.'],

        ['Kevin Salazar', 5, 'Booked this for my parents and they really enjoyed it. They want to go back.'],
        ['Trisha Mae Ocampo', 3, 'Decent stay. The room was clean, but some fixtures looked a little worn.'],
        ['Ramon Evangelista', 5, 'Excellent hospitality. The staff remembered our names by the second day.'],
        ['Nicole Andrada', 4, 'A practical choice for travelers. Clean, simple, and convenient.'],
        ['Adrian Lacson', 5, 'Lovely place to unwind. We came back from our day trips feeling relaxed.'],

        ['Shiela Marquez', 4, 'The staff were courteous and the room was ready on time.'],
        ['Vincent Dimaculangan', 4, 'Pleasant stay. I appreciated how tidy everything was kept.'],
        ['Joanna Robles', 5, 'Perfect for our barkada trip. Everyone had a good time.'],
        ['Christian Abad', 3, 'Average experience. It is fine for a night, but I expected a bit more.'],
        ['Mylene Gatchalian', 5, 'Highly recommended for anyone visiting the province. We had a wonderful time.'],

        ['Arnel Espiritu', 4, 'Quiet surroundings and kind staff. A restful stay overall.'],
        ['Katrina Jimenez', 5, 'From booking to check-out, everything went well. Thank you for a great stay.'],
        ['Francis Quiambao', 4, 'Nice place with a homey feel. The staff made us feel welcome.'],
        ['Grace Ann Valdez', 4, 'Clean and comfortable. Would recommend for couples looking for a quiet getaway.'],
        ['Renz Manalo', 3, 'It was alright. Good enough for resting, though replies before our arrival could be faster.'],

        ['Precious Lagman', 5, 'Such a relaxing experience. I left feeling refreshed.'],
        ['Gabriel Ilagan', 4, 'The room was spacious enough for our group, and it was kept clean.'],
        ['April Joy Belmonte', 5, 'One of the most pleasant stays we had on our Bicol trip.'],
        ['Noel Agustin', 4, 'Polite staff and a calm atmosphere. Good value for the price.'],
        ['Charlene Uy', 4, 'Easy to reach and comfortable once we got there. No complaints.'],

        ['Bryan Dizon', 5, 'Wonderful hosts and a restful place. We will definitely return.'],
        ['Alyssa Torres', 3, 'The stay was acceptable. Some small details could be improved.'],
        ['Ronaldo Cabrera', 4, 'We enjoyed our short stay. The staff were attentive and kind.'],
        ['Faith Marasigan', 5, 'The atmosphere was so peaceful. It was exactly the break we needed.'],
        ['Dennis Panganiban', 4, 'Good place to stay for a few nights while exploring Sorsogon.'],

        ['Erika Samonte', 5, 'Very accommodating staff who were always ready to help.'],
        ['Jayson Lopez', 4, "Comfortable room and a quiet night's sleep. That is all we needed."],
        ['Mariel Concepcion', 4, 'Overall a nice stay. The place was well maintained.'],
        ['Elmer Bernardo', 5, 'We celebrated a family occasion here and it was a memorable stay.'],
        ['Clarisse De Leon', 3, 'Not bad. A simple place to rest, though it could use a few updates.'],

        ['Ricardo Velasco', 5, 'Great experience. The warmth of the people here made it special.'],
        ['Joy Ann Fajardo', 4, 'Tidy room and a peaceful environment. I would stay here again.'],
        ['Lester Mangubat', 4, 'A cozy place with friendly staff. Good for a weekend trip.'],
        ['Monica Del Rosario', 5, 'Truly enjoyed our stay. It felt safe and very relaxing.'],
        ['Aldrin Sison', 4, 'Convenient and comfortable. It made our travel days easier.'],

        ['Rhea Padilla', 5, 'Thank you for the hospitality. Our first visit to Sorsogon was a great one.'],
        ['Edwin Natividad', 3, 'The stay was okay. Clean enough, but it was a little noisy one evening.'],
        ['Stephanie Go', 4, 'Friendly service and a clean room. A solid choice.'],
        ['Rommel Aguilar', 5, 'Absolutely loved it here. We are already planning our next visit.'],
        ['Kimberly Esguerra', 4, 'Simple but comfortable. The staff were helpful whenever we asked.'],

        ['Jonathan Tan', 4, 'Nice and quiet. A good spot to recharge.'],
        ['Lovely Mae Carreon', 5, 'Exceptional stay. Everything felt well taken care of.'],
        ['Harold Buenaventura', 4, 'We had a pleasant time. The place matched what we expected.'],
        ['Danica Rivera', 3, 'Fine for the price. A few things could be better, but we rested well.'],
        ['Allan Perez', 5, "Warm, welcoming, and relaxing. Couldn't ask for more."],

        ['Cheska Montemayor', 4, 'Clean surroundings and respectful staff. Would recommend to friends.'],
        ['Rodel Saldivar', 5, 'Stayed here with my kids and they enjoyed it as much as we did.'],
        ['Irish Cortez', 4, 'Comfortable and calm. A good base during our trip around the province.'],
        ['Patrick Sy', 4, 'The staff were approachable and the room was neat.'],
        ['Janelle Hernandez', 5, 'A truly restful stay. We slept very well every night.'],

        ['Marvin Gumabao', 3, 'Overall okay. Check-in took a little longer than expected.'],
        ['Maricel Obispo', 5, 'Lovely experience from start to finish. Salamat po!'],
        ['Ian Cervantes', 4, 'Good place to stay for travelers who want something quiet and clean.'],
        ['Wendy Lacsamana', 4, 'A pleasant stay with friendly faces around. Thank you!'],
        ['Oliver Fuentes', 5, 'We were well taken care of. Highly recommended.'],

        ['Abigail Ponce', 4, 'The room was comfortable and the place felt secure.'],
        ['Ronald Lagdameo', 5, 'Very satisfied with our stay. It exceeded our expectations.'],
        ['Melissa Arevalo', 3, 'A reasonable place for a night. Basic, but clean.'],
        ['Jericho Dalisay', 4, 'Restful stay and courteous staff. We enjoyed our visit.'],
        ['Hannah Grace Encarnacion', 5, 'Memorable stay in Sorsogon. We would gladly come back.'],
    ];

    public function run(): void
    {
        // Demo data must never be seeded in production.
        if (app()->environment('production')) {
            throw new RuntimeException(
                'Demo reviews cannot be seeded in production.'
            );
        }

        $accommodations = Accommodation::whereIn('id', array_keys(self::ACCOMMODATIONS))
            ->get()
            ->keyBy('id');

        foreach (self::ACCOMMODATIONS as $id => $name) {
            if ($accommodations->get($id)?->name !== $name) {
                throw new RuntimeException(
                    "Accommodation ID {$id} does not match the expected property \"{$name}\"."
                );
            }
        }

        $touristRole = Role::where('name', 'tourist')->firstOrFail();

        DB::transaction(function () use ($accommodations, $touristRole) {
            $legacyUsers = [];
            foreach (self::CASA_ANGELA_REVIEWS as $index => [$name]) {
                $legacyUsers[] = $this->reviewer('sosogon.demo.guest'.($index + 1).'@example.com', $name, $touristRole);
            }

            // An earlier version of this seeder reused the five Casa Angela
            // reviewers on every listing. Remove only those demo rows.
            $removed = Rating::where('rateable_type', Accommodation::class)
                ->where('rateable_id', '!=', self::CASA_ANGELA_ID)
                ->whereIn('user_id', array_map(fn (User $user) => $user->id, $legacyUsers))
                ->where('comment', 'like', 'Demo Review:%')
                ->delete();

            $created = 0;

            foreach (self::CASA_ANGELA_REVIEWS as $index => [, $score, $comment]) {
                $created += $this->review($legacyUsers[$index], self::CASA_ANGELA_ID, $score, $comment) ? 1 : 0;
            }

            $otherIds = array_values(array_diff(array_keys(self::ACCOMMODATIONS), [self::CASA_ANGELA_ID]));

            foreach (self::REVIEWS as $index => [$name, $score, $comment]) {
                $user = $this->reviewer(
                    sprintf('sosogon.demo.reviewer%02d@example.com', $index + 1),
                    $name,
                    $touristRole
                );

                // Spread sample dates over roughly the past eight months.
                $daysAgo = 7 + ($index * 37) % 240;

                $created += $this->review($user, $otherIds[intdiv($index, 5)], $score, $comment, $daysAgo) ? 1 : 0;
            }

            // Aggregates include every rating, demo and genuine.
            foreach ($accommodations as $accommodation) {
                $accommodation->average_rating =
                    $accommodation->ratings()->avg('score') ?? 0;

                $accommodation->total_reviews =
                    $accommodation->ratings()->count();

                $accommodation->save();
            }

            $this->command?->info(
                "Demo accommodation reviews: {$created} created, {$removed} reused-reviewer rows removed."
            );
        });
    }

    private function reviewer(string $email, string $name, Role $touristRole): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'role_id' => $touristRole->id,
                'name' => $name,
                'password' => Hash::make(Str::random(48)),
                'status' => 'active',
            ]
        );

        if ($user->role_id !== $touristRole->id || $user->name !== $name) {
            throw new RuntimeException(
                "Demo email {$email} belongs to an unexpected user."
            );
        }

        return $user;
    }

    /** Creates the review if this reviewer has none for the accommodation; never modifies existing rows. */
    private function review(User $user, int $accommodationId, int $score, string $comment, ?int $daysAgo = null): bool
    {
        $rating = Rating::firstOrCreate(
            [
                'user_id' => $user->id,
                'rateable_type' => Accommodation::class,
                'rateable_id' => $accommodationId,
            ],
            [
                'score' => $score,
                'comment' => self::PREFIX.$comment,
            ]
        );

        if ($rating->wasRecentlyCreated) {
            $rating->forceFill(['is_demo' => true])->save();
        }

        if ($rating->wasRecentlyCreated && $daysAgo !== null) {
            $date = now()->subDays($daysAgo);
            $rating->timestamps = false;
            $rating->forceFill(['created_at' => $date, 'updated_at' => $date])->save();
        }

        return $rating->wasRecentlyCreated;
    }
}
