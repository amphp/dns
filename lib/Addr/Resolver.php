<?php

namespace Addr;

use Alert\Reactor;

class Resolver
{
    const INET4_ADDR = 1;
    const INET6_ADDR = 2;
    const PREFER_INET4 = 4;

    private $domainNameMatchExpr = '/^(?:[a-z][a-z0-9\-]{0,61}[a-z0-9])(?:\.[a-z][a-z0-9\-]{0,61}[a-z0-9])*/i';

    /**
     * @var Reactor
     */
    private $reactor;

    /**
     * @var Cache
     */
    private $cache;

    private $hostsFilePath;

    private $hostsFileLastModTime = 0;

    private $hostsFileData = [];

    /**
     * @param Reactor $reactor
     * @param Cache $cache
     */
    public function __construct(Reactor $reactor, Cache $cache = null)
    {
        $this->reactor = $reactor;

        $path = stripos(PHP_OS, 'win') === 0 ? 'C:\Windows\system32\drivers\etc\hosts' : '/etc/hosts';
        if (is_file($path) && is_readable($path)) {
            $this->hostsFilePath = $path;
        }
    }

    /**
     * Parse a hosts file into an array
     */
    private function reloadHostsFileData()
    {
        $this->hostsFileData = [];

        foreach (file($this->hostsFilePath) as $line) {
            $line = trim($line);
            if ($line[0] === '#') {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if (!filter_var($parts[0], FILTER_VALIDATE_IP)) {
                continue;
            }

            for ($i = 1, $l = count($parts); $i < $l; $i++) {
                if (preg_match($this->domainNameMatchExpr, $parts[$i])) {
                    $this->hostsFileData[$parts[$i]] = $parts[0];
                }
            }
        }
    }

    /**
     * Lookup a name in the hosts file
     *
     * @param string $name
     * @return string|null
     */
    private function resolveFromHostsFile($name)
    {
        if ($this->hostsFilePath) {
            clearstatcache(true, $this->hostsFilePath);
            $mtime = filemtime($this->hostsFilePath);

            if ($mtime > $this->hostsFileLastModTime) {
                $this->reloadHostsFileData();
            }
        }

        return isset($this->hostsFileData[$name]) ? $this->hostsFileData[$name] : null;
    }

    /**
     * Try and resolve a name through the hosts file or the cache
     *
     * @param string $name
     * @param callable $callback
     * @param int $mode
     * @return bool
     */
    private function resolveNameLocally($name, callable $callback, $mode = 7)
    {
        if (null !== $result = $this->resolveFromHostsFile($name)) {
            $this->reactor->immediately(function() use($callback, $result) {
                call_user_func($callback, $result);
            });

            return true;
        }

        if ($this->cache && null !== $result = $this->cache->fetch($name)) {
            $this->reactor->immediately(function() use($callback, $result) {
                call_user_func($callback, $result);
            });

            return true;
        }

        return false;
    }

    /**
     * @param string $name
     * @param callable $callback
     */
    public function resolve($name, callable $callback)
    {
        if ($this->resolveNameLocally($name, $callback)) {
            return;
        }

    }
}
