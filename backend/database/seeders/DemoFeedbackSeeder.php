<?php

namespace Database\Seeders;

use App\Models\Accommodation;
use App\Models\Destination;
use App\Models\FeedbackNote;
use App\Models\FeedbackSubmission;
use App\Models\FoodBeverage;
use App\Models\RetailShop;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo only. Twenty Feedback & Concerns submissions that read like real ones, to show the admin
 * page with every category and status. Production only when SEED_DEMO_FEEDBACK_ON_LIVE=yes, for
 * a site still used only for demos: a real Tourism Office would act on made-up concerns about
 * real listings. DEMO_PREVIEW=1 lists what would be added and writes nothing.
 *
 * Senders use example.com addresses (a reserved domain that reaches no one). A submission about
 * a listing is matched by name; when that listing doesn't exist here it is still added, without
 * the link. Idempotent: a submission with the same subject and message is never added twice.
 */
class DemoFeedbackSeeder extends Seeder
{
    /** Listing type => model, for the 'listing' field below. */
    private const MODELS = [
        'destination' => Destination::class,
        'accommodation' => Accommodation::class,
        'food_beverage' => FoodBeverage::class,
        'retail_shop' => RetailShop::class,
    ];

    /**
     * [category, module, [listing type, name] or null, sender name or null (anonymous), wants a reply,
     *  subject, message, status, days ago, office note or null]
     */
    private const SUBMISSIONS = [
        ['incorrect_information', 'destinations', ['destination', 'Bulusan Lake'], 'Carla Mendoza', true,
            'Kayak rental closes earlier than the page says',
            'We arrived at Bulusan Lake around 3:30 PM and the kayak rental was already closed. The staff told us rentals stop at 3 PM. Please update the page so other visitors plan their time better.',
            'resolved', 27, 'Checked with the Bulusan tourism office: kayak rental is until 3 PM only. Activity hours updated.'],
        ['incorrect_pricing', 'accommodations', ['accommodation', 'Villa Isabel Hotel'], 'Mark Anthony Reyes', true,
            'Room rate is higher than listed',
            'We were charged ₱2,800 for one night in the Deluxe room, but the website shows ₱2,300. The front desk said their rates changed last month. Kindly ask them to update the price.',
            'under_review', 21, 'Sent to the owner to update their room rates.'],
        ['incorrect_location', 'destinations', ['destination', 'Nagsipit Falls'], 'Joel Dizon', false,
            'Map pin goes to the barangay hall, not the falls',
            'The map pin for Nagsipit Falls points to the barangay hall. From there it is still about 20 minutes of walking to the trail start. Please move the pin to the jump-off point.',
            'pending', 3, null],
        ['suggestion', 'trip_planner', null, 'Aileen Santos', false,
            'Let us choose where we arrive',
            "I'm coming by bus and will arrive at the Sorsogon City terminal. It would help if the trip planner asked where we are arriving so the first day starts near the terminal and not across the province.",
            'pending', 5, null],
        ['technical', 'website', null, null, false,
            'Photos load very slowly on mobile data',
            'On mobile data the photos on the accommodation pages take a long time to load and some stay blank. On Wi-Fi they are fine. Using an Android phone with Chrome.',
            'under_review', 12, 'Checking image sizes on the accommodation pages.'],
        ['complaint', 'food_beverage', ['food_beverage', 'Dragon House'], 'Ramon Villanueva', false,
            'Long wait with no update from the staff',
            'We waited almost an hour for two orders during Sunday lunch. The food was good, but no one told us about the delay until we asked. Hope they can improve this.',
            'pending', 2, null],
        ['general', 'destinations', ['destination', 'Subic Beach'], 'Patricia Lim', false,
            'The early trip tip really helped',
            'Thank you for the tip to leave early for Subic Beach. We were on the first boat and had the beach almost to ourselves until 10 AM. Great website!',
            'resolved', 18, 'Thanked the sender. No action needed.'],
        ['incorrect_information', 'events', null, 'Grace Bautista', true,
            'Parade time is different from the posted program',
            "The event page shows the parade starting at 8:00 AM, but the program posted at the municipal hall says 2:00 PM. Which one is correct? We don't want to miss it.",
            'under_review', 6, 'Asked the municipal tourism office for the official program.'],
        ['suggestion', 'destinations', null, 'Edwin Navarro', false,
            'Please add accessibility information',
            "I travel with my mother who uses a wheelchair. It would help a lot if each destination said whether there are stairs, ramps, or paved paths, and if there is a restroom we can use.",
            'pending', 9, null],
        ['complaint', 'destinations', ['destination', 'Rompeolas'], null, false,
            'Trash near the steps in the evening',
            'There was a lot of plastic trash near the steps by the seawall last Saturday evening. Maybe more trash bins can be placed along the area.',
            'under_review', 10, 'Forwarded to the City Environment Office.'],
        ['incorrect_pricing', 'destinations', ['destination', 'Bayugin Falls'], 'Kristine Aquino', false,
            'Entrance fee does not include the parking fee',
            'We paid the entrance fee plus a separate ₱20 parking fee for our motorcycle. The page only shows the entrance fee. Please add the parking fee so visitors know.',
            'pending', 4, null],
        ['technical', 'trip_planner', null, 'Jerome Castillo', true,
            'Last day is missing in the trip PDF',
            'I downloaded the PDF of my 4-day trip, but the page for day 4 is blank. Day 4 shows fine on the website. I tried twice.',
            'under_review', 7, 'Could not reproduce yet. Asked the sender for the trip dates and towns.'],
        ['other', 'accommodations', ['accommodation', 'Siama Surf Resort'], 'Daniel Ocampo', true,
            'Do they offer surf lessons?',
            "Does this resort offer surf lessons for beginners, and how much per session? I couldn't find it on the page.",
            'pending', 1, null],
        ['incorrect_information', 'shops', ['retail_shop', "Nimfa's Pili Candies"], 'Liza Gonzales', false,
            'Store now closes earlier',
            'We went at 7 PM and the store was already closed. A sign on the door says they now close at 6 PM.',
            'resolved', 24, 'Owner confirmed the new closing time and updated the listing.'],
        ['general', 'website', null, 'Noel Fernandez', false,
            'Very helpful for first-time visitors',
            'First time in Sorsogon and this site helped us plan everything, from where to stay to what pasalubong to buy. Thank you!',
            'resolved', 15, null],
        ['complaint', 'accommodations', ['accommodation', "Twin's Heart Transient House"], 'Maricel Torres', true,
            'Room looks different from the photos',
            'The room we got was smaller than in the photos and had no window. Please ask the owner to post photos of the actual rooms.',
            'under_review', 8, 'Asked the owner to upload current photos of each room type.'],
        ['suggestion', 'food_beverage', null, 'Paolo Cruz', false,
            'Show which restaurants are open late',
            'We arrived late at night and had a hard time finding a place to eat. A filter for restaurants open after 9 PM would be very useful.',
            'pending', 11, null],
        ['incorrect_location', 'food_beverage', ['food_beverage', 'Lola Feling Restaurant'], 'Arnel Ramos', false,
            'Pin is one street off',
            'The map pin is on the next street. The restaurant is beside the corner store on the main road.',
            'resolved', 30, 'Pin moved after checking with the owner.'],
        ['other', 'website', null, 'Van Rental Services', false,
            'Van rentals for your tours',
            'We offer van rentals with driver for Sorsogon tours, airport and port transfers. Message us for rates and bookings.',
            'rejected', 14, 'Advertisement, not feedback.'],
        ['suggestion', 'destinations', ['destination', 'Malawmawan Island'], 'Bea Marquez', false,
            'Add the boat schedule going to the island',
            'Please add the boat schedule and fare from the port going to Malawmawan Island. We waited a long time because we did not know the trips are only in the morning.',
            'pending', 6, null],
    ];

