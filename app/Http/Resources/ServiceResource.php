<?php

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    /**
     * The system key is left out; the page only needs to know whether this is the Job Search service.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'parent' => $this->whenLoaded('parent', fn () => $this->parent === null ? null : [
                'id' => $this->parent->id,
                'title' => $this->parent->title,
                'title_my' => $this->parent->title_my,
            ]),
            'title' => $this->title,
            'title_my' => $this->title_my,
            'public_description' => $this->public_description,
            'public_description_my' => $this->public_description_my,
            'show_on_website' => $this->show_on_website,
            'status' => $this->status->value,
            'default_fee' => $this->default_fee,
            'has_documents' => $this->has_documents,
            'is_job_search' => $this->isJobSearch(),
            'sub_services_count' => $this->whenCounted('children'),
        ];
    }
}
