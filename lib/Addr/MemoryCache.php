<?php

namespace Addr;

class MemoryCache implements Cache
{
    /**
     * Mapped names stored in the cache
     *
     * @var array
     */
    private $data = [
        AddressModes::INET4_ADDR => [],
        AddressModes::INET6_ADDR => [],
    ];

    /**
     * Look up an entry in the cache
     *
     * @param string $name
     * @param int $type
     * @return string|null
     */
    public function resolve($name, $type)
    {
        if (isset($this->data[$type][$name])) {
            if ($this->data[$type][$name][1] >= time()) {
                return $this->data[$type][$name][0];
            }

            unset($this->data[$type][$name]);
        }

        return null;
    }

    /**
     * Store an entry in the cache
     *
     * @param string $name
     * @param string $addr
     * @param int $type
     * @param int $ttl
     */
    public function store($name, $addr, $type, $ttl)
    {
        $this->data[$type][$name] = [$addr, time() + $ttl];
    }

    /**
     * Remove expired records from the cache
     */
    public function collectGarbage()
    {
        $now = time();

        foreach ([AddressModes::INET4_ADDR, AddressModes::INET6_ADDR] as $type) {
            while (list($name, $data) = each($this->data[$type])) {
                if ($data[1] < $now) {
                    unset($this->data[$type][$name]);
                }
            }
        }
    }
}
