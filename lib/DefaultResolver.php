<?php

namespace Amp\Dns;

use Amp\Cache\ArrayCache;
use Amp\MultiReasonException;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Failure;
use Amp\File\FilesystemException;
use Amp\Success;
use Amp\TimeoutException;
use Interop\Async\Loop;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\QuestionFactory;

class DefaultResolver implements Resolver {
    private $messageFactory;
    private $questionFactory;
    private $encoder;
    private $decoder;
    private $arrayCache;
    private $requestIdCounter;
    private $pendingRequests;
    private $serverIdMap;
    private $serverUriMap;
    private $serverIdTimeoutMap;
    private $now;
    private $serverTimeoutWatcher;
    private $config;

    public function __construct() {
        $this->messageFactory = new MessageFactory;
        $this->questionFactory = new QuestionFactory;
        $this->encoder = (new EncoderFactory)->create();
        $this->decoder = (new DecoderFactory)->create();
        $this->arrayCache = new ArrayCache;
        $this->requestIdCounter = 1;
        $this->pendingRequests = [];
        $this->serverIdMap = [];
        $this->serverUriMap = [];
        $this->serverIdTimeoutMap = [];
        $this->now = \time();
        $this->serverTimeoutWatcher = \Amp\repeat(1000, function ($watcherId) {
            $this->now = $now = \time();
            foreach ($this->serverIdTimeoutMap as $id => $expiry) {
                if ($now > $expiry) {
                    $this->unloadServer($id);
                }
            }
            if (empty($this->serverIdMap)) {
                \Amp\disable($watcherId);
            }
        });
        \Amp\unreference($this->serverTimeoutWatcher);
    }

