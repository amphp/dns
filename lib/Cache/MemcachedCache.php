<?php

namespace Amp\Dns\Cache;

use Memcached;

class MemcachedCache extends KeyValueCache {
    /**
     * @var Memcached
     */
    private $memcached;

    /**
     * Constructor
     *
     * @param Memcached $memcached
     * @param string $keyPrefix A prefix to prepend to all keys.
     */
    public function __construct(Memcached $memcached, $keyPrefix = __CLASS__)
    {
        $this->memcached = $memcached;
        parent::__construct($keyPrefix);
    }

    /**
     * Attempt to retrieve a value from the cache
     *
     * @param string $name
     * @param int $type
     * @param callable $callback
     */
    public function get($name, $type, callable $callback)
    {
        $wasHit = true;
        $value = $this->memcached->get($this->generateKey($name, $type), function() use(&$wasHit) {
            $wasHit = false;
            return false;
        });

        if ($wasHit) {
            $callback(true, $value);
            return;
        }

        $callback(false, null);
    }

    /**
     * Stores a value in the cache. Overwrites the previous value if there was one.
     *
     * @param string $name
     * @param int $type
     * @param string $addr
     * @param int $ttl
     */
    public function store($name, $type, $addr, $ttl = null)
    {
        if ($addr === null) {
            throw new \InvalidArgumentException('Caching null addresses is disallowed');
        }

        if ($ttl === null) {
            $ttl = self::DEFAULT_TTL;
        }

        $this->memcached->set($this->generateKey($name, $type), $addr, $ttl);
    }

    /**
     * Deletes an entry from the cache.
     *
     * @param string $name
     * @param int $type
     */
    public function delete($name, $type)
    {
        $this->memcached->delete($this->generateKey($name, $type));
    }
}
