<?php

namespace Amp\Dns;

use Amp\Reactor;

function validateHostName($name) {
    if (isset($name[253])) {
        return false;
    }

    $pattern = "/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9]){0,1})(?:\.[a-z0-9][a-z0-9\-]{0,61}[a-z0-9])*$/i";

    return (bool) \preg_match($pattern, $name);
}




class DnsServer {
    private $addr;
    private static $addresses = [];

    public function __construct($addr, $port = 53) {
        if (!$inAddr = @\inet_pton($addr)) {
            throw new \DomainException(
                "Invalid IP address: {$addr}"
            );
        }
        $addr = \inet_ntop($inAddr);
        if (isset($inAddr[15])) {
            $addr = "[{$addr}]";
        }
        $port = (int) $port;
        $this->addr = "udp://{$addr}:{$port}";
    }
}

function initServer()

function resolver(Reactor $reactor

class Resolver {
    private $
}

function resolve($name, array $options = []) {
    $generator = __doResolve($name, $options);

    return \Amp\resolve($generator);
}

function __doResolve($name, $options) {
    static $lookupIdCounter;
    static $cache;

    if (empty($cache)) {
        $cache = new \Amp\Cache\ArrayCache;
    }

    $mode = isset($options["mode"]) ? $options["mode"] : AddressModes::ANY_PREFER_INET4;
    if ($mode & AddressModes::PREFER_INET6) {
        if ($mode & AddressModes::INET6_ADDR) {
            $requests[] = AddressModes::INET6_ADDR;
        }
        if ($mode & AddressModes::INET4_ADDR) {
            $requests[] = AddressModes::INET4_ADDR;
        }
    } else {
        if ($mode & AddressModes::INET4_ADDR) {
            $requests[] = AddressModes::INET4_ADDR;
        }
        if ($mode & AddressModes::INET6_ADDR) {
            $requests[] = AddressModes::INET6_ADDR;
        }
    }

    $type = array_shift($requests);
    $cacheKey = $name . $type;

    if (yield $cache->has($cacheKey)) {
        $result = yield $cache->get($cacheKey);
        yield new \Amp\CoroutineResult($result);
        return;
    }

    do {
        $lookupId = $lookupIdCounter++;
        if ($lookupIdCounter >= PHP_INT_MAX) {
            $lookupIdCounter = 0;
        }
    } while (isset($pendingLookups[$lookupId]));

    $remote = isset($options["server_addr"])
        ? "udp://" . $options["server_addr"]
        : "udp://8.8.8.8:53"
    ;

    if (!$socket = @\stream_socket_client($remote, $errno, $errstr)) {
        throw new ConnectException(sprintf(
            "Connection to %s failed: [Error #%d] %s",
            $uri,
            $errno,
            $errstr
        ));
    }
    \stream_set_blocking($socket, false);



    if (yield $this->cache->has($cacheKey)) {
        $this->completePendingLookup($id, $addr, $type);
    } else {
        $this->dispatchRequest($id, $name, $type);
    }

    $promisor = new Deferred;
    $reactor->onReadable($socket, "Amp\Dns\__onReadable", $promisor);
    yield $promisor->promise();




}

function __getRequestModesFrom