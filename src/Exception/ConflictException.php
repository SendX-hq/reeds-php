<?php

declare(strict_types=1);

namespace Reeds\Exception;

/** 409: the object's state doesn't allow it (already booked, cancelled, a quote already used). */
class ConflictException extends ReedsException
{
}
