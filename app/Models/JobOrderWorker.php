<?php

namespace App\Models;

use App\Enums\ClientServiceStatus;
use App\Enums\WorkerCaseStatus;
use Database\Factories\JobOrderWorkerFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person on one job order. The person is the shared client record; the worker's fee, payments and documents live
 * on the Job Search client service this row points to, which must belong to the same person.
 *
 * @property int $id
 * @property int $job_order_id
 * @property int $client_id
 * @property int $client_service_id
 * @property WorkerCaseStatus $case_status
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'job_order_id',
    'client_id',
    'client_service_id',
    'case_status',
    'notes',
])]
class JobOrderWorker extends Model
{
    /** @use HasFactory<JobOrderWorkerFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // The database cannot tell that the record belongs to the same person and to Job Search, so the model checks. Only
        // when the person or the record changes (always on a new row): a case status or notes change needs no query.
        static::saving(function (JobOrderWorker $worker): void {
            if (! $worker->isDirty(['client_id', 'client_service_id'])) {
                return;
            }

            $record = ClientService::query()->with('service')->find($worker->client_service_id);

            if ($record === null || $record->client_id !== $worker->client_id) {
                throw new DomainException('A worker\'s service record must belong to the same person.');
            }

            if (! $record->service->isJobSearch()) {
                throw new DomainException('A worker\'s service record must be a Job Search record.');
            }
        });
    }

    /**
     * Workers whose person is not deleted. A deleted person leaves every operational count and list; the money of their
     * Job Search record is never hidden, it stays in the financial totals.
     *
     * @param  Builder<JobOrderWorker>  $query
     */
    #[Scope]
    protected function operational(Builder $query): void
    {
        $query->whereHas('client', fn ($client) => $client->withoutTrashed());
    }

    /**
     * Applications that are still open: Applied or Interview.
     *
     * @param  Builder<JobOrderWorker>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('case_status', [WorkerCaseStatus::Applied->value, WorkerCaseStatus::Interview->value]);
    }

    /**
     * The Applied Workers of an order (business rules, 11.1): applications that are Applied or Interview, whose person is not
     * deleted and whose Job Search service is not whole-service Cancelled. Hired, Rejected and Cancelled rows are not counted.
     *
     * @param  Builder<JobOrderWorker>  $query
     */
    #[Scope]
    protected function applied(Builder $query): void
    {
        $query->operational()
            ->open()
            ->whereHas('clientService', fn ($service) => $service->where('status', '!=', ClientServiceStatus::Cancel->value));
    }

    /**
     * The Hired Workers of an order (business rules, 11.1): applications whose CURRENT status is Hired and whose person is not
     * deleted. A Hired application that was cancelled (Hired -> Cancelled) is no longer counted, also on a closed order; its row
     * stays as history. This is the capacity count the manpower warning compares with.
     *
     * @param  Builder<JobOrderWorker>  $query
     */
    #[Scope]
    protected function hired(Builder $query): void
    {
        $query->operational()->where('case_status', WorkerCaseStatus::Hired->value);
    }

    /**
     * The historical cancelled applications of an order: rows whose status is Cancelled, whoever's person is not deleted. They
     * stay in the database and are shown apart from the operational counts, which they never inflate.
     *
     * @param  Builder<JobOrderWorker>  $query
     */
    #[Scope]
    protected function cancelledApplications(Builder $query): void
    {
        $query->operational()->where('case_status', WorkerCaseStatus::Cancelled->value);
    }

    /**
     * @return BelongsTo<JobOrder, $this>
     */
    public function jobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return BelongsTo<ClientService, $this>
     */
    public function clientService(): BelongsTo
    {
        return $this->belongsTo(ClientService::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'case_status' => WorkerCaseStatus::class,
        ];
    }
}
