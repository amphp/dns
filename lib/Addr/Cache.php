<?php

namespace Addr;

/**
 * Interface for record caches
 *
 * @package Addr
 */
interface Cache
{
    /**
     * Look up a name in the cache
     *
     * @param string $name Name to query
     * @param int $type AddressModes::INET4_ADDR or AddressModes::INET6_ADDR
     * @param callable $callback Function to receive the result
     */
    public function resolve($name, $type, callable $callback);

    /**
     * Store an entry in the cache
     *
     * @param string $name Name to query
     * @param string $addr IP address that $name maps to
     * @param int $type AddressModes::INET4_ADDR or AddressModes::INET6_ADDR
     * @param int $ttl Time the record should live, in seconds
     */
    public function store($name, $addr, $type, $ttl);

    /**
     * Remove all expired records from the cache
     */
    public function collectGarbage();
}
