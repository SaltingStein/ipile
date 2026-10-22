<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds default application data for local development and smoke tests.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(LegalContentSeeder::class);

        User::factory()->create([
            'nickname' => 'Test User',
            'email' => 'test@example.com',
            'is_guest' => false,
            'status' => 'active',
        ]);
    }
}