    public function run(): void
    {
        // Made-up concerns must never reach the real Tourism Office.
        if (app()->environment('production') && env('SEED_DEMO_FEEDBACK_ON_LIVE') !== 'yes') {
            throw new RuntimeException('Set SEED_DEMO_FEEDBACK_ON_LIVE=yes to add demo feedback in production.');
        }
        $preview = (bool) env('DEMO_PREVIEW');

        $admin = User::where('role_id', Role::where('name', 'admin')->value('id'))->orderBy('id')->first();

        DB::transaction(function () use ($admin, $preview) {
            $created = 0;
            foreach (self::SUBMISSIONS as [$category, $module, $about, $name, $wantsReply, $subject, $message, $status, $daysAgo, $note]) {
                $hash = FeedbackSubmission::contentHash($subject, $message);
                if (FeedbackSubmission::where('content_hash', $hash)->exists()) {
                    continue;
                }

                $listing = $about ? self::MODELS[$about[0]]::where('name', $about[1])->first() : null;
                if ($preview) {
                    $this->command?->line("  {$subject}".($about ? ' ('.($listing ? $about[1] : "{$about[1]} not found, added without the listing").')' : ''));
                    $created++;

                    continue;
                }
                $sent = now()->subDays($daysAgo)->setTime(random_int(7, 21), random_int(0, 59));
                $handled = $status !== 'pending';

                $submission = new FeedbackSubmission([
                    'reference' => $this->reference($sent),
                    'name' => $name,
                    'email' => $name ? $this->email($name) : null,
                    'wants_reply' => $wantsReply,
                    'category' => $category,
                    'subject' => $subject,
                    'message' => $message,
                    'module' => $module,
                    'listing_type' => $listing ? $about[0] : null,
                    'listing_id' => $listing?->id,
                    'municipality_id' => $listing?->municipality_id,
                    'content_hash' => $hash,
                ]);
                $submission->forceFill([
                    'status' => $status,
                    'handled_by' => $handled ? $admin?->id : null,
                    'handled_at' => $handled ? $sent->copy()->addDay() : null,
                    'created_at' => $sent,
                    'updated_at' => $handled ? $sent->copy()->addDay() : $sent,
                ])->save();

                if ($note) {
                    $entry = new FeedbackNote(['feedback_submission_id' => $submission->id, 'user_id' => $admin?->id, 'note' => $note]);
                    $entry->forceFill(['created_at' => $sent->copy()->addDay(), 'updated_at' => $sent->copy()->addDay()])->save();
                }
                $created++;
            }

            $this->command?->info("Feedback & Concerns: {$created} ".($preview ? 'would be added.' : 'added.'));
        });
    }

    /** "FB-20261102-7K3Q", dated the day it was sent. */
    private function reference($sent): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        do {
            $code = collect(range(1, 4))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode('');
            $reference = 'FB-'.$sent->copy()->timezone('Asia/Manila')->format('Ymd').'-'.$code;
        } while (FeedbackSubmission::where('reference', $reference)->exists());

        return $reference;
    }

    private function email(string $name): string
    {
        return strtolower(preg_replace('/[^a-z]+/i', '.', trim($name))).'@example.com';
    }
}
