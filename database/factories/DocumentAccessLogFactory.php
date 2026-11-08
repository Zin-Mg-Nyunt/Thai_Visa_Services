<?php

namespace Database\Factories;

use App\Enums\DocumentAccessOutcome;
use App\Enums\DocumentActor;
use App\Enums\DocumentDenyReason;
use App\Models\Document;
use App\Models\DocumentAccessLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentAccessLog>
 */
class DocumentAccessLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'client_id' => null,
            'document_title' => fake()->words(3, true),
            'outcome' => DocumentAccessOutcome::Allowed,
            'reason' => null,
            'actor_type' => DocumentActor::Client,
            'actor_id' => null,
            'ip' => fake()->ipv4(),
        ];
    }

    /**
     * A denied attempt with its reason.
     */
    public function denied(DocumentDenyReason $reason): static
    {
        return $this->state(fn () => ['outcome' => DocumentAccessOutcome::Denied, 'reason' => $reason]);
    }
}
