<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ShopProductDataTest extends TestCase
{
    public function test_no_product_photo_is_used_twice_and_no_shop_is_empty(): void
    {
        $shops = require __DIR__.'/../../database/seeders/data/shop_products.php';

        $photos = [];
        foreach ($shops as $list) {
            $this->assertContains($list['basis'], ['stated', 'name', 'typical']);
            $this->assertNotEmpty($list['products']);
            array_push($photos, ...array_column($list['products'], 'photo'));
        }

        $this->assertSame(count($photos), count(array_unique($photos)));
    }
}
