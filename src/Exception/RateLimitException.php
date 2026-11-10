<?php

declare(strict_types=1);

namespace Reeds\Exception;

/** 429. `$retryAfter` is the seconds Reeds asked you to wait. */
class RateLimitException extends ReedsException
{
    /** Seconds from the `Retry-After` header, when Reeds sent one. */
    public ?float $retryAfter = null;
}
