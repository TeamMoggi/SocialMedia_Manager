<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Database\Seeder;

class BrandSeeder extends Seeder
{
    /**
     * The first brand, Moggies International, owned by the laptop's owner. Safe to run again.
     */
    public function run(): void
    {
        $brand = Brand::firstOrCreate(
            ['slug' => 'moggies-international'],
            ['name' => 'Moggies International'],
        );
        $brand->users()->syncWithoutDetaching([User::localOwner()->id]);
    }
}
