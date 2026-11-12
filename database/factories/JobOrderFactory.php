<?php

namespace Database\Factories;

use App\Enums\JobOrderStatus;
use App\Models\JobOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobOrder>
 */
class JobOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $male = fake()->numberBetween(0, 10);
        $female = fake()->numberBetween(0, 10);

        return [
            'company_name' => fake()->company(),
            'manpower' => $male + $female,
            'male_count' => $male,
            'female_count' => $female,
            'interview_date' => fake()->dateTimeBetween('now', '+1 month'),
            'start_date' => fake()->dateTimeBetween('+1 month', '+3 months'),
            'status' => JobOrderStatus::Open,
            'closed_at' => null,
            'notes' => null,
        ];
    }

    public function done(): static
    {
        return $this->state(fn () => ['status' => JobOrderStatus::Done, 'closed_at' => now()]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => JobOrderStatus::Cancelled, 'closed_at' => now()]);
    }
}
