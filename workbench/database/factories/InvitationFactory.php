<?php

declare(strict_types=1);

namespace Workbench\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Workbench\App\Models\Invitation;

/**
 * @extends Factory<Invitation>
 */
final class InvitationFactory extends Factory
{
    /** @var class-string<Invitation> */
    protected $model = Invitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'team_id' => TeamFactory::new(),
            'email' => fake()->unique()->safeEmail(),
            'role' => 'member',
            'status' => 'pending',
        ];
    }
}
