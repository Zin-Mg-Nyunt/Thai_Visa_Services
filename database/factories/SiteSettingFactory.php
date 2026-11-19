<?php

namespace Database\Factories;

use App\Models\SiteSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteSetting>
 */
class SiteSettingFactory extends Factory
{
    /**
     * Define the model's default state: the one row, with a name and a hero heading, nothing else, not published.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => SiteSetting::ID,
            'agency_name' => fake()->company(),
            'hero_heading' => fake()->sentence(4),
            'is_published' => false,
        ];
    }

    /**
     * Published, with the logo and the favicon the website needs.
     */
    public function published(): static
    {
        return $this->state(fn () => [
            'logo_key' => 'site/'.fake()->uuid().'.png',
            'favicon_key' => 'site/'.fake()->uuid().'.png',
            'is_published' => true,
        ]);
    }
}
