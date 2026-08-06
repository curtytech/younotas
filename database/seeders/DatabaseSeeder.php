<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'email_verified_at' => now(),
                'password' => 'password',
                'role' => 'enterprise',
            ],
        );

        $this->call(NfeTestSeeder::class);
        $this->call(FocusTestCompanySeeder::class);
        $this->call(AllTablesSeeder::class);
        $this->call(FiscalDocumentSeeder::class);
    }
}
