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
     * @param int $mode Bit mask of AddressModes
     * @return array|null Array with address at index 0 and type at index 1, or null if the record does not exist
     */
    public function resolve($name, $mode);

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
