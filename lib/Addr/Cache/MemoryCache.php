<?php

namespace Addr\Cache;

use Addr\Cache;

class MemoryCache implements Cache
{
    const MAX_TTL = 31536000;//1 year

    private $valueAndTTLArray = [];

    /**
     * Attempt to retrieve a value from the cache
     *
     * Returns an array [$cacheHit, $value]
     * [true, $valueFromCache] - if it existed in the cache
     * [false, null] - if it didn't already exist in the cache
     *
     * @param string $name
     * @return array
     */
    public function get($name)
    {
        if (array_key_exists($name, $this->valueAndTTLArray) == false) {
            return [false, null];
        }

        list($value, $expireTime) = $this->valueAndTTLArray[$name];

        if ($expireTime <= time()) {
            return [false, null]; //It's already expired, so don't return cached value;    
        }

        return [true, $value];
    }

    /**
     * Stores a value in the cache. Overwrites the previous value if there was one.
     *
     * @param string $name
     * @param string $value
     * @param int $ttl
     */
    public function store($name, $value, $ttl = null)
    {
        if ($ttl === null) {
            $ttl = self::MAX_TTL;
        }

        $this->valueAndTTLArray[$name] = [$value, time() + $ttl];
    }

    /**
     * Deletes an entry from the cache.
     *
     * @param string $name
     */
    public function delete($name)
    {
        unset($this->valueAndTTLArray[$name]);
    }
}
