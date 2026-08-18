<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ModularSubscriptionSeeder extends Seeder
{
    /**
     * php artisan db:seed --class=ModularSubscriptionSeeder
     * (ou l'appeler depuis DatabaseSeeder::run())
     *
     * L'ordre est important : modules → tiers → prix.
     */
    public function run(): void
    {
        $this->call([
            ModuleSeeder::class,
            ModuleTierSeeder::class,
            ModulePriceSeeder::class,
        ]);
    }
}