    /**
     * {@inheritdoc}
     */
    public function resolve($name, array $options = []) {
        if (!$inAddr = @\inet_pton($name)) {
            if ($this->isValidHostName($name)) {
                $types = empty($options["types"]) ? [Record::A, Record::AAAA] : (array) $options["types"];
                return $this->pipeResult($this->recurseWithHosts($name, $types, $options), $types);
            } else {
                return new Failure(new ResolutionException("Cannot resolve; invalid host name"));
            }
        } else {
            return new Success([[$name, isset($inAddr[4]) ? Record::AAAA : Record::A, $ttl = null]]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function query($name, $type, array $options = []) {
        $handler = [$this, empty($options["recurse"]) ?  "doResolve" : "doRecurse"];
        $types = (array) $type;
        return $this->pipeResult(new Coroutine($handler($name, $types, $options)), $types);
    }

    private function isValidHostName($name) {
        $pattern = <<<'REGEX'
/^(?<name>[a-z0-9]([a-z0-9-]*[a-z0-9])?)(\.(?&name))*$/i
REGEX;

        return !isset($name[253]) && \preg_match($pattern, $name);
    }

    // flatten $result while preserving order according to $types (append unspecified types for e.g. Record::ALL queries)
    private function pipeResult($awaitable, array $types) {
        return \Amp\pipe($awaitable, function (array $result) use ($types) {
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

    private function recurseWithHosts($name, array $types, $options) {
        // Check for hosts file matches
        if (!isset($options["hosts"]) || $options["hosts"]) {
            static $hosts = null;
            if ($hosts === null || !empty($options["reload_hosts"])) {
                return \Amp\pipe(new Coroutine($this->loadHostsFile()), function ($value) use (&$hosts, $name, $types, $options) {
                    unset($options["reload_hosts"]); // avoid recursion
                    $hosts = $value;
                    return $this->recurseWithHosts($name, $types, $options);
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

        return new Coroutine($this->doRecurse($name, $types, $options));
    }

    private function doRecurse($name, array $types, $options) {
        if (array_intersect($types, [Record::CNAME, Record::DNAME])) {
            throw new ResolutionException("Cannot use recursion for CNAME and DNAME records");
        }

        $types = array_merge($types, [Record::CNAME, Record::DNAME]);
        $lookupName = $name;
        for ($i = 0; $i < 30; $i++) {
            $result = (yield new Coroutine($this->doResolve($lookupName, $types, $options)));
            if (count($result) > isset($result[Record::CNAME]) + isset($result[Record::DNAME])) {
                unset($result[Record::CNAME], $result[Record::DNAME]);
                yield Coroutine::result($result);
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

    private function doRequest($uri, $name, $type) {
        $server = $this->loadExistingServer($uri) ?: $this->loadNewServer($uri);

        $useTCP = substr($uri, 0, 6) == "tcp://";
        if ($useTCP && isset($server->connect)) {
            return \Amp\pipe($server->connect, function() use ($uri, $name, $type) {
                return $this->doRequest($uri, $name, $type);
            });
        }

        // Get the next available request ID
        do {
            $requestId = $this->requestIdCounter++;
            if ($this->requestIdCounter >= MAX_REQUEST_ID) {
                $this->requestIdCounter = 1;
            }
        } while (isset($this->pendingRequests[$requestId]));

        // Create question record
        $question = $this->questionFactory->create($type);
        $question->setName($name);

        // Create request message
        $request = $this->messageFactory->create(MessageTypes::QUERY);
        $request->getQuestionRecords()->add($question);
        $request->isRecursionDesired(true);
        $request->setID($requestId);

        // Encode request message
        $requestPacket = $this->encoder->encode($request);

        if ($useTCP) {
            $requestPacket = pack("n", strlen($requestPacket)) . $requestPacket;
        }

        // Send request
        $bytesWritten = \fwrite($server->socket, $requestPacket);
        if ($bytesWritten === false || isset($packet[$bytesWritten])) {
            throw new ResolutionException(
                "Request send failed"
            );
        }

        $deferred = new Deferred;
        $server->pendingRequests[$requestId] = true;
        $this->pendingRequests[$requestId] = [$deferred, $name, $type, $uri];

        return $deferred->getAwaitable();
    }

    private function doResolve($name, array $types, $options) {
        if (!$this->config) {
            $this->config = (yield new Coroutine($this->loadResolvConf()));
        }

        if (empty($types)) {
            yield Coroutine::result([]);
            return;
        }

        assert(array_reduce($types, function ($result, $val) { return $result && \is_int($val); }, true), 'The $types passed to DNS functions must all be integers (from \Amp\Dns\Record class)');

        $name = \strtolower($name);
        $result = [];

        // Check for cache hits
        if (!isset($options["cache"]) || $options["cache"]) {
            foreach ($types as $k => $type) {
                $cacheKey = "$name#$type";
                $cacheValue = (yield $this->arrayCache->get($cacheKey));

                if ($cacheValue !== null) {
                    $result[$type] = $cacheValue;
                    unset($types[$k]);
                }
            }
            if (empty($types)) {
                if (empty(array_filter($result))) {
                    throw new NoRecordException("No records returned for {$name} (cached result)");
                } else {
                    yield Coroutine::result($result);
                    return;
                }
            }
        }

        $timeout = empty($options["timeout"]) ? $this->config["timeout"] : (int) $options["timeout"];

        if (empty($options["server"])) {
            if (empty($this->config["nameservers"])) {
                throw new ResolutionException("No nameserver specified in system config");
            }

            $uri = "udp://" . $this->config["nameservers"][0];
        } else {
            $uri = $this->parseCustomServerUri($options["server"]);
        }

        foreach ($types as $type) {
            $awaitables[] = $this->doRequest($uri, $name, $type);
        }

        try {
            list( , $resultArr) = (yield \Amp\timeout(\Amp\some($awaitables), $timeout));
            foreach ($resultArr as $value) {
                $result += $value;
            }
        } catch (TimeoutException $e) {
            if (substr($uri, 0, 6) == "tcp://") {
                throw new TimeoutException(
                    "Name resolution timed out for {$name}"
                );
            } else {
                $options["server"] = \preg_replace("#[a-z.]+://#", "tcp://", $uri);
                yield Coroutine::result(\Amp\coroutine($this->doResolve($name, $types, $options)));
                return;
            }
        } catch (ResolutionException $e) {
            if (empty($result)) { // if we have no cached results
                throw $e;
            }
        } catch (MultiReasonException $e) { // if all promises in Amp\some fail
            if (empty($result)) { // if we have no cached results
                foreach ($e->getExceptions() as $ex) {
                    if ($ex instanceof NoRecordException) {
                        throw new NoRecordException("No records returned for {$name}", 0, $e);
                    }
                }
                throw new ResolutionException("All name resolution requests failed", 0, $e);
            }
        }

        yield Coroutine::result($result);
    }

    /** @link http://man7.org/linux/man-pages/man5/resolv.conf.5.html */
    private function loadResolvConf($path = null) {
        $result = [
            "nameservers" => [
                "8.8.8.8:53",
                "8.8.4.4:53",
            ],
            "timeout" => 3000,
            "attempts" => 2,
        ];

        if (\stripos(PHP_OS, "win") !== 0 || $path !== null) {
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
            } catch (FilesystemException $e) {
                // use default
            }
        }

        yield Coroutine::result($result);
    }

    private function loadHostsFile($path = null) {
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
                if ($this->isValidHostName($parts[$i])) {
                    $data[$key][strtolower($parts[$i])] = $parts[0];
                }
            }
        }

        // Windows does not include localhost in its host file. Fetch it from the system instead
        if (!isset($data[Record::A]["localhost"]) && !isset($data[Record::AAAA]["localhost"])) {
            // PHP currently provides no way to **resolve** IPv6 hostnames (not even with fallback)
            $local = gethostbyname("localhost");
            if ($local !== "localhost") {
                $data[Record::A]["localhost"] = $local;
            } else {
                $data[Record::AAAA]["localhost"] = "::1";
            }
        }

        yield Coroutine::result($data);
    }

    private function parseCustomServerUri($uri) {
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

    private function loadExistingServer($uri) {
        if (empty($this->serverUriMap[$uri])) {
            return null;
        }

        $server = $this->serverUriMap[$uri];

        if (\is_resource($server->socket)) {
            unset($this->serverIdTimeoutMap[$server->id]);
            \Amp\enable($server->watcherId);
            return $server;
        }

        $this->unloadServer($server->id);
        return null;
    }

    private function loadNewServer($uri) {
        if (!$socket = @\stream_socket_client($uri, $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT)) {
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
        $server->watcherId = \Amp\onReadable($socket, $this->makePrivateCallable("onReadable"));
        \Amp\unreference($server->watcherId);
        $this->serverIdMap[$id] = $server;
        $this->serverUriMap[$uri] = $server;

        if (substr($uri, 0, 6) == "tcp://") {
            $deferred = new Deferred;
            $server->connect = $deferred->getAwaitable();
            $watcher = \Amp\onWritable($server->socket, static function($watcher) use ($server, $deferred, &$timer) {
                \Amp\cancel($watcher);
                \Amp\cancel($timer);
                unset($server->connect);
                $deferred->resolve();
            });
            $timer = \Amp\delay(5000, function() use ($id, $deferred, $watcher, $uri) {
                \Amp\cancel($watcher);
                $this->unloadServer($id);
                $deferred->fail(new TimeoutException("Name resolution timed out, could not connect to server at $uri"));
            });
        }

        return $server;
    }

    private function unloadServer($serverId, $error = null) {
        // Might already have been unloaded (especially if multiple requests happen)
        if (!isset($this->serverIdMap[$serverId])) {
            return;
        }

        $server = $this->serverIdMap[$serverId];
        \Amp\cancel($server->watcherId);
        unset(
            $this->serverIdMap[$serverId],
            $this->serverUriMap[$server->uri]
        );
        if (\is_resource($server->socket)) {
            @\fclose($server->socket);
        }
        if ($error && $server->pendingRequests) {
            foreach (array_keys($server->pendingRequests) as $requestId) {
                list($deferred) = $this->pendingRequests[$requestId];
                $deferred->fail($error);
            }
        }
    }

    private function onReadable($watcherId, $socket) {
        $serverId = (int) $socket;
        $packet = @\fread($socket, 512);
        if ($packet != "") {
            $server = $this->serverIdMap[$serverId];
            if (\substr($server->uri, 0, 6) == "tcp://") {
                if ($server->length == INF) {
                    $server->length = unpack("n", $packet)[1];
                    $packet = substr($packet, 2);
                }
                $server->buffer .= $packet;
                while ($server->length <= \strlen($server->buffer)) {
                    $this->decodeResponsePacket($serverId, substr($server->buffer, 0, $server->length));
                    $server->buffer = substr($server->buffer, $server->length);
                    if (\strlen($server->buffer) >= 2 + $server->length) {
                        $server->length = unpack("n", $server->buffer)[1];
                        $server->buffer = substr($server->buffer, 2);
                    } else {
                        $server->length = INF;
                    }
                }
            } else {
                $this->decodeResponsePacket($serverId, $packet);
            }
        } else {
            $this->unloadServer($serverId, new ResolutionException(
                "Server connection failed"
            ));
        }
    }

    private function decodeResponsePacket($serverId, $packet) {
        try {
            $response = $this->decoder->decode($packet);
            $requestId = $response->getID();
            $responseCode = $response->getResponseCode();
            $responseType = $response->getType();

            if ($responseCode !== 0) {
                $this->finalizeResult($serverId, $requestId, new ResolutionException(
                    "Server returned error code: {$responseCode}"
                ));
            } elseif ($responseType !== MessageTypes::RESPONSE) {
                $this->unloadServer($serverId, new ResolutionException(
                    "Invalid server reply; expected RESPONSE but received QUERY"
                ));
            } else {
                $this->processDecodedResponse($serverId, $requestId, $response);
            }
        } catch (\Exception $e) {
            $this->unloadServer($serverId, new ResolutionException(
                "Response decode error", 0, $e
            ));
        }
    }

    private function processDecodedResponse($serverId, $requestId, $response) {
        list($deferred, $name, $type, $uri) = $this->pendingRequests[$requestId];

        // Retry via tcp if message has been truncated
        if ($response->isTruncated()) {
            if (\substr($uri, 0, 6) != "tcp://") {
                $uri = \preg_replace("#[a-z.]+://#", "tcp://", $uri);
                $deferred->resolve($this->doRequest($uri, $name, $type));
            } else {
                $this->finalizeResult($serverId, $requestId, new ResolutionException(
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
            $this->arrayCache->set("$name#$type", [], 300); // "it MUST NOT cache it for longer than five (5) minutes" per RFC 2308 section 7.1
            $this->finalizeResult($serverId, $requestId, new NoRecordException(
                "No records returned for {$name}"
            ));
        } else {
            $this->finalizeResult($serverId, $requestId, $error = null, $result);
        }
    }

    private function finalizeResult($serverId, $requestId, $error = null, $result = null) {
        if (empty($this->pendingRequests[$requestId])) {
            return;
        }

        list($deferred, $name) = $this->pendingRequests[$requestId];
        $server = $this->serverIdMap[$serverId];
        unset(
            $this->pendingRequests[$requestId],
            $server->pendingRequests[$requestId]
        );
        if (empty($server->pendingRequests)) {
            $this->serverIdTimeoutMap[$server->id] = $this->now + IDLE_TIMEOUT;
            \Amp\disable($server->watcherId);
            \Amp\enable($this->serverTimeoutWatcher);
        }
        if ($error) {
            $deferred->fail($error);
        } else {
            foreach ($result as $type => $records) {
                $minttl = INF;
                foreach ($records as list( , $ttl)) {
                    if ($ttl && $minttl > $ttl) {
                        $minttl = $ttl;
                    }
                }
                $this->arrayCache->set("$name#$type", $records, $minttl);
            }
            $deferred->resolve($result);
        }
    }

    private function makePrivateCallable($method) {
        return (new \ReflectionClass($this))->getMethod($method)->getClosure($this);
    }
}
