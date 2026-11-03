<?php

namespace Database\Factories;

use App\Enums\ServiceAvailability;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent_id' => null,
            'title' => fake()->unique()->words(2, true),
            'title_my' => null,
            'public_description' => fake()->sentence(),
            'public_description_my' => null,
            'show_on_website' => true,
            'status' => ServiceAvailability::Active,
            'default_fee' => fake()->numberBetween(1000, 10000),
            'has_documents' => false,
        ];
    }

    /**
     * A sub-service of the given service.
     */
    public function subServiceOf(Service $parent): static
    {
        return $this->state(fn () => [
            'parent_id' => $parent->id,
            'show_on_website' => false,
        ]);
    }
}
