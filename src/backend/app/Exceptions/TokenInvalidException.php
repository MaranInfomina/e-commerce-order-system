<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown for every token failure — malformed, bad signature, expired,
 * revoked. Deliberately carries no detail about which: telling a caller
 * that a token is revoked rather than expired tells an attacker which
 * tokens are live.
 */
class TokenInvalidException extends RuntimeException {}
