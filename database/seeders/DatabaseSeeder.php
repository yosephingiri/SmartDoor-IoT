<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /** Membuat akun pengelola. Ubah lewat .env: SMARTDOOR_ADMIN_EMAIL / SMARTDOOR_ADMIN_PASSWORD */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => env('SMARTDOOR_ADMIN_EMAIL', 'admin@smartdoor.test')],
            ['name' => 'Admin', 'password' => Hash::make(env('SMARTDOOR_ADMIN_PASSWORD', 'admin123'))]
        );
    }
}
