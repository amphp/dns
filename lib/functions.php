<?php

namespace Amp\Dns;

use Amp\Cache\ArrayCache;
use Amp\CoroutineResult;
use Amp\Deferred;
use Amp\Failure;
use Amp\Success;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\QuestionFactory;

/**
 * Resolve a hostname name to an IP address
 * [hostname as defined by RFC 3986]
 *
 * Upon success the returned promise resolves to an indexed array of the form:
 *
 *  [string $recordValue, int $type, int $ttl]
 *
 * A null $ttl value indicates the DNS name was resolved from the cache or the
 * local hosts file.
 * $type being one constant from Amp\Dns\Record
 *
 * Options:
 *
 *  - "server"       | string   Custom DNS server address in ip or ip:port format (Default: 8.8.8.8:53)
 *  - "timeout"      | int      DNS server query timeout (Default: 3000ms)
 *  - "hosts"        | bool     Use the hosts file (Default: true)
 *  - "reload_hosts" | bool     Reload the hosts file (Default: false), only active when no_hosts not true
 *  - "cache"        | bool     Use local DNS cache when querying (Default: true)
 *  - "types"        | array    Default: [Record::A, Record::AAAA] (only for resolve())
 *  - "recurse"      | bool     Check for DNAME and CNAME records (always active for resolve(), Default: false for query())
 *
 * If the custom per-request "server" option is not present the resolver will
 * use the first nameserver in /etc/resolv.conf or default to Google's public
 * DNS servers on Windows or if /etc/resolv.conf is not found.
 *
 * @param string $name The hostname to resolve
 * @param array  $options
 * @return \Amp\Promise
 * @TODO add boolean "clear_cache" option flag
 */
function resolve($name, array $options = []) {
    if (!$inAddr = @\inet_pton($name)) {
        if (__isValidHostName($name)) {
            $types = empty($options["types"]) ? [Record::A, Record::AAAA] : $options["types"];
            return __pipeResult(__recurseWithHosts($name, $types, $options), $types);
        } else {
            return new Failure(new ResolutionException("Cannot resolve; invalid host name"));
        }
    } else {
        return new Success([[$name, isset($inAddr[4]) ? Record::AAAA : Record::A, $ttl = null]]);
    }
}

/**
 * Query specific DNS records.
 *
 * @param string $name Unlike resolve(), query() allows for requesting _any_ name (as DNS RFC allows for arbitrary strings)
 * @param int|int[] $type Use constants of Amp\Dns\Record
 * @param array $options @see resolve documentation
 * @return \Amp\Promise
 */
function query($name, $type, array $options = []) {
    $handler = __NAMESPACE__ . "\\" . (empty($options["recurse"]) ?  "__doResolve" : "__doRecurse");
    $types = (array) $type;
    return __pipeResult(\Amp\resolve($handler($name, $types, $options)), $types);
}

function __isValidHostName($name) {
    $pattern = "/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9]){0,1})(?:\\.[a-z0-9][a-z0-9-]{0,61}[a-z0-9])*$/i";

    return !isset($name[253]) && \preg_match($pattern, $name);
}

// flatten $result while preserving order according to $types (append unspecified types for e.g. Record::ALL queries)
function __pipeResult($promise, array $types) {
    return \Amp\pipe($promise, function (array $result) use ($types) {
        $retval = [];
        foreach ($types as $type) {
            if (isset($result[$type])) {
                $retval = \array_merge($retval, $result[$type]);
                unset($result[$type]);
            }
        }
        return $result ? \array_merge($retval, \call_user_func_array("array_merge", $result)) : $retval;
    });
}

