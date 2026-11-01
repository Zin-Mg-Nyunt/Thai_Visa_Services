<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientPinAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientPinAttempt>
 */
class ClientPinAttemptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'caused_lock' => false,
        ];
    }
}
