<?php

namespace Amp\Dns;

use \LibDNS\Messages\MessageFactory;
use \LibDNS\Messages\MessageTypes;
use \LibDNS\Records\QuestionFactory;
use \LibDNS\Records\ResourceTypes;
use \LibDNS\Records\ResourceQTypes;
use \LibDNS\Encoder\EncoderFactory;
use \LibDNS\Decoder\DecoderFactory;

/**
 * Resolve a DNS name to an IP address
 *
 * Upon success the returned promise resolves to an indexed array of the form:
 *
 *  [string $resolvedIp, int $mode, int $ttl]
 *
 * The $mode parameter at index 1 corresponds to one of two constant values to
 * indicate if the resulting IP is IPv4 or IPv6:
 *
 *  - Amp\Dns\MODE_INET4
 *  - Amp\Dns\MODE_INET6
 *
 * A null $ttl value indicates the DNS name was resolved from the cache or the
 * local hosts file.
 *
 * Options:
 *
 *  - "server"       | string   Custom DNS server address in ip or ip:port format
 *  - "timeout"      | int      Default: 3000ms
 *  - "mode"         | int      Either Amp\Dns\MODE_INET4 or Amp\Dns\MODE_INET6
 *  - "no_hosts"     | bool     Ignore entries in the hosts file
 *  - "no_cache"     | bool     Ignore cached DNS response entries
 *
 * If the custom per-request "server" option is not present the resolver will
 * use the default from the following built-in constant:
 *
 *  - Amp\Dns\DEFAULT_SERVER
 *
 * @param string $name The hostname to resolve
 * @param array  $options
 * @return \Amp\Promise
 * @TODO add boolean "clear_cache" option flag
 * @TODO add boolean "reload_hosts" option flag
 */
function resolve($name, array $options = []) {
    $mode = isset($options["mode"]) ? $options["mode"] : MODE_INET4;
    if (!($mode === MODE_INET4 || $mode === MODE_INET6)) {
        return new \Amp\Failure(new ResolutionException(
            "Invalid request mode option; Amp\Dns\MODE_INET4 or Amp\Dns\MODE_INET6 required"
        ));
    } elseif (!$inAddr = @\inet_pton($name)) {
        return __isValidHostName($name)
            ? \Amp\resolve(__doResolve($name, $mode, $options))
            : new \Amp\Failure(new ResolutionException(
                "Cannot resolve; invalid host name"
            ))
        ;
    } elseif (isset($inAddr[4])) {
        return new \Amp\Success([$name, MODE_INET6, $ttl = null]);
    } else {
        return new \Amp\Success([$name, MODE_INET4, $ttl = null]);
    }
}

function __isValidHostName($name) {
    $pattern = "/^(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9]){0,1})(?:\.[a-z0-9][a-z0-9\-]{0,61}[a-z0-9])*$/i";

    return isset($name[253]) ? false : (bool) \preg_match($pattern, $name);
}

function __doResolve($name, $mode, $options) {
    static $state;
    $state = $state ?: (yield \Amp\resolve(__init()));

    $name = \strtolower($name);

    // Check for cache hits
    $cacheKey = "{$mode}#{$name}";
    if (empty($options["no_cache"])) {
        if (yield $state->arrayCache->has($cacheKey)) {
            $result = (yield $state->arrayCache->get($cacheKey));
            yield new \Amp\CoroutineResult([$result, $mode, $ttl = null]);
            return;
        }
    }

    // Check for hosts file matches
    if (empty($options["no_hosts"])) {
        $have4 = isset($state->hostsFile[MODE_INET4][$name]);
        $have6 = isset($state->hostsFile[MODE_INET6][$name]);
        $want4 = (bool)($mode & MODE_INET4);
        $want6 = (bool)($mode & MODE_INET6);
        if ($have6 && $want6) {
            $result = [$state->hostsFile[MODE_INET6][$name], MODE_INET6, $ttl = null];
        } elseif ($have4 && $want4) {
            $result = [$state->hostsFile[MODE_INET4][$name], MODE_INET4, $ttl = null];
        } else {
            $result = null;
        }
        if ($result) {
            yield new \Amp\CoroutineResult($result);
            return;
        }
    }

    $timeout = empty($options["timeout"]) ? DEFAULT_TIMEOUT : (int) $options["timeout"];

    $uri = empty($options["server"])
        ? "udp://" . DEFAULT_SERVER . ":" . DEFAULT_PORT
        : __parseCustomServerUri($options["server"])
    ;
    $server = __loadExistingServer($state, $uri) ?: __loadNewServer($state, $uri);

    // Get the next available request ID
    do {
        $requestId = $state->requestIdCounter++;
        if ($state->requestIdCounter >= MAX_REQUEST_ID) {
            $state->requestIdCounter = 1;
        }
    } while (isset($state->pendingRequests[$requestId]));

    // Create question record
    $questionType = ($mode === MODE_INET4) ? ResourceQTypes::A : ResourceQTypes::AAAA;
    $question = $state->questionFactory->create($questionType);
    $question->setName($name);

    // Create request message
    $request = $state->messageFactory->create(MessageTypes::QUERY);
    $request->getQuestionRecords()->add($question);
    $request->isRecursionDesired(true);
    $request->setID($requestId);

    // Encode request message
    $requestPacket = $state->encoder->encode($request);

    // Send request
    $bytesWritten = \fwrite($server->socket, $requestPacket);
    if ($bytesWritten === false || isset($packet[$bytesWritten])) {
        throw new ResolutionException(
            "Request send failed"
        );
    }

    $promisor = new \Amp\Deferred;
    $server->pendingRequests[$requestId] = true;
    $state->pendingRequests[$requestId] = [$promisor, $name, $mode];

    try {
        $resultArr = (yield \Amp\timeout($promisor->promise(), $timeout));
    } catch (\Amp\TimeoutException $e) {
        throw new TimeoutException(
            "Name resolution timed out for {$name}"
        );
    }

    list($resultIp, $resultMode, $resultTtl) = $resultArr;

    if ($resultMode === MODE_CNAME) {
        $result = (yield resolve($resultIp, $mode, $options));
        list($resultIp, $resultMode, $resultTtl) = $result;
    }

    yield $state->arrayCache->set($cacheKey, $resultIp, $resultTtl);
    yield new \Amp\CoroutineResult($resultArr);
}

