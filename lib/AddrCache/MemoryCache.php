<?php


namespace AddrCache;


class MemoryCache implements \AddrCache\Cache {
    
    const MAX_TTL = 31536000;//1 year
    
    private $valueAndTTLArray = [];

    /**
     * Attempt to retrieve a value from the cache
     *
     * Returns an array [$cacheHit, $value]
     * [true, $valueFromCache] - if it existed in the cache
     * [false, null] - if it didn't already exist in the cache
     *
     * @param $key
     * @return array
     */
    public function get($key) {
        if (array_key_exists($key, $this->valueAndTTLArray) == false) {
            return [false, null];
        }

        list($value, $expireTime) = $this->valueAndTTLArray[$key];

        if ($expireTime <= time()) {
            return [false, null]; //It's already expired, so don't return cached value;    
        }

        return [true, $value];
    }

    /**
     * Stores a value in the cache. Overwrites the previous value if there was one.
     *
     * @param $key
     * @param $value
     * @param null $ttl
     */
    public function store($key, $value, $ttl = null) {
        if ($ttl === null) {
            $ttl = self::MAX_TTL;
        }

        $this->valueAndTTLArray[$key] = [$value, time() + $ttl];
    }

    /**
     * Deletes an entry from the cache.
     * @param $key
     */
    public function delete($key) {
        unset($this->valueAndTTLArray[$key]);
    }

    /**
     * Remove expired records from the cache
     */
    public function collectGarbage()
    {
        $now = time();

        foreach ($this->valueAndTTLArray as $key => $valueAndTTL) {
            if ($valueAndTTL[1] <= $now) {
                unset($this->valueAndTTLArray);
            }
        }
    }
}

 