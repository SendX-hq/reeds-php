<?php

declare(strict_types=1);

namespace Reeds\Exception;

/** 400 or 422: the request itself is wrong. `$fields` lists each invalid field. */
class InvalidRequestException extends ReedsException
{
}