function __recurseWithHosts($name, array $types, $options) {
    // Check for hosts file matches
    if (!isset($options["hosts"]) || $options["hosts"]) {
        static $hosts = null;
        if ($hosts === null || !empty($options["reload_hosts"])) {
            return \Amp\pipe(\Amp\resolve(__loadHostsFile()), function ($value) use (&$hosts, $name, $types, $options) {
                unset($options["reload_hosts"]); // avoid recursion
                $hosts = $value;
                return __recurseWithHosts($name, $types, $options);
            });
        }
        $result = [];
        if (in_array(Record::A, $types) && isset($hosts[Record::A][$name])) {
            $result[Record::A] = [[$hosts[Record::A][$name], Record::A, $ttl = null]];
        }
        if (in_array(Record::AAAA, $types) && isset($hosts[Record::AAAA][$name])) {
            $result[Record::AAAA] = [[$hosts[Record::AAAA][$name], Record::AAAA, $ttl = null]];
        }
        if ($result) {
            return new Success($result);
        }
    }

    return \Amp\resolve(__doRecurse($name, $types, $options));
}

function __doRecurse($name, array $types, $options) {
    if (array_intersect($types, [Record::CNAME, Record::DNAME])) {
        throw new ResolutionException("Cannot use recursion for CNAME and DNAME records");
    }

    $types = array_merge($types, [Record::CNAME, Record::DNAME]);
    $lookupName = $name;
    for ($i = 0; $i < 30; $i++) {
        $result = (yield \Amp\resolve(__doResolve($lookupName, $types, $options)));
        if (count($result) > isset($result[Record::CNAME]) + isset($result[Record::DNAME])) {
            unset($result[Record::CNAME], $result[Record::DNAME]);
            yield new CoroutineResult($result);
            return;
        }
        // @TODO check for potentially using recursion and iterate over *all* CNAME/DNAME
        // @FIXME check higher level for CNAME?
        foreach ([Record::CNAME, Record::DNAME] as $type) {
            if (isset($result[$type])) {
                list($lookupName) = $result[$type][0];
            }
        }
    }

    throw new ResolutionException("CNAME or DNAME chain too long (possible recursion?)");
}

function __doRequest($state, $uri, $name, $type) {
    $server = __loadExistingServer($state, $uri) ?: __loadNewServer($state, $uri);

    // Get the next available request ID
    do {
        $requestId = $state->requestIdCounter++;
        if ($state->requestIdCounter >= MAX_REQUEST_ID) {
            $state->requestIdCounter = 1;
        }
    } while (isset($state->pendingRequests[$requestId]));

    // Create question record
    $question = $state->questionFactory->create($type);
    $question->setName($name);

    // Create request message
    $request = $state->messageFactory->create(MessageTypes::QUERY);
    $request->getQuestionRecords()->add($question);
    $request->isRecursionDesired(true);
    $request->setID($requestId);

    // Encode request message
    $requestPacket = $state->encoder->encode($request);

    if (substr($uri, 0, 6) == "tcp://") {
        $requestPacket = pack("n", strlen($requestPacket)) . $requestPacket;
    }

    // Send request
    $bytesWritten = \fwrite($server->socket, $requestPacket);
    if ($bytesWritten === false || isset($packet[$bytesWritten])) {
        throw new ResolutionException(
            "Request send failed"
        );
    }

    $promisor = new Deferred;
    $server->pendingRequests[$requestId] = true;
    $state->pendingRequests[$requestId] = [$promisor, $name, $type, $uri];

    return $promisor->promise();
}

