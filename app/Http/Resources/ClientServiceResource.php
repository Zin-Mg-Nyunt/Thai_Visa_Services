<?php

namespace App\Http\Resources;

use App\Models\ClientService;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClientService
 */
class ClientServiceResource extends JsonResource
{
    /**
     * Money goes to the page as decimal strings. Received, remaining and payment status are worked out from the payments, which
     * are loaded for the list on the client page.
     *
     * @return array<int|string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'service_id' => $this->service_id,
            'sub_service_id' => $this->sub_service_id,
            'service' => $this->whenLoaded('service', fn () => $this->titles($this->service)),
            'sub_service' => $this->whenLoaded('subService', fn () => $this->subService === null ? null : $this->titles($this->subService)),
            'status' => $this->status->value,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'fee' => $this->fee,
            'refund_amount' => $this->refund_amount,
            'remarks' => $this->remarks,
            $this->mergeWhen($this->relationLoaded('payments'), fn () => [
                'received' => $this->received(),
                'remaining' => $this->remaining(),
                'payment_status' => $this->paymentStatus()->value,
                'payments' => $this->payments->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'paid_at' => $payment->paid_at->toDateString(),
                    'method' => $payment->method->value,
                    'note' => $payment->note,
                ])->values()->all(),
            ]),
        ];
    }

    /**
     * @return array{id: int, title: string|null, title_my: string|null}
     */
    private function titles(Service $service): array
    {
        return ['id' => $service->id, 'title' => $service->title, 'title_my' => $service->title_my];
    }
}
