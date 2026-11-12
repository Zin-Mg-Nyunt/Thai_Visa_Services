<?php

namespace App\Enums;

/**
 * Where a worker is in the hiring flow of one job order. Separate from the payment status of the worker's service.
 */
enum WorkerCaseStatus: string
{
    case Applied = 'applied';
    case Interview = 'interview';
    case Hired = 'hired';
    case Working = 'working';
    case Rejected = 'rejected';
}
