<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\ClientService;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'client_service_id' => ClientService::factory(),
            'amount' => fake()->numberBetween(100, 1000),
            'paid_at' => fake()->date(),
            'method' => PaymentMethod::Cash,
            'note' => null,
            'created_by' => null,
        ];
    }
}
