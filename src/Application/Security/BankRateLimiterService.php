<?php

namespace App\Application\Security;

use Symfony\Contracts\Cache\CacheInterface;

final class BankRateLimiterService
{
    public function __construct(
        private readonly CacheInterface $cache
    ) {}

    public function validate(int $bankClientId, int $limitPerMinute): void
    {
        if ($limitPerMinute <= 0) {
            return;
        }

        $window = gmdate('YmdHi');
        $key = sprintf('rate_limit_bank_%d_%s', $bankClientId, $window);

        $current = $this->cache->get($key, function ($item) {
            $item->expiresAfter(70);
            return 0;
        });

        $current++;

        $this->cache->delete($key);
        $this->cache->get($key, function ($item) use ($current) {
            $item->expiresAfter(70);
            return $current;
        });

        if ($current > $limitPerMinute) {
            throw new \RuntimeException('rate_limit_exceeded');
        }
    }
}