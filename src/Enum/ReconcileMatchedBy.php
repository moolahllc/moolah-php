<?php

declare(strict_types=1);

namespace Moolah\Poynt\Enum;

enum ReconcileMatchedBy: string
{
    /** The terminal's own result for this exact payment. */
    case Reference = 'REFERENCE';
    /** No result was received; transactions are the terminal's activity around that time. */
    case TimeWindow = 'TIME_WINDOW';
}
