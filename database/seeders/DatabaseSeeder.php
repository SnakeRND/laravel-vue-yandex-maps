<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => env('SEED_USER_EMAIL', 'demo@example.com')],
            [
                'name' => env('SEED_USER_NAME', 'Demo User'),
                'password' => Hash::make(env('SEED_USER_PASSWORD', 'password')),
            ]
        );
    }
}
