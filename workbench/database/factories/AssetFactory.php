<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Asset;

/**
 * @extends Factory<Asset>
 */
final class AssetFactory extends Factory
{
    /** @var class-string<Asset> */
    protected $model = Asset::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'team_id' => TeamFactory::new(),
            'asset_tag' => 'AST-'.fake()->unique()->numerify('#####'),
            'serial_number' => 'SN-'.fake()->unique()->numerify('######'),
            'name' => fake()->words(2, true),
            'status' => 'active',
        ];
    }
}
