<?php

namespace App\Services\Integrations;

use RuntimeException;

/** Thrown between steps when someone pressed Cancel on the Integrations page. */
class SyncCancelled extends RuntimeException
{
}
