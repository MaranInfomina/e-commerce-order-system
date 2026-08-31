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

    /**
     * Which Redis connection holds the denylist.
     *
     * Split out with an explicit argument so the production arm is assertable.
     * The suite only ever takes the `test` arm, so left inline a change from
     * `default` to `cache` would move every revoked token into the database a
     * routine cache flush empties — and the whole suite would stay green.
     */
    public static function connectionName(bool $testing): string
    {
        // `default` is logical database 1 — carts and the denylist.
        // Deliberately not `cache`, which is safe to flush.
        return $testing ? 'test' : 'default';
    }

    private function connection(): Connection
    {
        return $this->redis->connection(
            self::connectionName(app()->runningUnitTests())
        );
    }
}