function __doResolve($name, array $types, $options) {
    static $state;
    $state = $state ?: (yield \Amp\resolve(__init()));

    if (empty($types)) {
        yield new CoroutineResult([]);
        return;
    }

    assert(array_reduce($types, function ($result, $val) { return $result && \is_int($val); }, true), 'The $types passed to DNS functions must all be integers (from \Amp\Dns\Record class)');

    $name = \strtolower($name);
    $result = [];

    // Check for cache hits
    if (!isset($options["cache"]) || $options["cache"]) {
        foreach ($types as $k => $type) {
            $cacheKey = "$name#$type";
            $cacheValue = (yield $state->arrayCache->get($cacheKey));

            if ($cacheValue !== null) {
                $result[$type] = $cacheValue;
                unset($types[$k]);
            }
        }
        if (empty($types)) {
            yield new CoroutineResult($result);
            return;
        }
    }

    $timeout = empty($options["timeout"]) ? $state->config["timeout"] : (int) $options["timeout"];

    if (empty($options["server"])) {
        if (empty($state->config["nameservers"])) {
            throw new ResolutionException("No nameserver specified in system config");
        }

        $uri = "udp://" . $state->config["nameservers"][0];
    } else {
        $uri = __parseCustomServerUri($options["server"]);
    }

    foreach ($types as $type) {
        $promises[] = __doRequest($state, $uri, $name, $type);
    }

    try {
        list( , $resultArr) = (yield \Amp\timeout(\Amp\some($promises), $timeout));
        foreach ($resultArr as $value) {
            $result += $value;
        }
    } catch (\Amp\TimeoutException $e) {
        if (substr($uri, 0, 6) == "tcp://") {
            throw new TimeoutException(
                "Name resolution timed out for {$name}"
            );
        } else {
            $options["server"] = \preg_replace("#[a-z.]+://#", "tcp://", $uri);
            yield new CoroutineResult(\Amp\resolve(__doResolve($name, $types, $options)));
            return;
        }
    } catch (ResolutionException $e) {
        if (empty($result)) { // if we have no cached results
            throw $e;
        }
    } catch (\Amp\CombinatorException $e) { // if all promises in Amp\some fail
        if (empty($result)) { // if we have no cached results
            throw new ResolutionException("All name resolution requests failed", 0, $e);
        }
    }

    yield new CoroutineResult($result);
}

function __init() {
    $state = new \StdClass;
    $state->messageFactory = new MessageFactory;
    $state->questionFactory = new QuestionFactory;
    $state->encoder = (new EncoderFactory)->create();
    $state->decoder = (new DecoderFactory)->create();
    $state->arrayCache = new ArrayCache;
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

    $state->config = (yield \Amp\resolve(__loadResolvConf()));

    yield new CoroutineResult($state);
}

/** @link http://man7.org/linux/man-pages/man5/resolv.conf.5.html */
function __loadResolvConf($path = null) {
    $result = [
        "nameservers" => [
            "8.8.8.8:53",
            "8.8.4.4:53",
        ],
        "timeout" => 3000,
        "attempts" => 2,
    ];

    if (\stripos(PHP_OS, "win") !== 0) {
        $path = $path ?: "/etc/resolv.conf";

        try {
            $lines = explode("\n", (yield \Amp\File\get($path)));
            $result["nameservers"] = [];

            foreach ($lines as $line) {
                $line = \preg_split('#\s+#', $line, 2);
                if (\count($line) !== 2) {
                    continue;
                }

                list($type, $value) = $line;
                if ($type === "nameserver") {
                    $line[1] = trim($line[1]);
                    $ip = @\inet_pton($line[1]);

                    if ($ip === false) {
                        continue;
                    }

                    if (isset($ip[15])) {
                        $result["nameservers"][] = "[" . $line[1] . "]:53";
                    } else {
                        $result["nameservers"][] = $line[1] . ":53";
                    }
                } elseif ($type === "options") {
                    $optline = preg_split('#\s+#', $value, 2);
                    if (\count($optline) !== 2) {
                        continue;
                    }

                    // TODO: Respect the contents of the attempts setting during resolution

                    list($option, $value) = $optline;
                    if (in_array($option, ["timeout", "attempts"])) {
                        $result[$option] = (int) $value;
                    }
                }
            }
        } catch (\Amp\File\FilesystemException $e) {}
    }

    yield new CoroutineResult($result);
}

function __loadHostsFile($path = null) {
    $data = [];
    if (empty($path)) {
        $path = \stripos(PHP_OS, "win") === 0
            ? 'C:\Windows\system32\drivers\etc\hosts'
            : '/etc/hosts';
    }
    try {
        $contents = (yield \Amp\File\get($path));
    } catch (\Exception $e) {
        yield new CoroutineResult($data);

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
            $key = Record::AAAA;
        } else {
            $key = Record::A;
        }
        for ($i = 1, $l = \count($parts); $i < $l; $i++) {
            if (__isValidHostName($parts[$i])) {
                $data[$key][strtolower($parts[$i])] = $parts[0];
            }
        }
    }

    yield new CoroutineResult($data);
}

