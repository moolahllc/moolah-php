<?php

declare(strict_types=1);

namespace Moolah\Poynt\Enum;

/** Card-on-file arrangement a stored-card charge belongs to. API default: Unscheduled. */
enum CardOnFileIntent: string
{
    /** Stored card charged at no fixed schedule. */
    case Unscheduled = 'UNSCHEDULED_COF_TXN';
    /** Regular charge on a fixed schedule (e.g. a monthly membership). */
    case Recurring = 'RECURRING_COF_TXN';
    /** One of a fixed number of payments for one purchase (e.g. a treatment plan). */
    case Installment = 'INSTALLMENT_COF_TXN';
}
