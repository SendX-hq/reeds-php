<?php

declare(strict_types=1);

namespace Reeds\Exception;

/** 401, or the token endpoint refusing the credentials (`invalid_client`), or none given. */
class AuthenticationException extends ReedsException
{
}
