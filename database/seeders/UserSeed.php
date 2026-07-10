<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;
use Illuminate\Support\Str;

class UserSeed extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $faker = Faker::create();

        // Récupérer tous les sites
        $sites = Site::all();

        // Récupérer tous les rôles
        $roles = Role::all();

        // Créer 50 utilisateurs fake
        for ($i = 0; $i < 50; $i++) {
            $role = $roles->random();

            $user = User::create([
                'id' => (string) Str::uuid(),
                'firstname' => $faker->firstName,
                'lastname' => $faker->lastName,
                'email' => $faker->unique()->safeEmail,
                'password' => bcrypt('password'), // mot de passe par défaut
                'is_verified' => $faker->boolean(80), // 80% chance d'être vérifié
                'role_id' => $role->id,
            ]);

            // Lier cet utilisateur à 1 à 3 sites aléatoires
            $assignedSites = $sites->random(rand(1, 3));

            foreach ($assignedSites as $site) {
                DB::table('site_user')->insert([
                    'user_id' => $user->id,
                    'site_id' => $site->id,
                    'first_seen_at' => $faker->dateTimeBetween('-60 days', '-30 days'),
                    'last_seen_at' => $faker->dateTimeBetween('-29 days', 'now'),
                ]);
            }
        }
    }
}
