<?php

namespace App\Http\Resources;

use App\Models\ClientService;
use App\Models\ClientServiceRemark;
use App\Models\Document;
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
     * are loaded for the list on the client page. Remarks are sent newest first, with who wrote them.
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
            'cancel_refund_amount' => $this->cancel_refund_amount,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'is_job_search' => $this->whenLoaded('service', fn () => $this->service->isJobSearch()),
            'accepts_documents' => $this->whenLoaded('service', fn () => $this->service->has_documents && ! $this->service->isJobSearch()),
            $this->mergeWhen($this->relationLoaded('documents'), fn () => [
                'documents' => $this->documents->map(fn (Document $document) => [
                    'id' => $document->id,
                    'title' => $document->title,
                    'mime' => $document->mime,
                    'size' => $document->size,
                    'client_can_download' => $document->client_can_download,
                    // Unlocked for the client: released by the admin, or unlocked by payment. The service is not asked again per file.
                    'unlocked' => $document->client_can_download || $this->unlocksDocuments(),
                    'created_at' => $document->created_at?->toIso8601String(),
                ])->values()->all(),
            ]),
            'remarks' => $this->whenLoaded('remarks', fn () => $this->remarks->map(fn (ClientServiceRemark $remark) => [
                'id' => $remark->id,
                'kind' => $remark->kind->value,
                'body' => $remark->body,
                'details' => $remark->details,
                'author' => $remark->creator?->name,
                'created_at' => $remark->created_at?->toIso8601String(),
            ])->values()->all()),
            $this->mergeWhen($this->relationLoaded('payments'), fn () => [
                'received' => $this->received(),
                'remaining' => $this->remaining(),
                'kept' => $this->kept(),
                'payment_status' => $this->paymentStatus()->value,
                'can_record_payment' => $this->canTakePayment(),
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
