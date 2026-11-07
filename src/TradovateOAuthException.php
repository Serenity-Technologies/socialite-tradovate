<?php

namespace SocialiteProviders\Tradovate;

use RuntimeException;
use Throwable;

/**
 * A typed Tradovate OAuth failure.
 *
 * `$errorCode` is either the provider's own `error` value or one of the package's
 * stable codes (`token_exchange_unreachable`, `token_exchange_failed`,
 * `token_missing`, `identity_failed`). `$detail` never carries a token, a request
 * body or a response body — only an HTTP status or a short, fixed description.
 */
class TradovateOAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $detail = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $detail === '' ? $errorCode : "tradovate_oauth: {$errorCode}: {$detail}",
            0,
            $previous,
        );
    }
}
