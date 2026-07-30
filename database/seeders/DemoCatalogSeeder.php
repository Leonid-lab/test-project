<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Manufacturer;
use App\Models\Price;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

final class DemoCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $today = Date::today()->toImmutable();
        $north = Manufacturer::query()->firstOrCreate([
            'manufacturer_name' => 'Северная мануфактура',
        ]);
        $south = Manufacturer::query()->firstOrCreate([
            'manufacturer_name' => 'Южная фабрика',
        ]);

        $tea = Product::query()->firstOrCreate([
            'product_name' => 'Чай листовой',
            'category_id' => 10,
            'manufacturer_id' => $north->manufacturer_id,
        ]);
        $coffee = Product::query()->firstOrCreate([
            'product_name' => 'Кофе молотый',
            'category_id' => 10,
            'manufacturer_id' => $south->manufacturer_id,
        ]);
        $cocoa = Product::query()->firstOrCreate([
            'product_name' => 'Какао',
            'category_id' => 10,
            'manufacturer_id' => $south->manufacturer_id,
        ]);
        Product::query()->firstOrCreate([
            'product_name' => 'Напиток без актуальной цены',
            'category_id' => 10,
            'manufacturer_id' => $north->manufacturer_id,
        ]);
        $cookies = Product::query()->firstOrCreate([
            'product_name' => 'Печенье',
            'category_id' => 20,
            'manufacturer_id' => $north->manufacturer_id,
        ]);

        $prices = [
            [$tea, '120.5000', $today->subDays(6)],
            [$tea, '99.9000', $today->subDays(5)],
            [$tea, '99.9000', $today->subDays(2)],
            [$tea, '145.0000', $today],
            [$tea, '80.0000', $today->subDays(7)],
            [$coffee, '310.0000', $today->subDay()],
            [$cocoa, '210.5550', $today->subDays(4)],
            [$cocoa, '240.0000', $today->subDays(3)],
            [$cocoa, '240.0000', $today],
            [$cookies, '75.0000', $today],
        ];

        foreach ($prices as [$product, $value, $date]) {
            Price::query()->firstOrCreate([
                'product_id' => $product->product_id,
                'price' => $value,
                'price_date' => $date->toDateString(),
            ]);
        }
    }
}
