<?php

namespace App\Services;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

class TokenDenylist
{
    private const PREFIX = 'denylist:';

    public function __construct(private readonly RedisFactory $redis) {}

    /**
     * Revoke one token by its jti. The TTL is the token's own remaining
     * lifetime, so the entry disappears exactly when the token would have
     * expired anyway — the denylist cannot grow without bound.
     */
    public function revoke(string $jti, int $expiresAt): void
    {
        $ttl = $expiresAt - time();

        if ($ttl <= 0) {
            // Already expired; the signature check rejects it regardless.
            return;
        }

        $this->connection()->setex(self::PREFIX.$jti, $ttl, '1');
    }

    public function isRevoked(string $jti): bool
    {
        return (bool) $this->connection()->exists(self::PREFIX.$jti);
    }

    private function connection(): Connection
    {
        // The `default` connection is logical database 1 — carts and the
        // denylist. Deliberately not `cache`, which is safe to flush.
        return $this->redis->connection(
            app()->runningUnitTests() ? 'test' : 'default'
        );
    }
}
