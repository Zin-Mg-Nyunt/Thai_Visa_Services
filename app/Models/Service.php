<?php

namespace App\Models;

use App\Enums\ServiceAvailability;
use Database\Factories\ServiceFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A service the agency offers (Passport, Work Permit, ...). A main service can have sub-services
 * through parent_id, for example Bath and MOU under Passport. There are two levels at most, and the
 * Job Search service never has sub-services. Sub-services only classify a client's service record:
 * fees, payments and profit always belong to the client-service record and roll up to the main service.
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string|null $key
 * @property string|null $title
 * @property string|null $title_my
 * @property string|null $public_description
 * @property string|null $public_description_my
 * @property bool $show_on_website
 * @property ServiceAvailability $status
 * @property string|null $default_fee
 * @property bool $has_documents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'parent_id',
    'key',
    'title',
    'title_my',
    'public_description',
    'public_description_my',
    'show_on_website',
    'status',
    'default_fee',
    'has_documents',
])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory, SoftDeletes;

    /**
     * The fixed key of the Job Search service, so code finds it even if an admin renames it.
     * Only linking a worker to a job order creates its client-service records.
     */
    public const JOB_SEARCH_KEY = 'job_search';

    public function isJobSearch(): bool
    {
        return $this->key === self::JOB_SEARCH_KEY;
    }

    public function isSubService(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * Keep the two-level rule whatever code saves a service: the parent must be a main service,
     * a service with sub-services cannot become one, and Job Search stays a main service with none.
     */
    protected static function booted(): void
    {
        static::saving(function (Service $service): void {
            if (! $service->isSubService()) {
                return;
            }

            if ($service->exists && ! $service->isDirty(['parent_id', 'key'])) {
                return;
            }

            $parent = self::withTrashed()->find($service->parent_id);

            if ($parent === null) {
                throw new DomainException('The parent service does not exist.');
            }

            if ($parent->is($service) || $parent->isSubService()) {
                throw new DomainException('A sub-service cannot have sub-services: services have two levels at most.');
            }

            if ($parent->isJobSearch()) {
                throw new DomainException('The Job Search service cannot have sub-services.');
            }

            if ($service->key !== null) {
                throw new DomainException('A sub-service cannot have a system key.');
            }

            if ($service->exists && $service->children()->withTrashed()->exists()) {
                throw new DomainException('A service that has sub-services cannot become a sub-service.');
            }
        });
    }

    /**
     * Main services only: the ones that are listed, offered to clients and shown on the landing page.
     *
     * @param  Builder<Service>  $query
     */
    #[Scope]
    protected function main(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ServiceAvailability::class,
            'default_fee' => 'decimal:2',
            'show_on_website' => 'boolean',
            'has_documents' => 'boolean',
        ];
    }
}
