<?php

namespace App\Http\Resources;

use App\Models\JobOrderWorker;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobOrderWorker
 */
class JobOrderWorkerResource extends JsonResource
{
    /**
     * A worker as listed on a job order: the person, the case status and the money of the worker's Job Search record.
     * The record and its payments must be loaded. Money is a decimal string, never a float.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'name' => $this->client->name,
            'phone' => $this->client->phone,
            'gender' => $this->client->gender->value,
            'case_status' => $this->case_status->value,
            'service_status' => $this->clientService->status->value,
            'fee' => $this->clientService->fee,
            'received' => $this->clientService->received(),
            'remaining' => $this->clientService->remaining(),
            'payment_status' => $this->clientService->paymentStatus()->value,
        ];
    }
}
