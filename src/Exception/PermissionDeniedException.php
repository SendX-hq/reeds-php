<?php

declare(strict_types=1);

namespace Reeds\Exception;

/** 403: the credentials can't do this (a missing scope, an IP not allowed, a sandbox call with live credentials). */
class PermissionDeniedException extends ReedsException
{
}
