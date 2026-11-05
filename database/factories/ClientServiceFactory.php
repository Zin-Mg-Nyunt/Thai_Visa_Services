<?php

namespace Database\Factories;

use App\Enums\ClientServiceStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientService>
 */
class ClientServiceFactory extends Factory
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
            'service_id' => Service::factory(),
            'sub_service_id' => null,
            'status' => ClientServiceStatus::InProgress,
            'start_date' => fake()->date(),
            'end_date' => null,
            'fee' => fake()->numberBetween(1000, 10000),
            'refund_amount' => 0,
            'cancelled_at' => null,
            'remarks' => null,
        ];
    }

    /**
     * For the given main service, optionally with one of its sub-services.
     */
    public function forService(Service $service, ?Service $subService = null): static
    {
        return $this->state(fn () => [
            'service_id' => $service->id,
            'sub_service_id' => $subService?->id,
        ]);
    }

    public function complete(): static
    {
        return $this->state(fn () => ['status' => ClientServiceStatus::Complete]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => ClientServiceStatus::Cancel,
            'cancelled_at' => now(),
        ]);
    }
}
