<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\Database\Factories\TeamFactory;
use Workbench\Database\Factories\UserFactory;
use Workbench\Database\Factories\VendorFactory;

/**
 * Two teams, each with an admin (may import) and a member (may not), and a few vendors to
 * reference: users 1–2 are team 1, users 3–4 team 2.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Acme', 'Globex'] as $index => $name) {
            $team = TeamFactory::new()->create(['name' => $name]);
            $slug = strtolower($name);

            UserFactory::new()->create(['team_id' => $team->id, 'name' => $name.' Admin', 'email' => "admin@{$slug}.test", 'is_admin' => true]);
            UserFactory::new()->create(['team_id' => $team->id, 'name' => $name.' Member', 'email' => "member@{$slug}.test", 'is_admin' => false]);

            foreach (['Lenovo' => 'SK100', 'Dell' => 'SK200', 'Apple' => 'SK300'] as $vendor => $vat) {
                VendorFactory::new()->create(['team_id' => $team->id, 'name' => $vendor, 'vat_id' => $vat.$index]);
            }
        }
    }
}