function __parseCustomServerUri($uri) {
    if (!\is_string($uri)) {
        throw new ResolutionException(
            'Invalid server address ($uri must be a string IP address, ' . gettype($uri) . " given)"
        );
    }
    if (strpos($uri, "://") !== false) {
        return $uri;
    }
    if (($colonPos = strrpos(":", $uri)) !== false) {
        $addr = \substr($uri, 0, $colonPos);
        $port = \substr($uri, $colonPos);
    } else {
        $addr = $uri;
        $port = 53;
    }
    $addr = trim($addr, "[]");
    if (!$inAddr = @\inet_pton($addr)) {
        throw new ResolutionException(
            'Invalid server $uri; string IP address required'
        );
    }

    return isset($inAddr[4]) ? "udp://[{$addr}]:{$port}" : "udp://{$addr}:{$port}";
}

function __loadExistingServer($state, $uri) {
    if (empty($state->serverUriMap[$uri])) {
        return null;
    }

    $server = $state->serverUriMap[$uri];

    if (\is_resource($server->socket)) {
        unset($state->serverIdTimeoutMap[$server->id]);
        \Amp\enable($server->watcherId);
        return $server;
    }

    __unloadServer($state, $server->id);
    return null;
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
    $server->buffer = "";
    $server->length = INF;
    $server->pendingRequests = [];
    $server->watcherId = \Amp\onReadable($socket, 'Amp\Dns\__onReadable', [
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
        $server = $state->serverIdMap[$serverId];
        if (\substr($server->uri, 0, 6) == "tcp://") {
            if ($server->length == INF) {
                $server->length = unpack("n", $packet)[1];
                $packet = substr($packet, 2);
            }
            $server->buffer .= $packet;
            while ($server->length <= \strlen($server->buffer)) {
                __decodeResponsePacket($state, $serverId, substr($server->buffer, 0, $server->length));
                $server->buffer = substr($server->buffer, $server->length);
                if (\strlen($server->buffer) >= 2 + $server->length) {
                    $server->length = unpack("n", $server->buffer)[1];
                    $server->buffer = substr($server->buffer, 2);
                } else {
                    $server->length = INF;
                }
            }
        } else {
            __decodeResponsePacket($state, $serverId, $packet);
        }
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
            "Response decode error", 0, $e
        ));
    }
}

function __processDecodedResponse($state, $serverId, $requestId, $response) {
    list($promisor, $name, $type, $uri) = $state->pendingRequests[$requestId];

    // Retry via tcp if message has been truncated
    if ($response->isTruncated()) {
        if (\substr($uri, 0, 6) != "tcp://") {
            $uri = \preg_replace("#[a-z.]+://#", "tcp://", $uri);
            $promisor->succeed(__doRequest($state, $uri, $name, $type));
        } else {
            __finalizeResult($state, $serverId, $requestId, new ResolutionException(
                "Server returned truncated response"
            ));
        }
        return;
    }

    $answers = $response->getAnswerRecords();
    foreach ($answers as $record) {
        $result[$record->getType()][] = [(string) $record->getData(), $record->getType(), $record->getTTL()];
    }
    if (empty($result)) {
        $state->arrayCache->set("$name#$type", [], 300); // "it MUST NOT cache it for longer than five (5) minutes" per RFC 2308 section 7.1
        __finalizeResult($state, $serverId, $requestId, new NoRecordException(
            "No records returned for {$name}"
        ));
    } else {
        __finalizeResult($state, $serverId, $requestId, $error = null, $result);
    }
}

function __finalizeResult($state, $serverId, $requestId, $error = null, $result = null) {
    if (empty($state->pendingRequests[$requestId])) {
        return;
    }

    list($promisor, $name) = $state->pendingRequests[$requestId];
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
        foreach ($result as $type => $records) {
            $minttl = INF;
            foreach ($records as list( , $ttl)) {
                if ($ttl && $minttl > $ttl) {
                    $minttl = $ttl;
                }
            }
            $state->arrayCache->set("$name#$type", $records, $minttl);
        }
        $promisor->succeed($result);
    }
}
