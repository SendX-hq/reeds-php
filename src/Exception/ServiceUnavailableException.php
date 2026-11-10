<?php

declare(strict_types=1);

namespace Reeds\Exception;

/** 503: Reeds is shedding load and did nothing. `$retryAfter` is the seconds to wait. */
class ServiceUnavailableException extends ReedsException
{
    /** Seconds from the `Retry-After` header, when Reeds sent one. */
    public ?float $retryAfter = null;
}
