<?php

// About-page profiles of Sorsogon's 14 municipalities and Sorsogon City (researched 2026-11-15),
// filled by MunicipalityProfileSeeder into empty fields only. Every fact comes from the sources
// listed with it: the Provincial Government's website (sorsogon.gov.ph, including its LGU email
// list and history timeline), the LGUs' own websites, and Wikipedia articles with references.
// Contact details that no official source confirmed are left null and show as "Not available";
// provincial admins complete them in the admin panel. Where accounts of a name differ, the
// text says so instead of picking one.

$province = 'https://sorsogon.gov.ph/?p=121 (LGU emails); https://sorsogon.gov.ph/about/history/ (provincial history timeline)';

return [
    'Barcelona' => [
        'lgu_type' => 'municipality',
        'email' => 'wework.barcelonaph@yahoo.com',
        'geography' => 'A coastal municipality on the eastern side of the province, facing the Philippine Sea. It covers 61.18 km² divided into 25 barangays, and is low-lying, rising from sea level to about 126 m.',
        'boundaries' => 'Gubat to the north, Bulusan to the south, Casiguran to the west, and the Philippine Sea to the east.',
        'name_origin' => 'The town was formerly called Danlog, after a local river. A Spanish official recommended the name Barcelona because he saw similarities with Barcelona in Catalonia, Spain.',
        'history' => "Before it became a town, the area was part of Gubat and Bulusan. Then known as Danlog and a visita of Gubat, Barcelona was created as a new town on 15 November 1865, and its legal existence as a pueblo civil was decreed on 16 April 1866.\n\nIt became an independent parish in 1868, with St. Joseph as its titular patron; it had earlier belonged to the parish of St. James the Great of Bulusan. In 1957 the barrio of Paghaluban was renamed Peña Francia.",
        'sources' => "{$province}; https://en.wikipedia.org/wiki/Barcelona,_Sorsogon",
    ],

    'Bulan' => [
        'lgu_type' => 'municipality',
        'email' => 'lgubulan@gmail.com',
        'geography' => 'Bulan lies at the south-westernmost tip of the Bicol Peninsula, along Ticao Pass, and has a fishing port serving its coastal waters. It covers 196.96 km² with 63 barangays. It is about 63 km from Sorsogon City and 667 km from Manila; Irosin is about 20 km away and Matnog about 30 km.',
        'boundaries' => 'Magallanes to the north, Juban and Irosin to the east, Matnog to the south, and Ticao Pass to the west.',
        'name_origin' => 'According to the Provincial Government, the name is widely believed to come from the Bikol word bulan, meaning "moon".',
        'history' => "The large settlement of Bulan, then a barrio of Gibalon, became a separate and independent parish in 1799. In 1801 Bulan was constituted into a pueblo civil under the province of Albay, with Don Juan Vicente as its first gobernadorcillo.\n\nThe 1818 census recorded 714 native families and 16 Spanish-Filipino families in the town. During the Philippine-American War, Colonel Emeterio Funes, commander of the revolutionary forces in Sorsogon, took the oath of allegiance to the United States in Bulan on 21 February 1901.",
        'sources' => "{$province}; https://en.wikipedia.org/wiki/Bulan,_Sorsogon; https://en.wikipedia.org/wiki/Bulusan,_Sorsogon",
    ],

    'Bulusan' => [
        'lgu_type' => 'municipality',
        'email' => 'lgu.bulusan@yahoo.com',
        'geography' => 'Bulusan lies at the foot of Mount Bulusan, the highest peak in Sorsogon, and reaches the coast overlooking the San Bernardino Strait and the Pacific Ocean. It covers 96.30 km² with 24 barangays, rising from sea level to about 620 m. Bulusan Lake, a crater lake, lies at the foot of the mountain, and the Bulusan, Bayugin, Paghasaan, Dulipay and Buklad rivers run through the town. The municipality also governs the San Bernardino islets, about 15 km offshore. It is about 55 km from Sorsogon City.',
        'boundaries' => 'Barcelona to the north; Casiguran, Juban, Irosin and Santa Magdalena on its other land sides; the San Bernardino Strait and the Pacific Ocean on its coast.',
        'name_origin' => '"Bulusan" is said to mean "where water flows".',
        'history' => "Bulusan was a visita of Casiguran until 1630, when it became an independent parish, first established at Inarado; it became a separate pueblo civil in 1631. In 1760 the town moved from Inarado to its present site at the mouth of the Bulusan River.\n\nBulusan was part of the province of Albay until Sorsogon became a province on 17 October 1894. On 25 April 1900, during the Philippine-American War, a battle was fought at Sitio Boco, San Francisco. The municipal building and public market were built in 1927, the provincial road to Barcelona and Irosin opened in 1930, and the first municipal waterworks was built in 1939. Japanese forces overran the town after the Second World War reached the Philippines in December 1941.",
        'sources' => "{$province}; https://en.wikipedia.org/wiki/Bulusan,_Sorsogon",
    ],

    'Casiguran' => [
        'lgu_type' => 'municipality',
        'email' => 'casiguranmio@gmail.com',
        'geography' => 'Casiguran lies on the coast of Sorsogon Bay, about 17 km from Sorsogon City and 598 km from Manila. It covers 87.13 km² with 25 barangays, and rises from sea level to about 162 m.',
        'boundaries' => 'Sorsogon City, Gubat, Barcelona, Juban and Bulusan, with Sorsogon Bay on its coast.',
        'name_origin' => 'Two explanations are told. One traces the name to Gugurang, the supreme deity of the indigenous Bicol peoples; a folk legend links it to the Bikol phrase "kasi gurang" ("because old").',
        'history' => "Established in 1600, Casiguran was the first missionary parish of Sorsogon. The Spaniards, who arrived in the 1570s, regarded it as their centre in Kabikolan, and in 1583 Franciscan missionaries took over its evangelization from the Augustinians.\n\nBacon, Bulusan, Juban and Magallanes were once visitas or barrios of Casiguran. The town assumed its legal existence as a pueblo civil of Albay in 1800, and the 1818 census recorded 1,023 native families and 28 Spanish-Filipino families.",
        'sources' => "{$province}; https://en.wikipedia.org/wiki/Casiguran,_Sorsogon; https://en.wikipedia.org/wiki/Sorsogon_City",
    ],

    'Castilla' => [
        'lgu_type' => 'municipality',
        'email' => 'progresibonglgucastilla@gmail.com',
        'other_contacts' => 'Website: castillasorsogon.gov.ph',
        'geography' => 'Castilla is in the northern part of the province, on Sorsogon Bay. It covers 186.20 km² with 34 barangays, 13 of them coastal, and rises from sea level to about 281 m. Malawmawan Island lies off the coast of Barangays Buenavista and Macalaya.',
        'boundaries' => 'Legazpi City and Manito (Albay) to the north, Sorsogon City to the east, and Pilar to the west, with Sorsogon Bay on its coast.',
        'name_origin' => 'Named after Castile in Spain. The place was formerly known as Bolabog.',
        'history' => "Castilla was formerly known as Bolabog, a visita of Sorsogon. It became a separate pueblo civil in 1827, and an independent parish on 24 June 1877.",
        'sources' => "{$province}; https://en.wikipedia.org/wiki/Castilla,_Sorsogon",
    ],

    'Donsol' => [
        'lgu_type' => 'municipality',
        'email' => 'donsollocalgovtph@gmail.com',
        'geography' => 'Donsol is on the western coast of the province, between Burias Pass and Ticao Pass, with hilly areas and fields inland and a port on its coast. It covers 156.20 km² with 51 barangays, and rises to about 84 m. It is about 66 km from Sorsogon City and 94 km from Legazpi.',
        'boundaries' => 'Pio Duran, Jovellar and Daraga in Albay; Pilar in Sorsogon; Burias Pass and Ticao Pass on its coast.',
        'name_origin' => 'The origin is uncertain. A popular account links it to the Spanish "Don Sol" ("Mr. Sun"), but the Provincial Government notes there is no record of Donsolanons ever practising such sun worship.',
        'history' => "Donsol was organized in 1655 as a barrio of Quipia (now Jovellar, Albay) and became an independent parish in 1668. In July 1754 large Moro vessels attacked a Spanish galiot opposite the town. Donsol became a pueblo civil in 1800.\n\nOn 26 December 1997 a group led by diving instructor Romir Aglugub encountered whale sharks off Donsol, and the town later became known as the \"Whale Shark Capital of the World\".",
        'sources' => "{$province}; https://en.wikipedia.org/wiki/Donsol",
    ],

    'Gubat' => [
        'lgu_type' => 'municipality',
        'email' => 'gubatsorsogon.lgu@gmail.com',
        'other_contacts' => 'Website: gubat.gov.ph',
        'geography' => 'Gubat is on the Pacific coast of the south-eastern Bicol Peninsula, facing Gubat Bay and the Philippine Sea. It covers 134.51 km² and has about 18,980 hectares of municipal waters and coral reef. It has 42 barangays, eight of them urban (poblacion), and rises to about 93 m.',
        'boundaries' => 'Prieto Diaz to the north, Sorsogon City to the northwest, Casiguran to the west, Barcelona to the south, and the Philippine Sea to the east.',
        'name_origin' => 'From the native verb "guinobat", meaning "raided", recalling the raids of Muslim pirates. (It is not from the Tagalog word gubat, "forest".)',
        'history' => "Gubat was originally a barrio of Bulusan. The first missionaries arrived on 13 June 1731, on their way from Bacon to Bulusan. On 13 June 1764 the Spanish government declared Gubat a pueblo civil under the province of Albay, and it became an independent parish on the same day, with Don Pedro Manook as its first gobernadorcillo.\n\nPreparations for its church began in 1768 and construction in 1778. Barcelona, then called Danlog, was a visita of Gubat until it became a town in 1865.",
        'sources' => "{$province}; https://en.wikipedia.org/wiki/Gubat,_Sorsogon",
    ],

    'Irosin' => [
        'lgu_type' => 'municipality',
        'email' => 'municipalityofirosin@gmail.com',
        'address' => 'Municipal Compound, San Julian, Irosin, Sorsogon 4707',
        'other_contacts' => 'Website: irosin.gov.ph',
        'geography' => 'Irosin is the only landlocked municipality in Sorsogon, lying inland in the area of the Irosin caldera beside Mount Bulusan. It covers 149.87 km² with 28 barangays, at elevations from about 20 m to 791 m. Its river has flooded the town in the past.',
        'boundaries' => 'Juban, Bulan, Bulusan, Matnog and Santa Magdalena.',
        'name_origin' => 'The town was earlier known as San Miguel. The meaning of the name Irosin is not documented in the sources consulted.',
        'history' => "Irosin, then known as San Miguel, became an independent parish in 1873 and an independent pueblo civil in 1880.\n\nOn 24 December 1933 massive flooding caused numerous deaths, and in 1937 a concrete river-control dike was built. On 10 April 1989 soldiers and police defended the town against an attack by about 200 New People's Army rebels.",
        'sources' => "{$province}; https://www.irosin.gov.ph/ (address); https://en.wikipedia.org/wiki/Irosin; https://en.wikipedia.org/wiki/Bulusan_Volcano",
    ],

    'Juban' => [
        'lgu_type' => 'municipality',
        'email' => 'lgujuban123@gmail.com',
        'geography' => 'Juban covers 121.49 km² with 25 barangays. Much of it is upland, with an average elevation of about 160 m and a highest point of about 833 m on the slopes of Bulusan Volcano, whose eruptions in June 2022 led to evacuations in Juban. It is about 22 km from Sorsogon City and 603 km from Manila.',
        'boundaries' => 'Sorsogon City, Magallanes, Casiguran, Bulan, Irosin and Bulusan.',
        'name_origin' => 'According to the Provincial Government, the name is believed to come from the Bikol word "gujuban", meaning to groove or etch with a sharp instrument.',
        'history' => "Juban became an independent pueblo civil under the province of Albay in 1800, and in 1817 it was separated from Casiguran as a parish. The 1818 census recorded 396 native families and 18 Spanish-Filipino families.\n\nHouses built during the Spanish period remain part of the town's heritage. Mario Guariña, governor of Sorsogon from 1908 to 1912 and senator from 1916 to 1919, was a native of Juban.",
        'sources' => "{$province}; https://en.wikipedia.org/wiki/Juban,_Sorsogon; https://en.wikipedia.org/wiki/Bulusan_Volcano",
    ],

    'Magallanes' => [
        'lgu_type' => 'municipality',
        'contact_number' => '(056) 411 6813 / (056) 411 1949',
        'email' => 'contact@magallanessorsogon.gov.ph',
        'address' => 'Municipal Building, Magallanes, Sorsogon',
        'other_contacts' => "Website: magallanessorsogon.gov.ph\nFacebook: facebook.com/MagayagayangMagallanesPh",
        'geography' => 'A coastal town facing Sorsogon Bay and Ticao Pass: 24 of its 34 barangays are along the coast. It covers 150.09 km² and rises to about 244 m. Bagatao Island, with its lighthouse, belongs to the town, and the Incarizan River reaches the sea here.',
        'boundaries' => 'Juban to the east and Bulan to the south; it shares Sorsogon Bay with Castilla and Sorsogon City, and faces Ticao Pass.',
        'name_origin' => 'The settlement was first called Parina (also written Parena), after a hardwood tree reputed to last for centuries. On 16 July 1860 it was renamed Magallanes in honour of Ferdinand Magellan.',
        'history' => "In 1569 the first exploration dispatched by Legazpi from Panay landed at Hibal-ong in Magallanes; Fray Alonso Jiménez, who came with it, later held the first Mass on the island of Luzon. In 1610 Governor General Juan de Silva established the Real Astillero de Bagatao, a royal shipyard at Calaguimi, where galleons were built; Maguindanao raiders sacked it on 18 October 1616, and the last galleon built in the Philippines, the Santísima Trinidad, was constructed there in 1751.\n\nMagallanes was organized as a barrio of Casiguran in 1852, its establishment as a new town was approved on 30 April 1859, and it became an independent parish in 1863. In 1854 bandits raided Barangay Ginangra, after which watchtowers were built and Bagatao Island was armed with cannons.",
        'sources' => "{$province}; https://magallanessorsogon.gov.ph/ (contact details); https://en.wikipedia.org/wiki/Magallanes,_Sorsogon",
    ],

    'Matnog' => [
        'lgu_type' => 'municipality',
        'email' => 'matnogsorsogon.lgu@gmail.com',
        'geography' => 'Matnog is at the southernmost part of Luzon, with its port on the San Bernardino Strait and Matnog Bay, and mangroves in Barangay Mambajog. It covers 162.40 km² with 40 barangays, and rises to about 354 m. It is about 65 km from Sorsogon City and 646 km from Manila.',
        'boundaries' => 'Bulan, Irosin and Santa Magdalena on land; across the San Bernardino Strait lie Allen, Capul and San Vicente in Northern Samar.',
        'name_origin' => 'Local elders trace it to the Bikol word "matonog", meaning "very audible", for the loud sound of the waves. Other accounts link it to a gong used by a council of chieftains, or to the roar of the tidal rip in the San Bernardino Strait.',
        'history' => "Matnog was a barrio of Bulusan, with Juan Evangelista as its first head in 1785. It became an independent pueblo civil under the province of Albay in 1800, and an independent parish separate from Bulusan in 1863.\n\nSanta Magdalena, then called Busaingan, belonged to Matnog until it became a separate parish in 1890.",
        'sources' => "{$province}; https://en.wikipedia.org/wiki/Matnog",
    ],

    'Pilar' => [
        'lgu_type' => 'municipality',
        'email' => 'pilarsor.mayor@gmail.com',
        'geography' => 'Pilar is on the western side of the province, on Ticao Pass, with Pilar Bay near Barangay San Antonio. It covers 248.00 km² with 49 barangays, and is low-lying, rising to about 82 m. It is about 55 km from Sorsogon City and 560 km from Manila.',
        'boundaries' => 'Donsol and Castilla in Sorsogon; Daraga and Legazpi City in Albay; Ticao Pass on its coast.',
        'name_origin' => 'The town is said to have been named after a newborn Spanish princess.',
        'history' => "On 6 August 1861 Pilar was created as a separate pueblo civil and, at the same time, an independent parish.\n\nOn 5 August 1898, during the revolution against Spain, shipyard workers in Panlatuan, Pilar rose up against the Spaniards. In 1957 the sitios of Naspi and Calaguitan were separated from the barrio of Putiao to form the barrio of Naspi.",
        'sources' => "{$province}; https://en.wikipedia.org/wiki/Pilar,_Sorsogon",
    ],

    'Prieto Diaz' => [
        'lgu_type' => 'municipality',
        'email' => 'lce.prietodiaz@yahoo.com',
        'geography' => 'Prieto Diaz is on the north-eastern coast of the province, about 20 km north-northeast of Sorsogon City, facing Albay Gulf to the north and the Pacific Ocean to the east. It covers 49.07 km² with 23 barangays, and is low-lying, rising to about 136 m.',
        'boundaries' => 'Rapu-Rapu (Albay) across Albay Gulf to the north, the Bacon District of Sorsogon City to the west, Gubat to the south, and the Pacific Ocean to the east.',
        'name_origin' => 'Named after two Bicolano martyr priests, Father Gabriel Prieto and Father Severino Diaz. The town was formerly known as Montupar.',
        'history' => "Prieto Diaz, formerly Montupar, was a visita of the old town of Bacon. After petitions for independence that began in 1833, it became an independent parish in 1881.\n\nOn 12 October 1903 it became a separate municipality under Act No. 940 of the Philippine Commission, making it the only town organized after Sorsogon became a province in 1894. In 1954 the sitio of Carayat became a barrio.",
        'sources' => "{$province}; https://en.wikipedia.org/wiki/Prieto_Diaz,_Sorsogon",
    ],

    'Santa Magdalena' => [
        'lgu_type' => 'municipality',
        'email' => 'lgu.smmo@gmail.com',
        'geography' => 'Santa Magdalena is in the south-eastern part of the province, on the Philippine Sea and the San Bernardino Strait. It covers 43.50 km² with 14 barangays: 4 in the lowland poblacion and 10 upland, with 12 coastal and 2 inland (Salvacion and La Esperanza). It rises from the coast to about 630 m.',
        'boundaries' => 'Bulusan, Irosin and Matnog, with the Philippine Sea and the San Bernardino Strait on its coast.',
        'name_origin' => 'The town was formerly known as Busaingan. The origin of either name is not documented in the sources consulted.',
        'history' => "Santa Magdalena, then called Busaingan, was part of Bulusan and later of Matnog. It became an independent parish separate from Matnog in October 1890.\n\nIn 1894 it became an independent pueblo under the province of Albay and, a few days later, came under the newly organized province of Sorsogon, with Isidro Gallanosa as its first gobernadorcillo. It is the province's smallest municipality by population (2020 census).",
        'sources' => "{$province}; https://en.wikipedia.org/wiki/Santa_Magdalena,_Sorsogon",
    ],

    'Sorsogon City' => [
        'lgu_type' => 'city',
        'contact_number' => '0991 483 4366 (City Mayor\'s Office)',
        'email' => 'mayor.esterhamor.lgusorcity@gmail.com',
        'address' => 'City Hall Complex, Barangay Cabid-an, Sorsogon City',
        'other_contacts' => "City Tourism Office: 0977 433 7983 / 0919 991 2289\nWebsite: sorsogoncity.gov.ph",
        'geography' => 'The provincial capital, about 581 km south-east of Manila and 60 km south of Legazpi City. It covers 276.11 km² with 64 barangays in its East, West and Bacon districts. The terrain is irregular: mountain ranges to the northwest, sloping uplands in the centre, plains in the southwest, centre-north and southeast, and marshlands on the south-eastern deltas, rising to about 1,078 m. It is surrounded by water: Sorsogon Bay to the west and south, Albay Gulf to the north, and the Philippine Sea to the east.',
        'boundaries' => 'Castilla to the west, Manito (Albay) to the northwest, Albay Gulf to the north, Prieto Diaz to the east, Gubat to the southeast, Casiguran to the southwest, and Sorsogon Bay to the south.',
        'name_origin' => 'By popular tradition, Spanish explorers asked a local man the name of a river site; thinking he was asked for directions, he answered "sosogon" ("to follow the river upstream"), which became Sorsogon.',
        'history' => "Archaeological finds suggest people lived in the Bacon area about 3,000 years ago. In the Spanish period Bacon was a visita of Casiguran and Sorsogon a visita of Bacon; Sorsogon became an independent parish in 1628, Bacon became the province's oldest pueblo civil in 1754, and Sorsogon became a political unit under Albay in 1864. In 1792 the Malaspina expedition reached Sorsogon harbour, which Malaspina described as the most beautiful that nature had shaped.\n\nWhen Sorsogon was separated from Albay on 17 October 1894, the town became the seat of the new province. In 1951 it became the seat of the Diocese of Sorsogon. Republic Act No. 8806, enacted on 16 August 2000, merged the municipalities of Sorsogon and Bacon into Sorsogon City; it was ratified and took effect in December 2000.",
        'sources' => "{$province}; https://sorsogoncity.gov.ph/directory/ (contact numbers); https://en.wikipedia.org/wiki/Sorsogon_City",
    ],
];