function __init() {
    $state = new \StdClass;
    $state->messageFactory = new MessageFactory;
    $state->questionFactory = new QuestionFactory;
    $state->encoder = (new EncoderFactory)->create();
    $state->decoder = (new DecoderFactory)->create();
    $state->arrayCache = new \Amp\Cache\ArrayCache;
    $state->hostsFile = (yield \Amp\resolve(__loadHostsFile()));
    $state->requestIdCounter = 1;
    $state->pendingRequests = [];
    $state->serverIdMap = [];
    $state->serverUriMap = [];
    $state->serverIdTimeoutMap = [];
    $state->now = \time();
    $state->serverTimeoutWatcher = \Amp\repeat(function ($watcherId) use ($state) {
        $state->now = $now = \time();
        foreach ($state->serverIdTimeoutMap as $id => $expiry) {
            if ($now > $expiry) {
                __unloadServer($state, $id);
            }
        }
        if (empty($state->serverIdMap)) {
            \Amp\disable($watcherId);
        }
    }, 1000, $options = [
        "enable" => true,
        "keep_alive" => false,
    ]);

    yield new \Amp\CoroutineResult($state);
}

function __loadHostsFile($path = null) {
    $data = [
        MODE_INET4 => [],
        MODE_INET6 => [],
    ];
    if (empty($path)) {
        $path = \stripos(PHP_OS, 'win') === 0
            ? 'C:\Windows\system32\drivers\etc\hosts'
            : '/etc/hosts'
        ;
    }
    try {
        $contents = (yield \Amp\Filesystem\get($path));
    } catch (\Exception $e) {
        yield new \Amp\CoroutineResult($data);
        return;
    }
    $lines = \array_filter(\array_map("trim", \explode("\n", $contents)));
    foreach ($lines as $line) {
        if ($line[0] === "#") {
            continue;
        }
        $parts = \preg_split('/\s+/', $line);
        if (!($ip = @\inet_pton($parts[0]))) {
            continue;
        } elseif (isset($ip[4])) {
            $key = MODE_INET6;
        } else {
            $key = MODE_INET4;
        }
        for ($i = 1, $l = \count($parts); $i < $l; $i++) {
            if (__isValidHostName($parts[$i])) {
                $data[$key][strtolower($parts[$i])] = $parts[0];
            }
        }
    }

    yield new \Amp\CoroutineResult($data);
}

function __parseCustomServerUri($uri) {
    if (!\is_string($uri)) {
        throw new ResolutionException(
            "Invalid server address (". gettype($uri) ."); string IP required"
        );
    }
    if (($colonPos = strrpos(":", $uri)) !== false) {
        $addr = \substr($uri, 0, $colonPos);
        $port = \substr($uri, $colonPos);
    } else {
        $addr = $uri;
        $port = DEFAULT_PORT;
    }
    $addr = trim($addr, "[]");
    if (!$inAddr = @\inet_pton($addr)) {
        throw new ResolutionException(
            "Invalid server URI; IP address required"
        );
    }

    return isset($inAddr[4]) ? "udp://[{$addr}]:{$port}" : "udp://{$addr}:{$port}";
}

