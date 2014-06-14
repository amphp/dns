<?php

namespace Addr;

class Cache
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
     * Look up a name in the cache
     *
     * @param string $name
     * @param int $mode
     * @return string|null
     */
    public function resolve($name, $mode)
    {
        $have4 = isset($this->data[AddressModes::INET4_ADDR][$name]);
        $have6 = isset($this->data[AddressModes::INET6_ADDR][$name]);

        if ($have6 && (!$have4 || $mode & AddressModes::PREFER_INET6)) {
            $type = AddressModes::INET6_ADDR;
        } else if ($have4) {
            $type = AddressModes::INET4_ADDR;
        } else {
            return null;
        }

        if ($this->data[$type][$name][1] < time()) {
            unset($this->data[$type][$name]);
            return null;
        }

        return [$this->data[$type][$name][0], $type];
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
