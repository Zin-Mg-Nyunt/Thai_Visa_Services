<?php

namespace Database\Factories;

use App\Enums\RemarkKind;
use App\Models\ClientService;
use App\Models\ClientServiceRemark;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientServiceRemark>
 */
class ClientServiceRemarkFactory extends Factory
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
            'kind' => RemarkKind::Note,
            'body' => fake()->sentence(),
            'details' => null,
            'created_by' => null,
        ];
    }
}
