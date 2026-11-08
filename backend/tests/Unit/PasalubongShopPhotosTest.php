<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PasalubongShopPhotosTest extends TestCase
{
    public function test_every_shop_has_five_photos_and_no_photo_is_shared(): void
    {
        $dir = __DIR__.'/../../database/seeders/data';
        $shops = require "{$dir}/pasalubong_shops.php";
        $photos = require "{$dir}/pasalubong_shop_photos.php";

        $keys = array_map(fn ($shop) => "{$shop['name']}|{$shop['town']}", $shops);
        sort($keys);
        $mapped = array_keys($photos);
        sort($mapped);
        $this->assertSame($keys, $mapped);

        foreach ($photos as $names) {
            $this->assertCount(5, $names);
        }

        $all = array_merge(...array_values($photos));
        $this->assertSame(count($all), count(array_unique($all)));
    }
}
