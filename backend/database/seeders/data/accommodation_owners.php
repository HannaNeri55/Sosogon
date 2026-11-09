<?php

/**
 * Accommodations from the project team's list (ACCOMMODATION.pdf, 2026-11-07).
 * Coordinates, phone numbers, hours and amenities are as listed; a null means the list gave none.
 * A business with no coordinates gets its owner account but no listing until coordinates are given.
 * Phones starting 0900 000 are made up, because the list asked for a number that is not real.
 */
$sample = 'Sample option based on the business photos: the name, beds and guest limit are not confirmed with the property.';

return [
    ['name' => 'BANTI AquaGrove', 'address' => 'Centennial Street, Poblacion Sur, Barcelona, Sorsogon, 4712', 'town' => 'Barcelona', 'barangay' => 'Poblacion Sur', 'lat' => '12.861455', 'lng' => '124.148736', 'phone' => '0900 000 0001', 'type' => 'Resort', 'classes' => ['Resort'], 'arrangement' => 'units', 'in' => '6:00 PM', 'out' => '7:00 AM',
        'about' => 'BANTI AquaGrove is a resort in Barcelona, Sorsogon, with a swimming pool and stays that range from a bamboo A-frame hut and suites to an attic room and a tree house.',
        'amenities' => ['Swimming Pool'], 'rooms' => [
            ['name' => 'Harmony Hut', 'unit_type' => 'cottage', 'max_guests' => 2, 'beds' => "Couple's bed", 'mode' => 'indicative', 'price' => 700, 'price_note' => 'Overnight, 6:00 PM to 7:00 AM, with free entrance for 2', 'features' => ['Air Conditioning']],
            ['name' => 'Seabreeze Terrace', 'unit_type' => 'room', 'max_guests' => 5, 'beds' => 'Bed and sofa bed', 'mode' => 'indicative', 'price' => 1500, 'price_note' => 'Overnight, 6:00 PM to 7:00 AM, for 2 guests. Extra guest ₱100'],
            ['name' => 'Suite Glory', 'unit_type' => 'room', 'max_guests' => 6, 'beds' => '1 queen bed, 1 sofa bed and 1 extra bed', 'mode' => 'indicative', 'price' => 2500, 'price_note' => 'Overnight, 6:00 PM to 7:00 AM, for 2 guests. Extra guest ₱100', 'features' => ['Air Conditioning', 'Full Bathroom', 'Dining Area']],
            ['name' => 'Suite Adeline', 'unit_type' => 'room', 'max_guests' => 6, 'beds' => '1 queen bed, 1 sofa bed and 1 extra bed', 'mode' => 'indicative', 'price' => 2500, 'price_note' => 'Overnight, 6:00 PM to 7:00 AM, for 2 guests. Extra guest ₱100', 'features' => ['Air Conditioning', 'Full Bathroom', 'Dining Area']],
            ['name' => "Bauy's Tranquility Attic", 'unit_type' => 'room', 'max_guests' => 10, 'beds' => '2 queen beds and 4 full-size beds', 'mode' => 'indicative', 'price' => 4000, 'price_note' => 'Overnight, 6:00 PM to 7:00 AM, for 10 guests. ₱100 per guest after 10', 'notes' => '3 restrooms on the ground floor.', 'features' => ['Air Conditioning']],
            ['name' => 'Dancalan Tree House', 'unit_type' => 'cabin', 'max_guests' => 6, 'beds' => '1 queen bed for 2 guests, and an attic room for up to 4', 'mode' => 'indicative', 'price' => 1500, 'price_max' => 3500, 'price_note' => 'For 2 guests with pool entrance: day stay ₱1,500, overnight ₱2,000, 22 hours ₱3,500. Extra guest ₱200', 'notes' => 'Day stay is 9:00 AM to 5:00 PM. Party area: umbrella cottage ₱250, extra table ₱100, chair ₱10.', 'features' => ['Air Conditioning', 'Lounge Area with Hammocks', 'Attic Room', 'Toiletries', 'Parking Space', "Children's Playground", 'Landscape Garden']],
        ], 'flag' => "The phone number is a placeholder, as the list asked. Rooms and rates are from posters marked Valentine's 2026: confirm they are current. Check-in and check-out are the overnight times. Tree house guest limit is 2 plus 4 from the poster."],

    ['name' => 'JALU Vacation House', 'address' => 'Poblacion Norte, Barcelona, Sorsogon', 'town' => 'Barcelona', 'barangay' => 'Poblacion Norte', 'lat' => '12.869836', 'lng' => '124.142954', 'phone' => '0900 000 0002', 'type' => 'Accommodation', 'classes' => ['Homestay'], 'arrangement' => 'entire_property', 'in' => null, 'out' => null,
        'about' => 'JALU Vacation House is a whole house for rent in Barcelona, Sorsogon, with two air-conditioned rooms, a kitchen and a gated parking area, for your exclusive use. Drinking water is not provided, but local stores just outside sell it.',
        'amenities' => ['Free Wi-Fi', 'Air Conditioning', 'Kitchen', 'Laundry Facilities', 'Gated Parking'],
        'rooms' => [['name' => 'Whole house', 'unit_type' => 'entire_property', 'bedrooms' => 2, 'features' => ['Air Conditioning', 'Kitchen', 'Cookware and Utensils', 'Washing Machine and Dryer'], 'notes' => 'Drinking water is not provided.']],
        'flag' => 'The phone number is a placeholder, as the list asked. The list gave no property type, so Homestay is an assumption.'],

    ['name' => "Cristina's Resort & Hotel", 'address' => 'Brgy. Guinlajon, Sorsogon City, Sorsogon, 4700', 'town' => 'Sorsogon City', 'barangay' => 'Guinlajon', 'lat' => '12.983096', 'lng' => '123.967459', 'phone' => '0900 000 0003', 'type' => 'Resort', 'classes' => ['Resort', 'Hotel'], 'arrangement' => 'units', 'in' => null, 'out' => null,
        'about' => "Cristina's Resort & Hotel in Guinlajon, Sorsogon City, offers Balinese-inspired villas with private pools, and guests can also use the resort's other pools.",
        'amenities' => ['Swimming Pool', 'Air Conditioning', 'Free Wi-Fi', 'Kitchen', 'KTV Room'],
        'rooms' => [
            ['name' => 'Bali Villa 1', 'unit_type' => 'villa', 'max_guests' => 10, 'features' => ['KTV Room', 'Private Kitchen', 'Private Pool', 'Access to Other Resort Pools']],
            ['name' => 'Bali Villa 2', 'unit_type' => 'villa', 'max_guests' => 6, 'beds' => '2 queen beds and 2 single beds', 'price_note' => '₱300 per person beyond 6 guests', 'notes' => 'Check-in 2:00 PM, check-out 12:00 noon.', 'features' => ['Air Conditioning', 'Private Pool', 'Hot and Cold Shower', 'Private Toilet', 'Smart TV with Cignal', 'Free Wi-Fi', 'Kitchen Utensils and LPG Stove', 'Refrigerator', 'Rice Cooker', 'Water Heater and Jug', 'Access to Other Resort Pools']],
        ], 'flag' => 'The phone number is a placeholder, as the list asked.'],

    ['name' => "Twin's Heart Transient House", 'address' => 'Brgy Road, Bibincahan, Sorsogon City', 'town' => 'Sorsogon City', 'barangay' => 'Bibincahan', 'lat' => '12.980901', 'lng' => '124.013147', 'phone' => '0991 606 3316', 'type' => 'Accommodation', 'classes' => ['Homestay'], 'arrangement' => 'units', 'in' => null, 'out' => null,
        'about' => "Twin's Heart Transient House in Bibincahan, Sorsogon City, has an air-conditioned studio room for 4 to 5 guests.",
        'amenities' => ['Air Conditioning', 'Parking', 'Refrigerator', 'Drinking Water', 'Free Wi-Fi', 'Bath Towels'],
        'rooms' => [['name' => 'Studio room', 'unit_type' => 'room', 'max_guests' => 5, 'features' => ['Air Conditioning', 'Refrigerator', 'Drinking Water', 'Wi-Fi', 'Bath Towels']]],
        'flag' => 'The list only says the studio is available October 12-18 for Kasanggayahan, so those dates were left out as they are not year-round.'],

    ['name' => 'IM Inn Sorsogon - Iconic Mardini Inn', 'address' => 'Purok 6, National Highway, Balogo, Sorsogon City (near SM Mall), 4700', 'town' => 'Sorsogon City', 'barangay' => 'Balogo', 'lat' => '12.976364', 'lng' => '124.020579', 'phone' => '0908 695 6732', 'type' => 'Inn', 'classes' => ['Inn'], 'arrangement' => 'units', 'in' => '2:00 PM', 'out' => '12:00 noon',
        'about' => 'IM Inn Sorsogon - Iconic Mardini Inn is an inn on the National Highway in Balogo, Sorsogon City, near SM Mall, with rooms from solo to penthouse.',
        'amenities' => ['Air Conditioning', 'Flat-Screen TV', 'Hot and Cold Shower', 'Working Station', 'Key Card Access', 'Free Wi-Fi', 'Netflix'],
        'rooms' => [
            ['name' => 'Solo Room', 'unit_type' => 'room', 'beds' => 'One single bed', 'mode' => 'indicative', 'price' => 700, 'price_max' => 900, 'price_note' => '₱700 for 12 hours, ₱900 overnight', 'notes' => 'Extra bed ₱500, extra hour ₱150.', 'features' => ['32-inch Flat-Screen TV', 'Netflix and YouTube', 'Wi-Fi', 'Split-Type Air Conditioner', 'Hot and Cold Shower', 'Working Station', 'Key Card Access']],
            ['name' => 'Standard Twin / Double', 'unit_type' => 'room', 'beds' => 'Two single beds or one double bed', 'mode' => 'indicative', 'price' => 1000, 'price_max' => 1500, 'price_note' => '₱1,000 for 12 hours, ₱1,500 overnight', 'notes' => 'Extra bed ₱500, extra hour ₱150.', 'features' => ['32-inch Flat-Screen TV', 'Netflix and YouTube', 'Wi-Fi', 'Split-Type Air Conditioner', 'Hot and Cold Shower', 'Working Station', 'Key Card Access']],
            ['name' => 'Deluxe Twin', 'unit_type' => 'room', 'beds' => 'Two single beds', 'mode' => 'indicative', 'price' => 1300, 'price_max' => 1800, 'price_note' => '₱1,300 for 12 hours, ₱1,800 overnight', 'notes' => 'Extra bed ₱500, extra hour ₱150.', 'features' => ['32-inch Flat-Screen TV', 'Netflix and YouTube', 'Wi-Fi', 'Split-Type Air Conditioner', 'Hot and Cold Shower', 'Working Station', 'Key Card Access']],
            ['name' => 'Deluxe Double', 'unit_type' => 'room', 'beds' => 'One double bed', 'mode' => 'indicative', 'price' => 1300, 'price_max' => 1800, 'price_note' => '₱1,300 for 12 hours, ₱1,800 overnight', 'notes' => 'Extra bed ₱500, extra hour ₱150.', 'features' => ['32-inch Flat-Screen TV', 'Netflix and YouTube', 'Wi-Fi', 'Split-Type Air Conditioner', 'Hot and Cold Shower', 'Working Station', 'Key Card Access']],
            ['name' => 'Deluxe Trio', 'unit_type' => 'room', 'beds' => 'One double bed and one single bed', 'mode' => 'indicative', 'price' => 1900, 'price_max' => 2400, 'price_note' => '₱1,900 for 12 hours, ₱2,400 overnight', 'notes' => 'Extra bed ₱500, extra hour ₱150.', 'features' => ['32-inch Flat-Screen TV', 'Netflix and YouTube', 'Wi-Fi', 'Split-Type Air Conditioner', 'Hot and Cold Shower', 'Working Station', 'Key Card Access']],
            ['name' => 'Quads', 'unit_type' => 'room', 'beds' => 'Two double beds', 'mode' => 'indicative', 'price' => 2600, 'price_max' => 3100, 'price_note' => '₱2,600 for 12 hours, ₱3,100 overnight', 'notes' => 'Extra bed ₱500, extra hour ₱150.', 'features' => ['32-inch Flat-Screen TV', 'Netflix and YouTube', 'Wi-Fi', 'Split-Type Air Conditioner', 'Hot and Cold Shower', 'Working Station', 'Key Card Access']],
            ['name' => 'Penthouse', 'unit_type' => 'room'],
        ], 'flag' => 'Rates are from the hotel rate card and posters. The rate card gives the Solo Room as ₱800, but the poster gives ₱700 for 12 hours and ₱900 overnight, which is what was saved: confirm it. The rate card gives no Penthouse rate.'],

    ['name' => 'Sorsogon NBS Transient and Tours', 'address' => 'Phase 3, Block 9, Lot 1, Seabreeze Homes, Cabid-an, Sorsogon City, 4700', 'town' => 'Sorsogon City', 'barangay' => 'Cabid-an', 'lat' => '12.974089', 'lng' => '124.026574', 'phone' => '09956734341', 'type' => 'Accommodation', 'classes' => ['Homestay'], 'arrangement' => null, 'in' => null, 'out' => null,
        'about' => 'Sorsogon NBS Transient and Tours is a homestay in Seabreeze Homes, Cabid-an, Sorsogon City.',
        'amenities' => [], 'rooms' => [
            ['name' => 'Twin Room', 'unit_type' => 'room', 'max_guests' => 2, 'beds' => '2 single beds', 'sample' => true, 'notes' => $sample],
            ['name' => 'Double Room', 'unit_type' => 'room', 'max_guests' => 2, 'beds' => '1 double bed', 'sample' => true, 'notes' => $sample],
        ], 'flag' => null],

    ['name' => 'Casa Issabella Airbnb, Transient & Apartment Rental', 'address' => 'Lot 14, Blk. 11, Barangay Balogo/Cabid-an, Sorsogon City, Sorsogon', 'town' => 'Sorsogon City', 'barangay' => 'Balogo', 'lat' => '12.978473', 'lng' => '124.022882', 'phone' => '+81 90-7549-9137', 'type' => 'Hotel', 'classes' => ['Hotel'], 'arrangement' => 'units', 'in' => '2:00 PM', 'out' => '11:00 AM',
        'about' => 'Casa Issabella in Sorsogon City offers fully air-conditioned rooms with a private bathroom, balcony and kitchenette, and self check-in. By the business, it is a 9-minute walk from SM Sorsogon, restaurants, cafes, hospitals, pharmacies, banks, the public market and the SITEX terminal.',
        'amenities' => ['Private Bathroom', 'Balcony', 'Private Kitchenette', 'Free Wi-Fi', 'Linens, Towels and Toiletries', 'Complimentary Coffee Set', 'Air Conditioning', 'Smart TV', 'Self Check-in', 'CCTV in Common Areas'],
        'rooms' => [['name' => 'Room for 2 guests', 'unit_type' => 'room', 'max_guests' => 2, 'mode' => 'indicative', 'price' => 1389, 'price_note' => 'From ₱1,389 per night for 2 guests. Extra bed ₱190', 'features' => ['Air Conditioning', 'Smart TV', 'Free Wi-Fi', 'Private Bathroom', 'Private Kitchenette', 'Balcony', 'Linens and Towels', 'Toiletries', 'Complimentary Coffee']]], 'flag' => 'The listed phone number is a Japan number (+81): confirm it. The list calls this a hotel, though the name says Airbnb, transient and apartment rental.'],

    ['name' => 'Casa Sereno Spring Resort', 'address' => 'Bliss, Monbon, Irosin, Sorsogon', 'town' => 'Irosin', 'barangay' => 'Monbon', 'lat' => '12.727941', 'lng' => '124.024030', 'phone' => '0951 476 3006', 'type' => 'Resort', 'classes' => ['Resort'], 'arrangement' => 'units', 'in' => null, 'out' => null,
        'about' => 'Casa Sereno Spring Resort is a resort at Bliss, Monbon, in Irosin, Sorsogon, with villas and rooms that have their own outdoor pools. By the resort, the pools use natural spring water.',
        'amenities' => ['Pool with Natural Spring Water', 'Air Conditioning', 'High-Speed Wi-Fi', 'Smart TV with Netflix', 'Hot and Cold Shower', 'Toiletries', 'Grilling Station', 'Kitchen', 'Karaoke'], 'rooms' => [
            ['name' => 'Villa Uno', 'unit_type' => 'villa', 'max_guests' => 13, 'bedrooms' => 2, 'beds' => 'Room for 6: 3 double beds. Room for 7: 2 double beds and 1 double deck', 'mode' => 'indicative', 'price' => 15000, 'price_note' => 'Rate from the resort brochure, which does not say if it is per night: confirm with the resort', 'features' => ['Outdoor Pool with Natural Spring Water', 'Poolside Lounge and Grilling Station', 'Air-Conditioned Rooms', 'Air-Conditioned Living Room', 'Kitchen and Dining Table', 'Refrigerator', 'Refillable Water Station', 'Two Bathrooms with Hot and Cold Shower', 'Toiletries', 'High-Speed Wi-Fi', 'Smart TV with Netflix', 'Sound System with Karaoke']],
            ['name' => 'Villa Dos', 'unit_type' => 'villa', 'max_guests' => 12, 'bedrooms' => 1, 'beds' => '3 double beds and 2 double decks', 'mode' => 'indicative', 'price' => 13000, 'price_note' => 'Rate from the resort brochure, which does not say if it is per night: confirm with the resort', 'features' => ['Private Pool with Natural Spring Water', 'Outdoor Grilling Station', 'Air-Conditioned Room', 'Air-Conditioned Living Room', 'Kitchen and Dining Table', 'Refrigerator', 'Refillable Water Station', 'Two Bathrooms with Hot and Cold Shower', 'Toiletries', 'High-Speed Wi-Fi', 'Smart TV with Netflix', 'Sound System with Karaoke']],
            ['name' => 'Deluxe Double Room', 'unit_type' => 'room', 'max_guests' => 2, 'beds' => '1 double bed', 'mode' => 'indicative', 'price' => 2500, 'price_note' => 'Rate from the resort brochure, which does not say if it is per night: confirm with the resort', 'features' => ['Private Pool with Natural Spring Water', 'Outdoor Lounge Area', 'Private Bathroom with Hot and Cold Shower', 'Smart TV with Netflix', 'Work Area', 'Unlimited Wi-Fi', 'Toiletries', 'Complimentary Water for 2']],
            ['name' => 'Twin Double Room', 'unit_type' => 'room', 'max_guests' => 4, 'beds' => '2 double beds', 'mode' => 'indicative', 'price' => 4000, 'price_note' => 'Rate from the resort brochure, which does not say if it is per night: confirm with the resort', 'notes' => 'Up to 4 extra beds at ₱500 each.', 'features' => ['Private Pool with Spring Water', 'Outdoor Lounge Area', 'Private Bathroom with Hot and Cold Shower', 'Dining Area', 'High-Speed Wi-Fi', 'Smart TV with Netflix', 'Toiletries', 'Complimentary Water for 4']],
        ], 'flag' => null],

    ['name' => 'Ohana Hometel', 'address' => 'San Roque, Bacon, Sorsogon City', 'town' => 'Sorsogon City', 'barangay' => 'San Roque', 'lat' => '13.016140', 'lng' => '124.026183', 'phone' => '+63 969 059 3889 / +63 994 344 9505', 'email' => 'ohanahometel@gmail.com', 'type' => 'Hotel', 'classes' => ['Homestay', 'Hotel'], 'arrangement' => 'units', 'in' => null, 'out' => null,
        'about' => 'Ohana Hometel in San Roque, Bacon, Sorsogon City, is a homestay and hotel. Stays include breakfast, and there is free parking for 8 cars.',
        'amenities' => ['Breakfast', 'Free Parking', 'Air Conditioning', 'Unlimited Wi-Fi', 'Netflix', 'Bath Towel', 'Toiletry Kit'], 'rooms' => [
            ['name' => 'Deluxe Room', 'unit_type' => 'room', 'max_guests' => 2, 'beds' => '1 double bed or 2 single beds', 'mode' => 'indicative', 'price' => 3000, 'price_note' => 'Per night, with breakfast. Prices may change without notice', 'notes' => 'On the 1st floor.', 'breakfast' => true, 'features' => ['Split-Type Air Conditioner', 'Unlimited Wi-Fi', 'Netflix', 'Bath Towel', 'Toiletry Kit']],
            ['name' => 'Superior Room', 'unit_type' => 'room', 'max_guests' => 2, 'beds' => '1 double bed or 2 single beds', 'mode' => 'indicative', 'price' => 3300, 'price_note' => 'Per night, with breakfast. Prices may change without notice', 'notes' => 'On the 2nd floor, with a better view of the mountain range.', 'breakfast' => true, 'features' => ['Split-Type Air Conditioner', 'Unlimited Wi-Fi', 'Netflix', 'Bath Towel', 'Toiletry Kit', 'Personal Refrigerator', 'Quality Furniture', 'Mountain Range View']],
            ['name' => 'Family Room', 'unit_type' => 'room', 'max_guests' => 4, 'mode' => 'indicative', 'price' => 5500, 'price_note' => 'Per night, with breakfast. Prices may change without notice', 'breakfast' => true, 'features' => ['Split-Type Air Conditioner', 'Unlimited Wi-Fi', 'Netflix', 'Bath Towel', 'Toiletry Kit', 'Private Entrance', 'Terrace', 'Walk-in Closet', 'Spacious Shower Room']],
        ], 'flag' => 'Rooms, rates and contact details are from the business poster.'],

    ['name' => 'FAITH Residences', 'address' => 'Burabod, Sorsogon City', 'town' => 'Sorsogon City', 'barangay' => 'Burabod', 'lat' => '12.972000', 'lng' => '124.003962', 'phone' => '09171154966', 'type' => 'Hotel', 'classes' => ['Hotel'], 'arrangement' => 'units', 'in' => '2:00 PM', 'out' => '11:00 AM',
        'about' => 'FAITH Residences in Burabod, Sorsogon City, is within walking distance of the Capitol Compound, downtown and the public market, and near Balogo Sports Arena, malls and bus stops bound for Sorsogon City Terminal. The list describes the area as flood-free, and the property has a backup solar power system.',
        'amenities' => ['Free Wi-Fi (300 Mbps)', 'Smart TV with Netflix', 'Private CR with Shower Heater', 'Air Conditioning', 'Kitchenette with Utensils', 'Towels and Guest Kit', 'Backup Solar Power', 'Free Drinking Water', 'Breakfast Nook Table'],
        'rooms' => [
            ['name' => 'Genesis Room', 'unit_type' => 'room', 'max_guests' => 4, 'mode' => 'indicative', 'price' => 1500, 'price_note' => 'From ₱1,500 per night for 2 guests. Extra charge for an additional person', 'description' => 'Calm, earth-inspired room with a personal refrigerator.'],
            ['name' => 'Exodus Room', 'unit_type' => 'room', 'max_guests' => 4, 'mode' => 'indicative', 'price' => 1500, 'price_note' => 'From ₱1,500 per night for 2 guests. Extra charge for an additional person', 'description' => 'Fresh, water-inspired room.'],
        ], 'flag' => null],

    ['name' => 'JV Transient House Sorsogon', 'address' => 'Tugos, Sorsogon City', 'town' => 'Sorsogon City', 'barangay' => 'Tugos', 'lat' => '12.971186', 'lng' => '123.989190', 'phone' => '09175933701', 'type' => 'Hotel', 'classes' => ['Hotel', 'Homestay'], 'arrangement' => null, 'in' => null, 'out' => null,
        'about' => 'JV Transient House Sorsogon is a transient house in Tugos, Sorsogon City.',
        'amenities' => [], 'rooms' => [
            ['name' => 'Whole house', 'unit_type' => 'entire_property', 'max_guests' => 6, 'beds' => '1 double bed and 1 bunk bed', 'bedrooms' => 2, 'sample' => true, 'notes' => $sample],
        ], 'flag' => 'Renamed from "Tugos Sorsogon City" at the request of the project team.'],

    ['name' => 'La Bella Luna Hotel', 'address' => 'Zone 4, Bulan, Sorsogon (in front of LCC1)', 'town' => 'Bulan', 'barangay' => null, 'lat' => '12.667620', 'lng' => '123.874483', 'phone' => '0938-299-0900', 'type' => 'Hotel', 'classes' => ['Hotel'], 'arrangement' => null, 'in' => null, 'out' => null,
        'about' => 'La Bella Luna Hotel is a hotel in Zone 4, Bulan, Sorsogon, in front of LCC.',
        'amenities' => [], 'rooms' => [
            ['name' => 'Double Room', 'unit_type' => 'room', 'max_guests' => 2, 'beds' => '1 double bed', 'sample' => true, 'notes' => $sample],
            ['name' => 'Twin Room', 'unit_type' => 'room', 'max_guests' => 4, 'beds' => '2 double beds', 'sample' => true, 'notes' => $sample],
        ], 'flag' => "The phone number is the same as Karl's Hotel in Bulan: confirm it."],

    ['name' => 'Bulusan Tourist Inn', 'address' => 'EME Bldg., Roxas Street, Brgy Central, Bulusan, Sorsogon', 'town' => 'Bulusan', 'barangay' => 'Central', 'lat' => '12.750608', 'lng' => '124.136002', 'phone' => '0968 354 4818', 'type' => 'Inn', 'classes' => ['Inn'], 'arrangement' => null, 'in' => null, 'out' => null,
        'about' => 'Bulusan Tourist Inn is an inn on Roxas Street in Central, Bulusan, with air-conditioned rooms and an in-house cafe.',
        'amenities' => ['Hot and Cold Shower', 'Air Conditioning', 'Basic Toiletries', 'Television', 'Free Wi-Fi', 'Complimentary Bottled Water', 'Electric Kettle', 'In-house Cafe'], 'rooms' => [
            ['name' => 'Double Room with Balcony', 'unit_type' => 'room', 'max_guests' => 2, 'beds' => '1 double bed', 'sample' => true, 'notes' => $sample],
            ['name' => 'Family Room', 'unit_type' => 'room', 'max_guests' => 4, 'beds' => '2 double beds and a sofa', 'sample' => true, 'notes' => $sample],
            ['name' => 'Bunk and Bed Room', 'unit_type' => 'room', 'max_guests' => 4, 'beds' => '1 bunk bed and 1 double bed', 'sample' => true, 'notes' => $sample],
            ['name' => 'Bunk Room', 'unit_type' => 'room', 'max_guests' => 6, 'beds' => '3 bunk beds, with lockers', 'sample' => true, 'notes' => $sample],
        ], 'flag' => null],

    ['name' => 'Vivencia Hometel', 'address' => 'Sumal-ot, Bacolod, Irosin, Sorsogon', 'town' => 'Irosin', 'barangay' => 'Bacolod', 'lat' => '12.701065', 'lng' => '124.037615', 'phone' => '0976 480 3874', 'type' => 'Hotel', 'classes' => ['Hotel', 'Homestay'], 'arrangement' => null, 'in' => '2:00 PM', 'out' => '11:00 AM',
        'about' => 'Vivencia Hometel is in Bacolod, Irosin. By the list, it is 1 minute from St. Michael the Archangel Parish Church, 5 minutes from San Benon Hot and Cold Spring, 10 minutes from Masakrot, 14 minutes from Balay sa Uma, 20 minutes from Dancalan Beach, 25 minutes from Zoe Resorts, and 30 minutes from Bulusan Lake and from Matnog.',
        'amenities' => ['Free Wi-Fi', 'Air Conditioning', 'Non-Smoking Rooms', 'Soap and Shampoo', 'Free Breakfast', 'Free Parking', 'Private Bathroom'], 'rooms' => [
            ['name' => 'Single Room', 'unit_type' => 'room', 'max_guests' => 1, 'beds' => '1 single bed', 'sample' => true, 'notes' => $sample],
            ['name' => 'Double Room', 'unit_type' => 'room', 'max_guests' => 2, 'beds' => '1 double bed', 'sample' => true, 'notes' => $sample],
            ['name' => 'Double Room with Sink', 'unit_type' => 'room', 'max_guests' => 2, 'beds' => '1 double bed', 'sample' => true, 'notes' => $sample],
        ], 'flag' => 'The list spells it "Vivencia Homete"; saved as Hometel, as its type says.'],

    ['name' => 'Melad Hotel', 'address' => 'Maharlika Highway, Cabid-an, Sorsogon City, 4700', 'town' => 'Sorsogon City', 'barangay' => 'Cabid-an', 'lat' => '12.976342', 'lng' => '124.026215', 'phone' => '0918 915 8005 / 0917 876 1338', 'type' => 'Hotel', 'classes' => ['Hotel'], 'arrangement' => 'units', 'in' => '2:00 PM', 'out' => '12:00 PM',
        'about' => 'Melad Hotel is on the Maharlika Highway in Cabid-an, Sorsogon City, with a restaurant serving breakfast, lunch and dinner, a coffee shop, event and meeting facilities, a rooftop area and a 24-hour front desk. Smoking and pets are not allowed.',
        'amenities' => ['Free Wi-Fi', 'Air Conditioning', 'Restaurant', 'Coffee Shop', 'Free Breakfast', 'Room Service', 'Concierge', 'Daily Housekeeping', 'Elevator', 'Gift Shop', 'Event Venue', 'Meeting Facilities', 'Laundry Service', 'Luggage Storage', 'Terrace', 'Family Rooms', 'CCTV', '24-hour Front Desk', 'Rooftop Area', 'Free Parking', 'Airport Transfer', 'Car Rental'],
        'rooms' => [
            ['name' => 'Queen Room', 'unit_type' => 'room', 'beds' => '1 queen bed'],
            ['name' => 'Triple Room', 'unit_type' => 'room'],
            ['name' => 'Standard Quad Room', 'unit_type' => 'room', 'max_guests' => 4, 'beds' => '2 queen beds', 'breakfast' => true, 'features' => ['Air Conditioning', 'Flat-Screen TV', 'Complimentary Wi-Fi', 'Complimentary Refreshments', 'Private Bathroom with Hot and Cold Shower', 'Toiletries', 'Complimentary Breakfast for 4']],
            ['name' => 'Family Room', 'unit_type' => 'room', 'max_guests' => 6, 'beds' => '3 queen beds', 'breakfast' => true, 'features' => ['Air Conditioning', 'Flat-Screen TV', 'Complimentary Wi-Fi', 'Complimentary Refreshments', 'Private Bathroom with Hot and Cold Shower', 'Toiletries', 'Complimentary Breakfast for 6']],
        ], 'flag' => 'The list mentions bottled water but also marks free bottled water in rooms as unavailable, so it was left out.'],

    ['name' => 'Cozynest Sorsogon', 'address' => 'Purok 3, Balogo, Sorsogon City, 4700', 'town' => 'Sorsogon City', 'barangay' => 'Balogo', 'lat' => '12.976602', 'lng' => '124.014014', 'phone' => '0939 494 1407', 'type' => 'Accommodation', 'classes' => ['Homestay'], 'arrangement' => null, 'in' => '2:00 PM', 'out' => '11:00 AM',
        'about' => 'Cozynest Sorsogon is a budget homestay in Balogo, Sorsogon City, with self check-in by keybox, a kitchenette and free parking. It is pet-friendly and follows a clean-as-you-go policy. By the business, it is near SM Sorsogon and the Sorsogon Sports Arena.',
        'amenities' => ['Air Conditioning', 'Kitchenette', 'Free Parking', 'Self Check-in (Keybox)', 'Pet-Friendly', 'Smart TV', 'Wi-Fi', 'Cookware and Dining Ware'], 'rooms' => [
            ['name' => 'Left Cabin', 'unit_type' => 'cabin', 'max_guests' => 2, 'beds' => '1 double bed', 'sample' => true, 'notes' => $sample],
            ['name' => 'Right Cabin', 'unit_type' => 'cabin', 'max_guests' => 2, 'beds' => '1 double bed', 'sample' => true, 'notes' => $sample],
            ['name' => 'Group Cabin', 'unit_type' => 'cabin', 'max_guests' => 6, 'beds' => 'Floor mattresses', 'sample' => true, 'notes' => $sample],
        ], 'flag' => 'Some posters use the name Tiny Cabin Sorsogon with the same phone number: confirm the name. Rates were left out because the posters show promos (one says August 4-30 only).'],

    ['name' => "Gabz'K Hotel & Resort", 'address' => 'Purok 7, San Pedro, Irosin, Sorsogon, 4707', 'town' => 'Irosin', 'barangay' => 'San Pedro', 'lat' => '12.701726', 'lng' => '124.026916', 'phone' => '0981 293 6995', 'type' => 'Resort', 'classes' => ['Hotel', 'Resort'], 'arrangement' => null, 'in' => null, 'out' => null,
        'about' => "Gabz'K Hotel & Resort is in San Pedro, Irosin, Sorsogon.", 'amenities' => [], 'rooms' => [
            ['name' => 'Double Room', 'unit_type' => 'room', 'max_guests' => 2, 'beds' => '1 double bed', 'sample' => true, 'notes' => $sample],
            ['name' => 'Twin Room', 'unit_type' => 'room', 'max_guests' => 2, 'beds' => '2 single beds', 'sample' => true, 'notes' => $sample],
            ['name' => 'Triple Room', 'unit_type' => 'room', 'max_guests' => 3, 'beds' => '3 single beds', 'sample' => true, 'notes' => $sample],
            ['name' => 'Family Room', 'unit_type' => 'room', 'max_guests' => 6, 'beds' => '1 double bed and 2 bunk beds', 'sample' => true, 'notes' => $sample],
        ], 'flag' => null],

    ['name' => 'Peaks&Pedals Transient', 'address' => 'M.L. Quezon St., San Pedro, Irosin, Sorsogon', 'town' => 'Irosin', 'barangay' => 'San Pedro', 'lat' => '12.704718', 'lng' => '124.031530', 'phone' => '09455087191', 'type' => 'Accommodation', 'classes' => ['Homestay'], 'arrangement' => 'entire_property', 'in' => '2:00 PM', 'out' => '12:00 noon',
        'about' => 'Peaks&Pedals Transient is a single third-floor unit in San Pedro, Irosin, with no air conditioning (fan only), a pour-flush toilet and no front desk: the host meets guests at the property. A clean-as-you-go policy applies, there are no short-time stays, and mountain bikes and a scooter are for rent.',
        'amenities' => ['Television', 'Free Wi-Fi', 'Kitchen', 'Nighttime Car Park', 'CCTV', 'Bicycle Rental', 'Scooter Rental'],
        'rooms' => [['name' => 'Third-floor unit', 'unit_type' => 'entire_property', 'unit_count' => 1, 'features' => ['Fan Only', 'Television', 'Free Wi-Fi', 'Kitchen', 'Cooking and Eating Utensils', 'Pour-Flush Toilet']]], 'flag' => null],

    ['name' => 'Gipsy Adovis Hotel', 'address' => 'Bgy. Adovis, Casiguran, Sorsogon, 4702', 'town' => 'Casiguran', 'barangay' => null, 'lat' => '12.869902', 'lng' => '124.010387', 'phone' => '0933 869 9745', 'type' => 'Hotel', 'classes' => ['Hotel'], 'arrangement' => 'units', 'in' => null, 'out' => null,
        'about' => 'Gipsy Adovis Hotel is in Adovis, Casiguran, with air-conditioned rooms and non-air-conditioned cottages.', 'amenities' => ['Common Bathroom'],
        'rooms' => [
            ['name' => 'A/C Triple Sharing', 'unit_type' => 'room', 'features' => ['Air Conditioning']],
            ['name' => 'A/C Standard', 'unit_type' => 'room', 'features' => ['Air Conditioning']],
            ['name' => 'A/C Family Room', 'unit_type' => 'room', 'features' => ['Air Conditioning']],
            ['name' => 'Open Cottage', 'unit_type' => 'cottage', 'notes' => 'Not air-conditioned.'],
        ], 'flag' => null],

    ['name' => "Carlito's Inn", 'address' => 'Sitio Cagpacol, Barangay Lungib, Casiguran, Sorsogon, 4702', 'town' => 'Casiguran', 'barangay' => 'Lungib', 'lat' => null, 'lng' => null, 'phone' => '09513388504', 'type' => 'Inn', 'classes' => ['Inn'], 'arrangement' => null, 'in' => null, 'out' => null,
        'about' => "Carlito's Inn is in Barangay Lungib, Casiguran, Sorsogon.", 'amenities' => [], 'rooms' => [], 'flag' => null],

    ['name' => 'Tanayad Campsite', 'address' => 'Sitio Cagpacol, Barangay Lungib, Casiguran, Sorsogon, 4702', 'town' => 'Casiguran', 'barangay' => 'Lungib', 'lat' => '12.865703', 'lng' => '124.001082', 'phone' => '0928 520 2928', 'type' => 'Accommodation', 'classes' => ['Homestay'], 'arrangement' => null, 'in' => null, 'out' => null,
        'about' => 'Tanayad Campsite in Barangay Lungib, Casiguran, has air-conditioned A-frame cabins among trees, with hammocks around the grounds and a shared toilet and shower block. By the campsite, breakfast is free with a cabin booking.', 'amenities' => ['A-Frame Cabins', 'Air Conditioning', 'Free Breakfast', 'Hammocks', 'Shared Toilets and Showers'], 'rooms' => [
            ['name' => 'A-Frame Cabin', 'unit_type' => 'cabin', 'max_guests' => 3, 'beds' => '1 double bed and 1 single mattress', 'sample' => true, 'notes' => $sample],
        ], 'flag' => "The address is the same as Carlito's Inn (Sitio Cagpacol, Barangay Lungib): confirm."],

    ['name' => 'Sibol', 'address' => 'Purok 1, Sogoy, Castilla, Sorsogon', 'town' => 'Castilla', 'barangay' => 'Sogoy', 'lat' => '12.979312', 'lng' => '123.781427', 'phone' => '+63 993 752 2374', 'type' => 'Resort', 'classes' => ['Resort'], 'arrangement' => 'units', 'in' => null, 'out' => null,
        'about' => 'Sibol is a resort in Sogoy, Castilla, with private cabins, a swimming pool, a bonfire area, open cottages, a restobar and a view of the nearby river.',
        'amenities' => ['Private Cabins', 'Kitchen', 'Bonfire Area', 'River View', 'Swimming Pool', 'Open Cottage', 'Restobar'],
        'rooms' => [
            ['name' => 'Solo package', 'unit_type' => 'other', 'max_guests' => 1, 'mode' => 'indicative', 'price' => 999, 'price_max' => 1499, 'price_note' => 'Weekdays ₱999, weekends ₱1,499'],
            ['name' => 'Couple package', 'unit_type' => 'other', 'max_guests' => 2, 'mode' => 'indicative', 'price' => 1999, 'price_max' => 2499, 'price_note' => 'Weekdays ₱1,999, weekends ₱2,499'],
            ['name' => 'Small group package', 'unit_type' => 'other', 'max_guests' => 5, 'mode' => 'indicative', 'price' => 3999, 'price_max' => 4499, 'price_note' => '3-5 guests. Weekdays ₱3,999, weekends ₱4,499'],
            ['name' => 'Barkada package', 'unit_type' => 'other', 'max_guests' => 9, 'mode' => 'indicative', 'price' => 4999, 'price_max' => 5499, 'price_note' => '6-9 guests. Weekdays ₱4,999, weekends ₱5,499'],
            ['name' => 'Family package', 'unit_type' => 'other', 'max_guests' => 15, 'mode' => 'indicative', 'price' => 5999, 'price_max' => 6499, 'price_note' => 'Weekdays ₱5,999 for 10-12 guests, weekends ₱6,499 for 10-15 guests'],
        ], 'flag' => null],
];
