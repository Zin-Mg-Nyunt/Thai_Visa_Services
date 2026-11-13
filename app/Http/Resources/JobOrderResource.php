<?php

namespace App\Http\Resources;

use App\Models\JobOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobOrder
 */
class JobOrderResource extends JsonResource
{
    /**
     * The fields of an order as the admin sees them. The worker counts (active, and cancelled apart) and exceeds_manpower
     * are only there when the workers were counted. Deleted people are not counted. More active workers than the company
     * asked for is a warning on the page, never a block.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_name' => $this->company_name,
            'manpower' => $this->manpower,
            'male_count' => $this->male_count,
            'female_count' => $this->female_count,
            'interview_date' => $this->interview_date?->toDateString(),
            'start_date' => $this->start_date?->toDateString(),
            'status' => $this->status->value,
            'closed_at' => $this->closed_at?->toIso8601String(),
            'notes' => $this->notes,
            'active_workers_count' => $this->whenHas('active_workers_count'),
            'cancelled_workers_count' => $this->whenHas('cancelled_workers_count'),
            'exceeds_manpower' => $this->whenHas('active_workers_count', fn (int $count) => $count > $this->manpower),
            'can_edit' => $request->user()?->can('update', $this->resource) ?? false,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
