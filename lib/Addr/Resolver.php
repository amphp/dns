<?php

namespace Addr;

use Alert\Reactor;

class Resolver
{
    /**
     * @var Reactor
     */
    private $reactor;

    /**
     * @var NameValidator
     */
    private $nameValidator;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var Cache
     */
    private $cache;

    /**
     * @var HostsFile
     */
    private $hostsFile;

    /**
     * @var callable
     */
    private $cacheStoreCallback;

    /**
     * Constructor
     *
     * @param Reactor $reactor
     * @param NameValidator $nameValidator
     * @param Client $client
     * @param Cache $cache
     * @param HostsFile $hostsFile
     */
    public function __construct(
        Reactor $reactor,
        NameValidator $nameValidator,
        Client $client = null,
        Cache $cache = null,
        HostsFile $hostsFile = null
    ) {
        $this->reactor = $reactor;
        $this->nameValidator = $nameValidator;
        $this->client = $client;
        $this->cache = $cache;
        $this->hostsFile = $hostsFile;

        if ($cache) {
            $this->cacheStoreCallback = [$cache, 'store'];
        }
    }

    /**
     * Verify the supplied domain name is in a valid format
     *
     * @param $name
     * @param $callback
     * @return bool
     */
    private function validateName($name, $callback)
    {
        if (!$this->nameValidator->validate($name)) {
            $this->reactor->immediately(function() use($callback) {
                call_user_func($callback, null, ResolutionErrors::ERR_INVALID_NAME);
            });

            return false;
        }

        return true;
    }

    /**
     * Check if a supplied name is an IP address and resolve immediately
     *
     * @param string $name
     * @param callable $callback
     * @return bool
     */
    private function resolveAsIPAddress($name, $callback)
    {
        if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->reactor->immediately(function() use($callback, $name) {
                call_user_func($callback, $name, AddressModes::INET4_ADDR);
            });

            return true;
        } else if (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $this->reactor->immediately(function() use($callback, $name) {
                call_user_func($callback, $name, AddressModes::INET6_ADDR);
            });

            return true;
        }

        return false;
    }

    /**
     * Resolve a name in the hosts file
     *
     * @param string $name
     * @param int $mode
     * @param callable $callback
     * @return bool
     */
    private function resolveInHostsFile($name, $mode, $callback)
    {
        /* localhost should resolve regardless of whether we have a hosts file
           also the Windows hosts file no longer contains this record */
        if ($name === 'localhost') {
            if ($mode & AddressModes::PREFER_INET6) {
                $this->reactor->immediately(function() use($callback) {
                    call_user_func($callback, '::1', AddressModes::INET6_ADDR);
                });
            } else {
                $this->reactor->immediately(function() use($callback) {
                    call_user_func($callback, '127.0.0.1', AddressModes::INET4_ADDR);
                });
            }

            return true;
        }

        if (!$this->hostsFile || null === $result = $this->hostsFile->resolve($name, $mode)) {
            return false;
        }

        list($addr, $type) = $result;
        $this->reactor->immediately(function() use($callback, $addr, $type) {
            call_user_func($callback, $addr, $type);
        });

        return true;
    }

    /**
     * Resolve a name in the cache
     *
     * @param string $name
     * @param int $mode
     * @param callable $callback
     * @return bool
     */
    private function resolveInCache($name, $mode, $callback)
    {
        if ($this->cache && null !== $result = $this->cache->resolve($name, $mode)) {
            list($addr, $type) = $result;
            $this->reactor->immediately(function() use($callback, $addr, $type) {
                call_user_func($callback, $addr, $type);
            });

            return true;
        }

        return false;
    }

    /**
     * Resolve a name from a server
     *
     * @param string $name
     * @param int $mode
     * @param callable $callback
     * @return bool
     */
    private function resolveFromServer($name, $mode, $callback)
    {
        if (!$this->client) {
            $this->reactor->immediately(function() use($callback) {
                call_user_func($callback, null, ResolutionErrors::ERR_NO_RECORD);
            });

            return;
        }

        $this->client->resolve($name, $mode, $callback, $this->cacheStoreCallback);
    }

    /**
     * Resolve a name
     *
     * @param string $name
     * @param callable $callback
     * @param int $mode
     */
    public function resolve($name, callable $callback, $mode = 3)
    {
        if ($this->resolveAsIPAddress($name, $callback)) {
            return;
        }

        $name = strtolower($name);
        if (!$this->validateName($name, $callback)) {
            return;
        }

        if ($this->resolveInHostsFile($name, $mode, $callback) || $this->resolveInCache($name, $mode, $callback)) {
            return;
        }

        $this->resolveFromServer($name, $mode, $callback);
    }
}
