<?php

namespace Addr;

interface Cache
{
    /**
     * Attempt to retrieve a value from the cache
     *
     * Callback has the following signature:
     *  void callback ( bool $cacheHit, string $address )
     *
     * Called with the following values:
     *  [true, $valueFromCache] - if it existed in the cache
     *  [false, null] - if it didn't exist in the cache
     *
     * @param string $name
     * @param int $type
     * @param callable $callback
     */
    public function get($name, $type, callable $callback);

    /**
     * Stores a value in the cache. Overwrites the previous value if there was one.
     *
     * @param string $name
     * @param int $type
     * @param string $addr
     * @param int $ttl
     */
    public function store($name, $type, $addr, $ttl);
}
