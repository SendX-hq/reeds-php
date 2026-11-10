<?php

declare(strict_types=1);

namespace Reeds\Exception;

/** A webhook whose signature or timestamp doesn't check out. Reply 400 and don't act on it. */
class WebhookSignatureException extends ReedsException
{
}
