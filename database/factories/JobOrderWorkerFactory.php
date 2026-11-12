<?php

namespace Database\Factories;

use App\Enums\WorkerCaseStatus;
use App\Models\Client;
use App\Models\ClientService;
use App\Models\JobOrder;
use App\Models\JobOrderWorker;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Makes a person with a Job Search record, so the worker's service record belongs to the same person. Both are made only
 * when the caller does not give them: a given client gets a new Job Search record of their own, and given ids create nothing.
 *
 * @extends Factory<JobOrderWorker>
 */
class JobOrderWorkerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'job_order_id' => JobOrder::factory(),
            'client_id' => Client::factory(),
            'client_service_id' => fn (array $attributes) => ClientService::factory()
                ->forService($this->jobSearchService())
                ->create(['client_id' => $attributes['client_id']])->id,
            'case_status' => WorkerCaseStatus::Applied,
            'notes' => null,
        ];
    }

    /**
     * The seeded Job Search service, or a new one when the catalogue is empty (as in tests).
     */
    private function jobSearchService(): Service
    {
        return Service::query()->where('key', Service::JOB_SEARCH_KEY)->first()
            ?? Service::factory()->jobSearch()->create();
    }
}
