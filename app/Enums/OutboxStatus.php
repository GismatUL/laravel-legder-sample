<?php

namespace App\Enums;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    // Nothing to deliver, e.g. the customer has no email address.
    case Skipped = 'skipped';
    // Gave up after the job's last attempt; `queue:retry` or a fix + relay can resend it.
    case Failed = 'failed';
}
