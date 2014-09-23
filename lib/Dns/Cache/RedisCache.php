<?php

namespace Amp\Dns\Cache;

use Predis\Client as RedisClient;

class RedisCache extends KeyValueCache {
    /**
     * @var RedisClient
     */
    private $redisClient;

    /**
     * Lua script to get a value, and flag whether it was cache hit or miss.
     * Don't delete the "== 1" - it's important in Lua
     *
     * @var string
     */
    private $fetchLuaScript = <<< SCRIPT
if redis.call("exists", KEYS[1]) == 1
then
    return {1, redis.call("get", KEYS[1])}
else
    return {0, 0}
end
SCRIPT;

    /**
     * Constructor
     *
     * @param RedisClient $redisClient
     * @param string $keyPrefix A prefix to prepend to all keys. This can also be
     * set via the redis client, in which case you may wish to pass an empty string
     * as $prefixKey
     */
    public function __construct(RedisClient $redisClient, $keyPrefix = __CLASS__) {
        $this->redisClient = $redisClient;
        parent::__construct($keyPrefix);
    }

    /**
     * Attempt to retrieve a value from the cache
     *
     * @param string $name
     * @param int $type
     * @param callable $callback
     */
    public function get($name, $type, callable $callback) {
        list($wasHit, $value) = $this->redisClient->eval($this->fetchLuaScript, 1, $this->generateKey($name, $type));

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
    public function store($name, $type, $addr, $ttl = null) {
        $key = $this->generateKey($name, $type);

        if ($ttl > 0) {
            $this->redisClient->set($key, $addr, 'EX', (int)$ttl);
        } else {
            $this->redisClient->set($key, $addr);
        }
    }

    /**
     * Deletes an entry from the cache.
     *
     * @param string $name
     * @param int $type
     */
    public function delete($name, $type) {
        $this->redisClient->del([$this->generateKey($name, $type)]);
    }
}
