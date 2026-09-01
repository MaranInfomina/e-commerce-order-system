<?php

namespace App\Repositories;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;

/**
 * All Redis cart access lives here. Controllers never touch Redis directly,
 * so the key scheme and the hash representation can change without
 * rewriting HTTP code — and Milestone 3's order conversion has one place to
 * read a cart from.
 */
class CartRepository
{
    public function __construct(private readonly RedisFactory $redis) {}

    /**
     * @return array<int, int> productId => quantity
     */
    public function get(int $userId): array
    {
        $raw = $this->connection()->hgetall($this->key($userId));

        $cart = [];

        foreach ($raw as $productId => $quantity) {
            $cart[(int) $productId] = (int) $quantity;
        }

        return $cart;
    }

    public function setQuantity(int $userId, int $productId, int $quantity): void
    {
        $this->connection()->hset($this->key($userId), (string) $productId, (string) $quantity);
    }

    /**
     * HINCRBY rather than read-modify-write: atomic, so two tabs adding the
     * same product cannot lose one another's update.
     */
    public function increment(int $userId, int $productId, int $by): int
    {
        return (int) $this->connection()->hincrby($this->key($userId), (string) $productId, $by);
    }

    public function remove(int $userId, int $productId): void
    {
        $this->connection()->hdel($this->key($userId), (string) $productId);
    }

    public function clear(int $userId): void
    {
        $this->connection()->del($this->key($userId));
    }

    private function key(int $userId): string
    {
        return "cart:user:{$userId}";
    }

    /**
     * Which Redis connection holds the carts.
     *
     * Static and argument-taking for the same reason
     * TokenDenylist::connectionName() is (Task 5, `da50fd3`): phpunit.xml
     * forces REDIS_DB=15, so under the suite `default` and `test` resolve to
     * the SAME index. A repository silently rewired to `cache` would pass
     * every test in CartTest while putting production carts in the database a
     * routine flush empties. Splitting the choice out is what makes the
     * production arm assertable at all.
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
