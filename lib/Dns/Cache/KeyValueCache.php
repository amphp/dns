<?php

namespace Amp\Dns\Cache;

use Amp\Dns\Cache;

abstract class KeyValueCache implements Cache {
    /**
     * A prefix to prepend to all keys
     *
     * @var string
     */
    private $keyPrefix;

    /**
     * Constructor
     *
     * @param string $keyPrefix A prefix to prepend to all keys.
     */
    public function __construct($keyPrefix)
    {
        $this->keyPrefix = (string)$keyPrefix;
    }

    /**
     * Generate a data store key for a record
     *
     * @param string $name
     * @param int $type
     * @return string
     */
    protected function generateKey($name, $type)
    {
        return $this->keyPrefix . 'Name:' . $name . ',Type:' . $type;
    }

    /**
     * Get the prefix to prepend to all keys
     *
     * @return string
     */
    public function getKeyPrefix()
    {
        return $this->keyPrefix;
    }

    /**
     * Set the prefix to prepend to all keys
     *
     * @param string $keyPrefix
     */
    public function setKeyPrefix($keyPrefix)
    {
        $this->keyPrefix = (string)$keyPrefix;
    }

    /**
     * Attempt to retrieve a value from the cache
     *
     * @param string $name
     * @param int $type
     * @param callable $callback
     */
    abstract public function get($name, $type, callable $callback);

    /**
     * Stores a value in the cache. Overwrites the previous value if there was one.
     *
     * @param string $name
     * @param int $type
     * @param string $addr
     * @param int $ttl
     */
    abstract public function store($name, $type, $addr, $ttl);
}
