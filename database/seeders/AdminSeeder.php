<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Admin;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        Admin::create([
            'name' => 'Super Admin',
            'type' => 'superadmin',
            'email' => 'admin@mail.com',
            'password' => Hash::make('12345678'), // use a strong password in production
        ]);
    }
}
