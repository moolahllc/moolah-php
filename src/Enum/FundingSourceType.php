<?php

declare(strict_types=1);

namespace Moolah\Poynt\Enum;

/** Which rail a payment token is charged on. */
enum FundingSourceType: string
{
    case Card = 'CARD';
    /** Bank account. Surcharge never applies. */
    case Ach = 'CHEQUE';
}
