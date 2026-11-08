<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Dashboard\BusinessReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\DashboardRangeRequest;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The Business dashboard for the chosen date range (this month by default). A custom range that is not valid stays
     * chosen with what was typed and its errors, and the page shows this month until it is fixed.
     */
    public function index(DashboardRangeRequest $request, BusinessReport $report): Response
    {
        $range = $request->dates();

        return Inertia::render('Dashboard', [
            'range' => [
                'key' => $range['key'],
                'from' => $range['from']->toDateString(),
                'to' => $range['to']->toDateString(),
            ],
            'customInput' => $range['input'],
            'rangeErrors' => (object) $range['errors'],
            'report' => $report->build($range['from'], $range['to']),
        ]);
    }
}
