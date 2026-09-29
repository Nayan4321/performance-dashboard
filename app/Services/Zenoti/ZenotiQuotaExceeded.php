<?php

namespace App\Services\Zenoti;

use RuntimeException;

/** Zenoti answered 429: the API key's call quota is used up for now. */
class ZenotiQuotaExceeded extends RuntimeException
{
}
