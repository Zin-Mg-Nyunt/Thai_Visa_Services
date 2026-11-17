<?php

namespace App\Enums;

/**
 * What a person's In Progress Job Search record (engagement) allows when they are added to a job order.
 */
enum EngagementState: string
{
    /** No In Progress record: a new one is created, whatever Complete or Cancelled records the person has. */
    case None = 'none';

    /** One In Progress record whose applications are all Rejected or Cancelled: it is reused. */
    case Reusable = 'reusable';

    /** An application is still Applied or Interview: no new assignment. */
    case OpenApplication = 'open_application';

    /** Something that normal workflows cannot produce (see FindEngagement): refuse, log, change nothing. */
    case Inconsistent = 'inconsistent';
}
