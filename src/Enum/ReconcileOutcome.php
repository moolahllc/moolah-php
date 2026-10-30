<?php

declare(strict_types=1);

namespace Moolah\Poynt\Enum;

/** Outcome of payments->reconcile(). Compare with ReconcileOutcome::tryFrom($rec->outcome). */
enum ReconcileOutcome: string
{
    /** The payment happened. Do not retry. */
    case Completed = 'COMPLETED';
    /** The terminal reported back with no transaction. Safe to retry. */
    case NotCompleted = 'NOT_COMPLETED';
    /** Still unresolved. A retry may double-charge; ask again later. */
    case Pending = 'PENDING';
}