function __loadExistingServer($state, $uri) {
    if (empty($state->serverUriMap[$uri])) {
        return;
    }
    $server = $state->serverUriMap[$uri];
    if (\is_resource($server->socket)) {
        unset($state->serverIdTimeoutMap[$server->id]);
        \Amp\enable($server->watcherId);
        return $server;
    }
    __unloadServer($state, $server->id);
}

function __loadNewServer($state, $uri) {
    if (!$socket = @\stream_socket_client($uri, $errno, $errstr)) {
        throw new ResolutionException(sprintf(
            "Connection to %s failed: [Error #%d] %s",
            $uri,
            $errno,
            $errstr
        ));
    }

    \stream_set_blocking($socket, false);
    $id = (int) $socket;
    $server = new \StdClass;
    $server->id = $id;
    $server->uri = $uri;
    $server->socket = $socket;
    $server->pendingRequests = [];
    $server->watcherId = \Amp\onReadable($socket, "Amp\Dns\__onReadable", [
        "enable" => true,
        "keep_alive" => true,
        "cb_data" => $state,
    ]);
    $state->serverIdMap[$id] = $server;
    $state->serverUriMap[$uri] = $server;

    return $server;
}

function __unloadServer($state, $serverId, $error = null) {
    $server = $state->serverIdMap[$serverId];
    \Amp\cancel($server->watcherId);
    unset(
        $state->serverIdMap[$serverId],
        $state->serverUriMap[$server->uri]
    );
    if (\is_resource($server->socket)) {
        @\fclose($server->socket);
    }
    if ($error && $server->pendingRequests) {
        foreach (array_keys($server->pendingRequests) as $requestId) {
            list($promisor) = $state->pendingRequests[$requestId];
            $promisor->fail($error);
        }
    }
}

function __onReadable($watcherId, $socket, $state) {
    $serverId = (int) $socket;
    $packet = @\fread($socket, 512);
    if ($packet != "") {
        __decodeResponsePacket($state, $serverId, $packet);
    } else {
        __unloadServer($state, $serverId, new ResolutionException(
            "Server connection failed"
        ));
    }
}

function __decodeResponsePacket($state, $serverId, $packet) {
    try {
        $response = $state->decoder->decode($packet);
        $requestId = $response->getID();
        $responseCode = $response->getResponseCode();
        $responseType = $response->getType();

        if ($responseCode !== 0) {
            __finalizeResult($state, $serverId, $requestId, new ResolutionException(
                "Server returned error code: {$responseCode}"
            ));
        } elseif ($responseType !== MessageTypes::RESPONSE) {
            __unloadServer($state, $serverId, new ResolutionException(
                "Invalid server reply; expected RESPONSE but received QUERY"
            ));
        } else {
            __processDecodedResponse($state, $serverId, $requestId, $response);
        }
    } catch (\Exception $e) {
        __unloadServer($state, $serverId, new ResolutionException(
            "Response decode error",
            0,
            $e
        ));
    }
}

function __processDecodedResponse($state, $serverId, $requestId, $response) {
    static $typeMap = [
        MODE_INET4 => ResourceTypes::A,
        MODE_INET6 => ResourceTypes::AAAA,
    ];

    list($promisor, $name, $mode) = $state->pendingRequests[$requestId];
    $answers = $response->getAnswerRecords();
    foreach ($answers as $record) {
        switch ($record->getType()) {
            case $typeMap[$mode]:
                $result = [(string) $record->getData(), $mode, $record->getTTL()];
                break 2;
            case ResourceTypes::CNAME:
                // CNAME should only be used if no A records exist so we only
                // break out of the switch (and not the foreach loop) here.
                $result = [(string) $record->getData(), MODE_CNAME, $record->getTTL()];
                break;
        }
    }
    if (empty($result)) {
        $recordType = ($mode === MODE_INET4) ? "A" : "AAAA";
        __finalizeResult($state, $serverId, $requestId, new NoRecordException(
            "No {$recordType} records returned for {$name}"
        ));
    } else {
        __finalizeResult($state, $serverId, $requestId, $error = null, $result);
    }
}

function __finalizeResult($state, $serverId, $requestId, $error = null, $result = null) {
    if (empty($state->pendingRequests[$requestId])) {
        return;
    }

    list($promisor) = $state->pendingRequests[$requestId];
    $server = $state->serverIdMap[$serverId];
    unset(
        $state->pendingRequests[$requestId],
        $server->pendingRequests[$requestId]
    );
    if (empty($server->pendingRequests)) {
        $state->serverIdTimeoutMap[$server->id] = $state->now + IDLE_TIMEOUT;
        \Amp\disable($server->watcherId);
        \Amp\enable($state->serverTimeoutWatcher);
    }
    if ($error) {
        $promisor->fail($error);
    } else {
        $promisor->succeed($result);
    }
}
