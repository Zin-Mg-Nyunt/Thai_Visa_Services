<?php

namespace Database\Factories;

use App\Models\ClientService;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
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
            'title' => fake()->words(3, true),
            'storage_key' => 'documents/'.Str::uuid().'.pdf',
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(10_000, 500_000),
            'client_can_download' => false,
            'uploaded_by' => null,
        ];
    }

    /**
     * Released by the admin, whatever the payment status.
     */
    public function released(): static
    {
        return $this->state(fn () => ['client_can_download' => true]);
    }
}
