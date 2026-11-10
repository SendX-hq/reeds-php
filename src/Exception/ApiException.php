<?php

declare(strict_types=1);

namespace Reeds\Exception;

/** Any other failure status (5xx), or an answer the library doesn't recognise. */
class ApiException extends ReedsException
{
}
