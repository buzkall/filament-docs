<?php

namespace Arzcode\FilamentDocs\Tests\Fixtures;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name'     => fake()->name(),
            'email'    => fake()->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'role'     => 'admin',
        ];
    }

    public function editor(): static
    {
        return $this->state(fn(): array => ['role' => 'editor']);
    }

    public function reader(): static
    {
        return $this->state(fn(): array => ['role' => 'reader']);
    }
}
