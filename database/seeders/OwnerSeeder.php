<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class OwnerSeeder extends Seeder
{
    /**
     * The one user on the laptop. Safe to run again: it never adds a second owner.
     */
    public function run(): void
    {
        User::localOwner();
    }
}
