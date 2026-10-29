<?php

namespace Database\Factories;

use App\Enums\Gender;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+66'.fake()->unique()->numerify('8########'),
            'birthday' => fake()->date(),
            'gender' => fake()->randomElement(Gender::cases()),
            'remarks' => null,
        ];
    }
}
