<?php

namespace App\Enums;

/**
 * Where an application is in the hiring flow of one job order. Separate from the payment status of the worker's service.
 *
 * Hired, Rejected and Cancelled are final. Cancelled means the worker no longer wants this job order; it is not the same
 * as cancelling the whole Job Search service.
 */
enum WorkerCaseStatus: string
{
    case Applied = 'applied';
    case Interview = 'interview';
    case Hired = 'hired';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
}
