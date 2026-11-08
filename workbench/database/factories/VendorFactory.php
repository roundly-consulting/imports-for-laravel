<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Vendor;

/**
 * @extends Factory<Vendor>
 */
final class VendorFactory extends Factory
{
    /** @var class-string<Vendor> */
    protected $model = Vendor::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'team_id' => TeamFactory::new(),
            'name' => fake()->unique()->company(),
            'vat_id' => 'SK'.fake()->unique()->numerify('##########'),
        ];
    }
}
