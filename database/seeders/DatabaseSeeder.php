<?php

namespace Database\Seeders;

use App\Models\AIRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        /*User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);*/

        //Role::create(['id' => (string) Str::uuid(), 'name' => 'admin']);
        //Role::create(['id' => (string) Str::uuid(), 'name' => 'visitor']);
        $this->call([
            //UserSeed::class,
            //AIRoleSeeder::class,
            //ChatbotCtaSeeder::class,
            //TypeSiteSeeder::class,
            McpConnectorSeeder::class,
            McpCapabilityPlaybookSeeder::class,
            McpMicrosoft365Seeder::class,
        ]);
    }
}
