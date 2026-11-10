<?php

declare(strict_types=1);

namespace Reeds\Exception;

/** 402: the wallet or credit doesn't cover it (`INSUFFICIENT_BALANCE`, `CREDIT_LIMIT_REACHED`). */
class PaymentRequiredException extends ReedsException
{
}
