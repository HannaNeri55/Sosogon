<?php

/**
 * Products per shop, keyed by "name|town". No prices: the businesses have not given any.
 * 'basis' says why a product is listed: stated (the shop's own description), name (the shop's name)
 * or typical (a common item in this kind of shop, not confirmed with the business).
 * 'photo' is one representative photo in the image folder, not used by any other shop.
 */
return [
    'Bulan Public Market|Bulan' => [
        'basis' => 'stated',
        'products' => [
            ['name' => 'Fresh seafood', 'type' => 'Local Food Product', 'about' => 'The day\'s catch of fish, shellfish and other seafood from Sorsogon waters.', 'photo' => 'c0872'],
            ['name' => 'Fresh produce', 'type' => 'Local Food Product', 'about' => 'Vegetables and fruits from local farms, sold fresh.', 'photo' => 'c0876'],
            ['name' => 'Local handicrafts', 'type' => 'Handicraft', 'about' => 'Handmade items crafted by local artisans from native materials.', 'photo' => 'c0454'],
        ],
    ],
    'Matnog Port Public Market|Matnog' => [
        'basis' => 'stated',
        'products' => [
            ['name' => 'Fresh crabs and squid', 'type' => 'Local Food Product', 'about' => 'Crabs and squid from local waters, sold fresh for cooking.', 'photo' => 'c1027'],
            ['name' => 'Snacks', 'type' => 'Local Food Product', 'about' => 'Local snacks and light bites to enjoy on the go.', 'photo' => 'c0983'],
            ['name' => 'Local souvenirs', 'type' => 'Souvenir', 'about' => 'Souvenir items that capture the place, made to take home or give as gifts.', 'photo' => 'c0502'],
        ],
    ],
    'PiliNuts Pasalubong Center|Matnog' => [
        'basis' => 'stated',
        'products' => [
            ['name' => 'Sweetened pili nuts', 'type' => 'Local Food Product', 'about' => 'Pili nuts cooked in sugar until sweet and crunchy.', 'photo' => 'p0000'],
            ['name' => 'Pili candies', 'type' => 'Local Food Product', 'about' => 'Sweets made from pili nuts, Sorsogon\'s signature nut, often caramelized or sugar-coated.', 'photo' => 'c0006'],
            ['name' => 'Roasted pili nuts', 'type' => 'Local Food Product', 'about' => 'Pili nuts roasted until golden and crunchy.', 'photo' => 'p0003'],
        ],
    ],
    'Gibalon Heritage Pasalubong & Delicacies|Magallanes' => [
        'basis' => 'stated',
        'products' => [
            ['name' => 'Glazed pili nuts', 'type' => 'Local Food Product', 'about' => 'Pili nuts roasted and coated in a crisp sugar glaze.', 'photo' => 'p0008'],
            ['name' => 'Mazapan de pili', 'type' => 'Local Food Product', 'about' => 'A crumbly sweet made from ground pili nuts and sugar, a Bicol favorite.', 'photo' => 'c0004'],
            ['name' => 'Tablea discs', 'type' => 'Local Food Product', 'about' => 'Discs of pure ground cacao, melted to make thick, rich hot chocolate.', 'photo' => 'c0976'],
        ],
    ],
    'Leslie Pili Nut Products|Sorsogon City' => [
        'basis' => 'stated',
        'products' => [
            ['name' => 'Glazed pili', 'type' => 'Local Food Product', 'about' => 'Pili nuts coated in a light sugar glaze, crunchy and sweet.', 'photo' => 'p0002'],
            ['name' => 'Roasted salted pili', 'type' => 'Local Food Product', 'about' => 'Pili nuts roasted and lightly salted, a savory snack.', 'photo' => 'p0001'],
            ['name' => 'Pili oil products', 'type' => 'Local Food Product', 'about' => 'Products made with oil pressed from pili nuts.', 'photo' => 'c0042'],
        ],
    ],
    "Tia Berning's Store & Native Handicrafts|Sorsogon City" => [
        'basis' => 'stated',
        'products' => [
            ['name' => 'Handwoven abaca slippers', 'type' => 'Handicraft', 'about' => 'Light, comfortable slippers woven from abaca fiber.', 'photo' => 'c0476'],
            ['name' => 'Native bayong bags', 'type' => 'Handicraft', 'about' => 'Traditional woven bags made from buri or pandan leaves, sturdy and roomy.', 'photo' => 'c0455'],
            ['name' => 'Handcrafted keepsakes', 'type' => 'Souvenir', 'about' => 'Small handmade souvenirs crafted from native materials.', 'photo' => 'c0612'],
        ],
    ],
    'Bagol Craft & Souvenir Shop|Sorsogon City' => [
        'basis' => 'stated',
        'products' => [
            ['name' => 'Coconut shell lamps', 'type' => 'Handicraft', 'about' => 'Lamps and lampshades carved from coconut shell that cast patterned light.', 'photo' => 'c0663'],
            ['name' => 'Coconut shell trinkets', 'type' => 'Souvenir', 'about' => 'Small keepsakes and accessories made from polished coconut shell.', 'photo' => 'c0660'],
            ['name' => 'Coconut shell home decor', 'type' => 'Handicraft', 'about' => 'Home decorations crafted from polished coconut shell, each with its own natural pattern.', 'photo' => 'c0650'],
        ],
    ],
    'Bagatao Island Shellcrafts & Collectibles|Magallanes' => [
        'basis' => 'stated',
        'products' => [
            ['name' => 'Shell jewelry', 'type' => 'Souvenir', 'about' => 'Necklaces, bracelets and earrings made from seashells.', 'photo' => 'c0646'],
            ['name' => 'Shell wind chimes', 'type' => 'Souvenir', 'about' => 'Wind chimes made from seashells that ring softly in the breeze.', 'photo' => 'c0669'],
            ['name' => 'Ocean-themed home decor', 'type' => 'Handicraft', 'about' => 'Home decor inspired by the sea, made with shells and other coastal materials.', 'photo' => 'c0655'],
        ],
    ],
    "Manay Jo's Handicraft & Native Goods|Santa Magdalena" => [
        'basis' => 'stated',
        'products' => [
            ['name' => 'Handwoven abaca products', 'type' => 'Handicraft', 'about' => 'Bags, mats and decor woven by hand from abaca fiber, a native material of the Bicol region.', 'photo' => 'c0475'],
            ['name' => 'Decorative mats', 'type' => 'Handicraft', 'about' => 'Colorful handwoven mats made for display or everyday use around the home.', 'photo' => 'c0573'],
            ['name' => 'Native storage baskets', 'type' => 'Handicraft', 'about' => 'Handwoven baskets made for storing clothes, food or household items.', 'photo' => 'c0494'],
        ],
    ],
    'San Sebastian Dried Fish & Coastal Produce|Santa Magdalena' => [
        'basis' => 'stated',
        'products' => [
            ['name' => 'Tuyo (dried fish)', 'type' => 'Local Food Product', 'about' => 'Small salted and dried fish, usually fried and paired with rice and vinegar.', 'photo' => 'c0375'],
            ['name' => 'Danggit (dried fish)', 'type' => 'Local Food Product', 'about' => 'Small rabbitfish split open, salted and sun-dried, best fried until crisp and served with rice.', 'photo' => 'c0379'],
            ['name' => 'Dried squid', 'type' => 'Local Food Product', 'about' => 'Squid that is cleaned, salted and sun-dried, eaten grilled or fried as a snack or viand.', 'photo' => 'c0416'],
        ],
    ],
    "Juana's Handicrafts & Souvenirs|Matnog" => [
        'basis' => 'stated',
        'products' => [
            ['name' => 'Keychains', 'type' => 'Souvenir', 'about' => 'Small souvenir keychains, easy to bring home and share.', 'photo' => 'c0740'],
            ['name' => 'Shell crafts', 'type' => 'Souvenir', 'about' => 'Decorative items made from seashells.', 'photo' => 'c0652'],
            ['name' => 'Handwoven abaca products', 'type' => 'Handicraft', 'about' => 'Bags, mats and decor woven by hand from abaca fiber, a native material of the Bicol region.', 'photo' => 'c0450'],
        ],
    ],
    'OTOP Hub Sorsogon|Sorsogon City' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Suman', 'type' => 'Local Food Product', 'about' => 'Sticky rice cooked in coconut milk and wrapped in banana or palm leaves.', 'photo' => 'c0105'],
            ['name' => 'Polvoron', 'type' => 'Local Food Product', 'about' => 'A crumbly shortbread of toasted flour, milk powder, sugar and butter, pressed into molds.', 'photo' => 'c0900'],
            ['name' => 'Native baskets', 'type' => 'Handicraft', 'about' => 'Baskets handwoven from bamboo, rattan or buri, for storage or carrying goods.', 'photo' => 'c0486'],
        ],
    ],
    "Nimfa's Pili Candies|Sorsogon City" => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Assorted pili candies', 'type' => 'Local Food Product', 'about' => 'A mix of sweets made from pili nuts, Sorsogon\'s signature nut, in different flavors and coatings.', 'photo' => 'c0035'],
            ['name' => 'Sesame pili candy', 'type' => 'Local Food Product', 'about' => 'Pili candy coated with toasted sesame seeds.', 'photo' => 'c0030'],
            ['name' => 'Pili nuts', 'type' => 'Local Food Product', 'about' => 'Rich, buttery nuts from the pili tree, native to the Bicol region and enjoyed roasted or candied.', 'photo' => 'c0000'],
        ],
    ],
    'Seph Pasalubong Shop|Gubat' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Kutsinta', 'type' => 'Local Food Product', 'about' => 'A chewy steamed rice cake with a caramel color, served with grated coconut.', 'photo' => 'c0218'],
            ['name' => 'Pastillas', 'type' => 'Local Food Product', 'about' => 'Soft milk candy made from milk and sugar, wrapped individually.', 'photo' => 'c0924'],
            ['name' => 'Woven bags', 'type' => 'Handicraft', 'about' => 'Bags handwoven from native fibers such as buri, pandan or abaca.', 'photo' => 'c0558'],
        ],
    ],
    "5 J's Food Products|Sorsogon City" => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Banana chips', 'type' => 'Local Food Product', 'about' => 'Thin slices of saba banana fried until crisp and coated in sugar, a popular sweet snack.', 'photo' => 'c1023'],
            ['name' => 'Polvoron', 'type' => 'Local Food Product', 'about' => 'A crumbly shortbread of toasted flour, milk powder, sugar and butter, pressed into molds.', 'photo' => 'c0901'],
            ['name' => 'Kutsinta', 'type' => 'Local Food Product', 'about' => 'A chewy steamed rice cake with a caramel color, served with grated coconut.', 'photo' => 'c0216'],
        ],
    ],
    "Louella's Sweet Food Products|Bulan" => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Yema candy', 'type' => 'Local Food Product', 'about' => 'A sweet custard candy made from egg yolks, milk and sugar.', 'photo' => 'c0949'],
            ['name' => 'Pastillas', 'type' => 'Local Food Product', 'about' => 'Soft milk candy made from milk and sugar, wrapped individually.', 'photo' => 'c0932'],
            ['name' => 'Polvoron', 'type' => 'Local Food Product', 'about' => 'A crumbly shortbread of toasted flour, milk powder, sugar and butter, pressed into molds.', 'photo' => 'c0910'],
        ],
    ],
    'Shanlet Souvenir Shop|Donsol' => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Bamboo and capiz handicrafts', 'type' => 'Handicraft', 'about' => 'Decorative pieces made from bamboo and capiz shell, combining native wood work with translucent shell.', 'photo' => 'p0005'],
            ['name' => 'Native hats', 'type' => 'Souvenir', 'about' => 'Wide-brimmed hats woven from native materials such as buri, abaca or bamboo.', 'photo' => 'c0467'],
            ['name' => 'Woven souvenirs', 'type' => 'Souvenir', 'about' => 'Small woven items, from coasters to mini baskets, that make easy gifts.', 'photo' => 'c0665'],
        ],
    ],
    "Vicky's Pili & Food Products|Irosin" => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Roasted pili nuts', 'type' => 'Local Food Product', 'about' => 'Pili nuts roasted until golden and crunchy.', 'photo' => 'c0038'],
            ['name' => 'Pili candy', 'type' => 'Local Food Product', 'about' => 'A sweet treat made from pili nuts cooked with sugar.', 'photo' => 'c0040'],
            ['name' => 'Pili nuts', 'type' => 'Local Food Product', 'about' => 'Rich, buttery nuts from the pili tree, native to the Bicol region and enjoyed roasted or candied.', 'photo' => 'c0001'],
        ],
    ],
    'MFG Pasalubong Shop|Bulusan' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Puto', 'type' => 'Local Food Product', 'about' => 'A soft, fluffy steamed rice cake, sometimes topped with cheese.', 'photo' => 'c0271'],
            ['name' => 'Otap', 'type' => 'Local Food Product', 'about' => 'A flaky, crispy puff pastry sprinkled with sugar.', 'photo' => 'c1000'],
            ['name' => 'Woven hats', 'type' => 'Handicraft', 'about' => 'Hats woven by hand from native materials such as buri or bamboo.', 'photo' => 'c0710'],
        ],
    ],
    'Wenays Foodhouse|Sorsogon City' => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Biko', 'type' => 'Local Food Product', 'about' => 'Sticky rice cooked in coconut milk and brown sugar, usually topped with latik.', 'photo' => 'c0253'],
            ['name' => 'Suman', 'type' => 'Local Food Product', 'about' => 'Sticky rice cooked in coconut milk and wrapped in banana or palm leaves.', 'photo' => 'c0106'],
            ['name' => 'Palitaw', 'type' => 'Local Food Product', 'about' => 'A soft, chewy rice cake rolled in grated coconut, sugar and toasted sesame seeds.', 'photo' => 'c0296'],
        ],
    ],
    'Pasalubong Center|Bulan' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Biko', 'type' => 'Local Food Product', 'about' => 'Sticky rice cooked in coconut milk and brown sugar, usually topped with latik.', 'photo' => 'c0251'],
            ['name' => 'Banana chips', 'type' => 'Local Food Product', 'about' => 'Thin slices of saba banana fried until crisp and coated in sugar, a popular sweet snack.', 'photo' => 'c1022'],
            ['name' => 'Banig mats', 'type' => 'Handicraft', 'about' => 'Traditional handwoven sleeping mats made from dried pandan or buri leaves.', 'photo' => 'c0570'],
        ],
    ],
    'RNG Dried Fish|Bulan' => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Dried fish', 'type' => 'Local Food Product', 'about' => 'Locally caught fish that is salted and sun-dried, a staple Filipino breakfast with garlic rice.', 'photo' => 'c0376'],
        ],
    ],
    "Manoy's Foodhouse Pancit Malabon|Sorsogon City" => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Pancit Malabon', 'type' => 'Local Food Product', 'about' => 'Thick rice noodles in a rich orange sauce, topped with seafood, egg and crushed chicharon.', 'photo' => 'c0326'],
        ],
    ],
    'Jecx Confectionery Shop|Bulusan' => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Pastillas', 'type' => 'Local Food Product', 'about' => 'Soft milk candy made from milk and sugar, wrapped individually.', 'photo' => 'c0934'],
            ['name' => 'Durian candy', 'type' => 'Local Food Product', 'about' => 'Chewy milk candy flavored with durian fruit.', 'photo' => 'c0937'],
            ['name' => 'Barquillos', 'type' => 'Local Food Product', 'about' => 'Crisp, thin wafer rolls with a light, buttery sweetness.', 'photo' => 'c1146'],
        ],
    ],
    "Moana's Food Products|Bulan" => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Moron', 'type' => 'Local Food Product', 'about' => 'Sticky rice rolled with chocolate, steamed in banana leaves.', 'photo' => 'c1034'],
            ['name' => 'Binagol', 'type' => 'Local Food Product', 'about' => 'A sweet made from grated root crops and coconut milk, traditionally steamed and served in a coconut shell.', 'photo' => 'c0330'],
            ['name' => 'Puto', 'type' => 'Local Food Product', 'about' => 'A soft, fluffy steamed rice cake, sometimes topped with cheese.', 'photo' => 'c0181'],
        ],
    ],
    'Ethays Kakanin|Castilla' => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Suman', 'type' => 'Local Food Product', 'about' => 'Sticky rice cooked in coconut milk and wrapped in banana or palm leaves.', 'photo' => 'c0123'],
            ['name' => 'Palitaw', 'type' => 'Local Food Product', 'about' => 'A soft, chewy rice cake rolled in grated coconut, sugar and toasted sesame seeds.', 'photo' => 'c0290'],
            ['name' => 'Biko', 'type' => 'Local Food Product', 'about' => 'Sticky rice cooked in coconut milk and brown sugar, usually topped with latik.', 'photo' => 'c0268'],
        ],
    ],
    "Edgeline's Pasalubong Center|Sorsogon City" => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Sapin-sapin', 'type' => 'Local Food Product', 'about' => 'A colorful, layered sticky rice dessert made with coconut milk.', 'photo' => 'c0196'],
            ['name' => 'Yema candy', 'type' => 'Local Food Product', 'about' => 'A sweet custard candy made from egg yolks, milk and sugar.', 'photo' => 'c0947'],
            ['name' => 'Native jewelry', 'type' => 'Souvenir', 'about' => 'Necklaces, bracelets and earrings made from native materials such as shell, wood and seeds.', 'photo' => 'c0633'],
        ],
    ],
    'Sorsogon Universal Trading|Sorsogon City' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Suman', 'type' => 'Local Food Product', 'about' => 'Sticky rice cooked in coconut milk and wrapped in banana or palm leaves.', 'photo' => 'c0121'],
            ['name' => 'Barquillos', 'type' => 'Local Food Product', 'about' => 'Crisp, thin wafer rolls with a light, buttery sweetness.', 'photo' => 'c1143'],
            ['name' => 'Woven hats', 'type' => 'Handicraft', 'about' => 'Hats woven by hand from native materials such as buri or bamboo.', 'photo' => 'c0715'],
        ],
    ],
    'Kasanggayahan Trading|Sorsogon City' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Kutsinta', 'type' => 'Local Food Product', 'about' => 'A chewy steamed rice cake with a caramel color, served with grated coconut.', 'photo' => 'c0228'],
            ['name' => 'Ensaymada', 'type' => 'Local Food Product', 'about' => 'A soft, coiled sweet bread topped with butter, sugar and grated cheese.', 'photo' => 'c1085'],
            ['name' => 'Native accessories', 'type' => 'Souvenir', 'about' => 'Accessories made from native materials such as shell, wood and woven fiber.', 'photo' => 'c0457'],
        ],
    ],
    'Giftaholic|Bulan' => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Woven gift bags', 'type' => 'Souvenir', 'about' => 'Small handwoven bags, ideal for wrapping or carrying gifts.', 'photo' => 'c0557'],
            ['name' => 'Abaca necklaces', 'type' => 'Souvenir', 'about' => 'Necklaces handcrafted from abaca fiber, a light and durable native material from the Bicol region.', 'photo' => 'c0458'],
            ['name' => 'Woven bamboo decor', 'type' => 'Souvenir', 'about' => 'Decor pieces woven from bamboo, such as lamp covers and wall hangings.', 'photo' => 'c0479'],
        ],
    ],
    'Amazing G|Bulan' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Souvenir items', 'type' => 'Souvenir', 'about' => 'A range of souvenirs such as keychains, magnets and small decor.', 'photo' => 'c0510'],
            ['name' => 'Native hats', 'type' => 'Souvenir', 'about' => 'Wide-brimmed hats woven from native materials such as buri, abaca or bamboo.', 'photo' => 'c0703'],
            ['name' => 'Woven baskets', 'type' => 'Handicraft', 'about' => 'Baskets handwoven from native materials such as bamboo, rattan and buri.', 'photo' => 'c0487'],
        ],
    ],
    "Ka Ymon's Arts and Crafts|Bulan" => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Woven bamboo decor', 'type' => 'Handicraft', 'about' => 'Decor pieces woven from bamboo, such as lamp covers and wall hangings.', 'photo' => 'c0480'],
            ['name' => 'Handwoven textiles', 'type' => 'Handicraft', 'about' => 'Fabrics woven by hand on traditional looms, in native patterns and colors.', 'photo' => 'c0465'],
            ['name' => 'Rattan crafts', 'type' => 'Handicraft', 'about' => 'Furniture, baskets and decor woven from rattan.', 'photo' => 'c0493'],
        ],
    ],
    'A&F Gift Shop|Bulan' => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Souvenir gifts', 'type' => 'Souvenir', 'about' => 'Gift items and souvenirs to bring home for family and friends.', 'photo' => 'c0520'],
            ['name' => 'Woven bags', 'type' => 'Souvenir', 'about' => 'Bags handwoven from native fibers such as buri, pandan or abaca.', 'photo' => 'c0708'],
            ['name' => 'Woven bamboo decor', 'type' => 'Souvenir', 'about' => 'Decor pieces woven from bamboo, such as lamp covers and wall hangings.', 'photo' => 'c0481'],
        ],
    ],
    'Kimson Enterprise|Bulan' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Puto', 'type' => 'Local Food Product', 'about' => 'A soft, fluffy steamed rice cake, sometimes topped with cheese.', 'photo' => 'c0272'],
            ['name' => 'Polvoron', 'type' => 'Local Food Product', 'about' => 'A crumbly shortbread of toasted flour, milk powder, sugar and butter, pressed into molds.', 'photo' => 'c0909'],
            ['name' => 'Woven bags', 'type' => 'Handicraft', 'about' => 'Bags handwoven from native fibers such as buri, pandan or abaca.', 'photo' => 'c0722'],
        ],
    ],
    'Santa Magdalena Public Market Pasalubong Stalls|Santa Magdalena' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Fresh fish', 'type' => 'Local Food Product', 'about' => 'Fish from the day\'s catch, sold whole or cleaned for cooking.', 'photo' => 'c0754'],
            ['name' => 'Fresh produce', 'type' => 'Local Food Product', 'about' => 'Vegetables and fruits from local farms, sold fresh.', 'photo' => 'c0496'],
            ['name' => 'Dried fish', 'type' => 'Local Food Product', 'about' => 'Locally caught fish that is salted and sun-dried, a staple Filipino breakfast with garlic rice.', 'photo' => 'c0380'],
        ],
    ],
    "Lala's Collection & Souvenirs|Irosin" => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Handwoven dresses', 'type' => 'Clothing', 'about' => 'Dresses made from handwoven native fabrics with traditional patterns.', 'photo' => 'c0464'],
            ['name' => 'Barong and gowns', 'type' => 'Clothing', 'about' => 'Formal Filipino wear, including the barong and gowns made with native fabrics.', 'photo' => 'c0679'],
            ['name' => 'Souvenir accessories', 'type' => 'Souvenir', 'about' => 'Small accessories and wearable souvenirs.', 'photo' => 'c0460'],
        ],
    ],
    'Pili Crush Food Enterprise|Irosin' => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Pili nuts in jars', 'type' => 'Local Food Product', 'about' => 'Pili nuts packed in jars, convenient for gifts and long trips.', 'photo' => 'c0697'],
            ['name' => 'Pili candy', 'type' => 'Local Food Product', 'about' => 'A sweet treat made from pili nuts cooked with sugar.', 'photo' => 'c0032'],
            ['name' => 'Shelled pili nuts', 'type' => 'Local Food Product', 'about' => 'Pili nuts with the shell removed, ready to eat or use in cooking and baking.', 'photo' => 'c0039'],
        ],
    ],
    'A and C Drygoods & Souvenirs|Irosin' => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Woven banig mats', 'type' => 'Handicraft', 'about' => 'Traditional mats handwoven from dried pandan or buri leaves.', 'photo' => 'c0571'],
            ['name' => 'Woven fabric', 'type' => 'Handicraft', 'about' => 'Fabric woven by hand in native patterns.', 'photo' => 'c0705'],
            ['name' => 'Souvenir items', 'type' => 'Souvenir', 'about' => 'A range of souvenirs such as keychains, magnets and small decor.', 'photo' => 'c0711'],
        ],
    ],
    "Gubateno's Pasalubong|Gubat" => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Bibingka', 'type' => 'Local Food Product', 'about' => 'A soft rice cake baked in banana leaves, often topped with salted egg and grated coconut.', 'photo' => 'c0135'],
            ['name' => 'Hopia', 'type' => 'Local Food Product', 'about' => 'A flaky pastry filled with sweet mung bean, ube or other fillings.', 'photo' => 'c1133'],
            ['name' => 'Woven mats', 'type' => 'Handicraft', 'about' => 'Handwoven mats made from pandan, buri or other native leaves.', 'photo' => 'c0574'],
        ],
    ],
    "Gubat's Famous Timitim|Gubat" => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Timitim', 'type' => 'Local Food Product', 'about' => 'A Gubat kakanin made from grated cassava, brown sugar, eggs, milk and coconut milk.', 'photo' => 'c0107'],
        ],
    ],
    'KAwffee Bakes|Gubat' => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Ensaymada', 'type' => 'Local Food Product', 'about' => 'A soft, coiled sweet bread topped with butter, sugar and grated cheese.', 'photo' => 'c1104'],
            ['name' => 'Ube ensaymada', 'type' => 'Local Food Product', 'about' => 'Soft, coiled sweet bread filled or topped with ube halaya.', 'photo' => 'c1094'],
            ['name' => 'Otap', 'type' => 'Local Food Product', 'about' => 'A flaky, crispy puff pastry sprinkled with sugar.', 'photo' => 'c0999'],
        ],
    ],
    "Bicol's Oragon Souvenir Store|Prieto Diaz" => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Souvenir items', 'type' => 'Souvenir', 'about' => 'A range of souvenirs such as keychains, magnets and small decor.', 'photo' => 'c0511'],
            ['name' => 'Woven bamboo decor', 'type' => 'Souvenir', 'about' => 'Decor pieces woven from bamboo, such as lamp covers and wall hangings.', 'photo' => 'c0482'],
            ['name' => 'Bamboo and rattan crafts', 'type' => 'Handicraft', 'about' => 'Handmade items woven from bamboo and rattan, from small decor to everyday household pieces.', 'photo' => 'c0585'],
        ],
    ],
    'Ding & Adith Pasalubong Center|Bulusan' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Biko', 'type' => 'Local Food Product', 'about' => 'Sticky rice cooked in coconut milk and brown sugar, usually topped with latik.', 'photo' => 'c0252'],
            ['name' => 'Pastillas', 'type' => 'Local Food Product', 'about' => 'Soft milk candy made from milk and sugar, wrapped individually.', 'photo' => 'c0931'],
            ['name' => 'Bamboo and pili bracelets', 'type' => 'Souvenir', 'about' => 'Bracelets made from bamboo and pili, light native accessories that make easy gifts.', 'photo' => 'p0004'],
        ],
    ],
    'Pasalubong Center|Castilla' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Sapin-sapin', 'type' => 'Local Food Product', 'about' => 'A colorful, layered sticky rice dessert made with coconut milk.', 'photo' => 'c0205'],
            ['name' => 'Banana chips', 'type' => 'Local Food Product', 'about' => 'Thin slices of saba banana fried until crisp and coated in sugar, a popular sweet snack.', 'photo' => 'c1024'],
            ['name' => 'Handwoven textiles', 'type' => 'Handicraft', 'about' => 'Fabrics woven by hand on traditional looms, in native patterns and colors.', 'photo' => 'c0719'],
        ],
    ],
    'JC Pili Center|Casiguran' => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Pili nuts', 'type' => 'Local Food Product', 'about' => 'Rich, buttery nuts from the pili tree, native to the Bicol region and enjoyed roasted or candied.', 'photo' => 'c0005'],
            ['name' => 'Pili candy', 'type' => 'Local Food Product', 'about' => 'A sweet treat made from pili nuts cooked with sugar.', 'photo' => 'c0033'],
            ['name' => 'Pili butter', 'type' => 'Local Food Product', 'about' => 'A smooth spread made from ground pili nuts.', 'photo' => 'c0003'],
        ],
    ],
    'Casiguran Public Market|Casiguran' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Fresh fish', 'type' => 'Local Food Product', 'about' => 'Fish from the day\'s catch, sold whole or cleaned for cooking.', 'photo' => 'c0868'],
            ['name' => 'Fresh produce', 'type' => 'Local Food Product', 'about' => 'Vegetables and fruits from local farms, sold fresh.', 'photo' => 'c0497'],
            ['name' => 'Dried shrimp', 'type' => 'Local Food Product', 'about' => 'Small shrimp salted and dried, used to add flavor to soups, noodles and vegetable dishes.', 'photo' => 'c0382'],
        ],
    ],
    'Juban Public Market|Juban' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Fresh fish', 'type' => 'Local Food Product', 'about' => 'Fish from the day\'s catch, sold whole or cleaned for cooking.', 'photo' => 'c0869'],
            ['name' => 'Fresh produce', 'type' => 'Local Food Product', 'about' => 'Vegetables and fruits from local farms, sold fresh.', 'photo' => 'c0498'],
            ['name' => 'Dried fish', 'type' => 'Local Food Product', 'about' => 'Locally caught fish that is salted and sun-dried, a staple Filipino breakfast with garlic rice.', 'photo' => 'c0384'],
        ],
    ],
    'Snaazzy Pasalubong Shop|Juban' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Suman', 'type' => 'Local Food Product', 'about' => 'Sticky rice cooked in coconut milk and wrapped in banana or palm leaves.', 'photo' => 'c0117'],
            ['name' => 'Barquillos', 'type' => 'Local Food Product', 'about' => 'Crisp, thin wafer rolls with a light, buttery sweetness.', 'photo' => 'c1144'],
            ['name' => 'Handwoven clothing', 'type' => 'Clothing', 'about' => 'Clothing made from handwoven native fabrics.', 'photo' => 'c0463'],
        ],
    ],
    'Matnog Bagsakan Center|Matnog' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Fresh fish', 'type' => 'Local Food Product', 'about' => 'Fish from the day\'s catch, sold whole or cleaned for cooking.', 'photo' => 'c0870'],
            ['name' => 'Fresh produce', 'type' => 'Local Food Product', 'about' => 'Vegetables and fruits from local farms, sold fresh.', 'photo' => 'c0499'],
            ['name' => 'Dried fish', 'type' => 'Local Food Product', 'about' => 'Locally caught fish that is salted and sun-dried, a staple Filipino breakfast with garlic rice.', 'photo' => 'c0386'],
        ],
    ],
    'Curia|Barcelona' => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Kutsinta', 'type' => 'Local Food Product', 'about' => 'A chewy steamed rice cake with a caramel color, served with grated coconut.', 'photo' => 'c0240'],
            ['name' => 'Ensaymada', 'type' => 'Local Food Product', 'about' => 'A soft, coiled sweet bread topped with butter, sugar and grated cheese.', 'photo' => 'c1103'],
            ['name' => 'Woven hats', 'type' => 'Handicraft', 'about' => 'Hats woven by hand from native materials such as buri or bamboo.', 'photo' => 'c0468'],
        ],
    ],
    "Marlon's Pasalubong Shop|Bulusan" => [
        'basis' => 'typical',
        'products' => [
            ['name' => 'Puto', 'type' => 'Local Food Product', 'about' => 'A soft, fluffy steamed rice cake, sometimes topped with cheese.', 'photo' => 'c0183'],
            ['name' => 'Polvoron', 'type' => 'Local Food Product', 'about' => 'A crumbly shortbread of toasted flour, milk powder, sugar and butter, pressed into molds.', 'photo' => 'c0907'],
            ['name' => 'Native souvenirs', 'type' => 'Souvenir', 'about' => 'Souvenirs made from local materials, from small trinkets to woven decor.', 'photo' => 'c0675'],
        ],
    ],
    'Japan Home Centre|Sorsogon City' => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Home organizers', 'type' => 'Souvenir', 'about' => 'Storage boxes, racks and organizers for keeping the home tidy.', 'photo' => 'yen-9'],
            ['name' => 'Household containers', 'type' => 'Souvenir', 'about' => 'Containers and storage ware for food and household use.', 'photo' => 'yen-11'],
            ['name' => 'Household basics', 'type' => 'Souvenir', 'about' => 'Everyday household items for the kitchen, bathroom and home.', 'photo' => 'yen-5'],
        ],
    ],
    'Pilar Flower Shop|Pilar' => [
        'basis' => 'name',
        'products' => [
            ['name' => 'Flower bouquets', 'type' => 'Souvenir', 'about' => 'Arranged bouquets of fresh flowers for gifts, celebrations and special occasions.', 'photo' => 'c1287'],
            ['name' => 'Sunflower bouquets', 'type' => 'Souvenir', 'about' => 'Bouquets arranged with bright sunflowers.', 'photo' => 'c1279'],
            ['name' => 'Fresh cut flowers', 'type' => 'Souvenir', 'about' => 'Fresh flowers sold by the stem or bunch for arrangements and gifts.', 'photo' => 'c1291'],
        ],
    ],
];
