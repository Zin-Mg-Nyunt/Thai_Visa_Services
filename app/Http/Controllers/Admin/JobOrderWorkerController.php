<?php

namespace App\Http\Controllers\Admin;

use App\Actions\JobSearch\AddWorker;
use App\Concerns\HandlesMoney;
use App\Http\Controllers\Controller;
use App\Http\Requests\LookupJobOrderWorkerRequest;
use App\Http\Requests\StoreJobOrderWorkerRequest;
use App\Models\Client;
use App\Models\JobOrder;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;

class JobOrderWorkerController extends Controller
{
    use HandlesMoney;

    /**
     * Look a phone number up before adding a worker: the matching active person, if there is one, and whether they are
     * already on this order. Soft-deleted people are not found (their phone number can be used again). It is a POST so the
     * phone number stays out of the address, the browser history and the access logs; it changes nothing.
     */
    public function lookup(LookupJobOrderWorkerRequest $request, JobOrder $jobOrder): JsonResponse
    {
        $phone = $request->validated('phone');
        $client = Client::query()->where('phone', $phone)->first();

        return response()->json([
            'phone' => $phone,
            'client' => $client === null ? null : [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'birthday' => $client->birthday->toDateString(),
                'gender' => $client->gender->value,
            ],
            'on_this_order' => $client !== null && $jobOrder->workers()->where('client_id', $client->id)->exists(),
        ]);
    }

    /**
     * Add a worker: an existing person is reused, a new one is created, and the worker gets a Job Search record with
     * the fee. More workers than the company asked for is allowed; it is only a warning on the pages.
     */
    public function store(StoreJobOrderWorkerRequest $request, JobOrder $jobOrder, AddWorker $addWorker): RedirectResponse
    {
        $data = $request->validated();

        try {
            $addWorker->handle(
                $jobOrder,
                isset($data['client_id']) ? (int) $data['client_id'] : null,
                Arr::only($data, ['name', 'phone', 'birthday', 'gender']),
                $this->money($data['fee']),
            );
        } catch (DomainException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __($exception->getMessage())]);

            // A closed order has no edit page, so the admin goes to the list.
            return $jobOrder->fresh()?->isClosed()
                ? to_route('admin.job-search.index')
                : back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Worker added.')]);

        return back();
    }
}
