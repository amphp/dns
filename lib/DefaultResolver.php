<?php

namespace Amp\Dns;

use Amp;
use Amp\Cache\ArrayCache;
use Amp\Cache\Cache;
use Amp\CallableMaker;
use Amp\Deferred;
use Amp\Failure;
use Amp\Loop;
use Amp\MultiReasonException;
use Amp\Promise;
use Amp\Success;
use Amp\TimeoutException;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\QuestionFactory;
use function Amp\call;

class DefaultResolver implements Resolver {
    use CallableMaker;

    const MAX_REQUEST_ID = 65536;
    const IDLE_TIMEOUT = 15000;
    const CACHE_PREFIX = "amphp.dns.";

    private $cache;
    private $configLoader;
    private $config;
    private $messageFactory;
    private $questionFactory;
    private $encoder;
    private $decoder;
    private $requestIdCounter;
    private $pendingRequests;
    private $serverIdMap;
    private $serverUriMap;
    private $serverIdTimeoutMap;
    private $now;
    private $serverTimeoutWatcher;

    public function __construct(Cache $cache = null, ConfigLoader $configLoader = null) {
        $this->cache = $cache ?? new ArrayCache;
        $this->configLoader = $configLoader ?? \stripos(PHP_OS, "win") === 0
                ? new WindowsConfigLoader
                : new UnixConfigLoader;

        $this->messageFactory = new MessageFactory;
        $this->questionFactory = new QuestionFactory;
        $this->encoder = (new EncoderFactory)->create();
        $this->decoder = (new DecoderFactory)->create();

        $this->requestIdCounter = 1;
        $this->pendingRequests = [];
        $this->serverIdMap = [];
        $this->serverUriMap = [];
        $this->serverIdTimeoutMap = [];
        $this->now = \time();

        $this->serverTimeoutWatcher = Loop::repeat(1000, function ($watcherId) {
            $this->now = $now = \time();

            foreach ($this->serverIdTimeoutMap as $id => $expiry) {
                if ($now > $expiry) {
                    $this->unloadServer($id);
                }
            }

            if (empty($this->serverIdMap)) {
                Loop::disable($watcherId);
            }
        });

        Loop::unreference($this->serverTimeoutWatcher);
    }

    /** @inheritdoc */
    public function resolve(string $name): Promise {
        if (!$inAddr = @\inet_pton($name)) {
            try {
                $name = normalizeDnsName($name);

                return call(function () use ($name) {
                    $result = yield from $this->recurseWithHosts($name, [Record::A, Record::AAAA], []);
                    return $this->flattenResult($result, [Record::A, Record::AAAA]);
                });
            } catch (InvalidNameError $e) {
                return new Failure(new ResolutionException("Cannot resolve invalid host name ({$name})", 0, $e));
            }
        }

        // It's already a valid IP, don't resolve, immediately return
        return new Success([new Record($name, isset($inAddr[4]) ? Record::AAAA : Record::A, $ttl = null)]);
    }

    /** @inheritdoc */
    public function query(string $name, $type): Promise {
        $types = (array) $type;

        return call(function () use ($name, $types) {
            $result = yield from $this->doResolve($name, $types);
            return $this->flattenResult($result, $types);
        });
    }

    public function reloadConfig(): Promise {
        return $this->loadConfig(true);
    }

    private function loadConfig(bool $forceReload = false): Promise {
        if ($this->config && !$forceReload) {
            return new Success($this->config);
        }

        $promise = $this->configLoader->loadConfig();
        $promise->onResolve(function ($error, $result) {
            if ($error) {
                return;
            }

            $this->config = $result;
        });

        return $promise;
    }

    // flatten $result while preserving order according to $types (append unspecified types for e.g. Record::ALL queries)
    private function flattenResult(array $result, array $types): array {
        $retval = [];

        foreach ($types as $type) {
            if (isset($result[$type])) {
                $retval = \array_merge($retval, $result[$type]);
                unset($result[$type]);
            }
        }

        $records = $result ? \array_merge($retval, \call_user_func_array("array_merge", $result)) : $retval;

        return array_map(function ($record) {
            return new Record($record[0], $record[1], $record[2]);
        }, $records);
    }

    private function recurseWithHosts($name, array $types) {
        /** @var Config $config */
        $config = yield $this->loadConfig();
        $hosts = $config->getKnownHosts();
        $result = [];

        if (\in_array(Record::A, $types) && isset($hosts[Record::A][$name])) {
            $result[Record::A] = [[$hosts[Record::A][$name], Record::A, $ttl = null]];
        }

        if (\in_array(Record::AAAA, $types) && isset($hosts[Record::AAAA][$name])) {
            $result[Record::AAAA] = [[$hosts[Record::AAAA][$name], Record::AAAA, $ttl = null]];
        }

        if ($result) {
            return $result;
        }

        return yield from $this->doRecurse($name, $types);
    }

    private function doRecurse($name, array $types) {
        if (\array_intersect($types, [Record::CNAME, Record::DNAME])) {
            throw new \Error("Cannot use recursion for CNAME and DNAME records");
        }

        $types = \array_merge($types, [Record::CNAME, Record::DNAME]);
        $lookupName = $name;

        for ($i = 0; $i < 30; $i++) {
            $result = yield from $this->doResolve($lookupName, $types);

            if (\count($result) > isset($result[Record::CNAME]) + isset($result[Record::DNAME])) {
                unset($result[Record::CNAME], $result[Record::DNAME]);
                return $result;
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
        $useTCP = \substr($uri, 0, 6) == "tcp://";

        if ($useTCP && isset($server->connect)) {
            return call(function () use ($server, $uri, $name, $type) {
                yield $server->connect;
                return $this->doRequest($uri, $name, $type);
            });
        }

        // Get the next available request ID
        do {
            $requestId = $this->requestIdCounter++;
            if ($this->requestIdCounter >= self::MAX_REQUEST_ID) {
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
            $requestPacket = \pack("n", \strlen($requestPacket)) . $requestPacket;
        }

        // Send request
        // FIXME: Fix might not write all bytes if TCP is used, as the buffer might be full
        $bytesWritten = @\fwrite($server->socket, $requestPacket);
        if ($bytesWritten === false || $bytesWritten === 0 && (!\is_resource($server->socket) || !\feof($server->socket))) {
            $exception = new ResolutionException("Request send failed");
            $this->unloadServer($server->id, $exception);
            throw $exception;
        }

        $deferred = new Deferred;
        $server->pendingRequests[$requestId] = true;
        $this->pendingRequests[$requestId] = [$deferred, $name, $type, $uri];

        return $deferred->promise();
    }

    private function doResolve($name, array $types) {
        /** @var Config $config */
        $config = yield $this->loadConfig();

        if (empty($types)) {
            return [];
        }

        \assert(
            \array_reduce($types, function ($result, $val) {
                return $result && \is_int($val);
            }, true),
            'The $types passed to DNS functions must all be integers (from \Amp\Dns\Record class)'
        );

        if (($packedIp = @inet_pton($name)) !== false) {
            if (isset($packedIp[4])) { // IPv6
                $name = wordwrap(strrev(bin2hex($packedIp)), 1, ".", true) . ".ip6.arpa";
            } else { // IPv4
                $name = inet_ntop(strrev($packedIp)) . ".in-addr.arpa";
            }
        }

        $name = normalizeDnsName($name);
        $result = [];

        // Check for cache hits
        foreach ($types as $k => $type) {
            $cacheValue = yield $this->cache->get($this->getCacheKey($name, $type));

            if ($cacheValue !== null) {
                $result[$type] = \json_decode($cacheValue, true);
                unset($types[$k]);
            }
        }

        if (empty($types)) {
            // TODO: Why do we use array_filter here?
            if (empty(array_filter($result))) {
                throw new NoRecordException("No records returned for {$name} (cached result)");
            }

            return $result;
        }

        $nameservers = $config->getNameservers();
        $attempts = $config->getAttempts();

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $i = $attempt % \count($nameservers);
            $uri = "udp://" . $nameservers[$i];

            $promises = [];
            foreach ($types as $type) {
                $promises[] = $this->doRequest($uri, $name, $type);
            }

            try {
                list(, $resultArr) = yield Promise\timeout(Promise\some($promises), $config->getTimeout());

                foreach ($resultArr as $value) {
                    $result += $value;
                }
            } catch (Amp\TimeoutException $e) {
                continue;
            } catch (ResolutionException $e) {
                if (empty($result)) { // if we have no cached results
                    throw $e;
                }
            } catch (MultiReasonException $e) { // if all promises in Amp\some fail
                if (empty($result)) { // if we have no cached results
                    foreach ($e->getReasons() as $ex) {
                        if ($ex instanceof NoRecordException) {
                            throw new NoRecordException("No records returned for {$name}", 0, $e);
                        }
                    }
                    throw new ResolutionException("All name resolution requests failed", 0, $e);
                }
            }

            return $result;
        }

        throw $e;
    }

    private function loadExistingServer($uri) {
        if (empty($this->serverUriMap[$uri])) {
            return null;
        }

        $server = $this->serverUriMap[$uri];

        if (\is_resource($server->socket)) {
            unset($this->serverIdTimeoutMap[$server->id]);
            Loop::enable($server->watcherId);
            return $server;
        }

        $this->unloadServer($server->id);
        return null;
    }

    private function loadNewServer($uri) {
        if (!$socket = @\stream_socket_client($uri, $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT)) {
            throw new ResolutionException(\sprintf(
                "Connection to %s failed: [Error #%d] %s",
                $uri,
                $errno,
                $errstr
            ));
        }

        \stream_set_blocking($socket, false);
        $id = (int) $socket;

        $server = new class {
            use Amp\Struct;
            public $id;
            public $uri;
            public $server;
            public $socket;
            public $buffer = "";
            public $length = INF;
            public $pendingRequests = [];
            public $watcherId;
            public $connect;
        };

        $server->id = $id;
        $server->uri = $uri;
        $server->socket = $socket;
        $server->pendingRequests = [];
        $server->watcherId = Loop::onReadable($socket, $this->callableFromInstanceMethod("onReadable"));
        Loop::disable($server->watcherId);

        $this->serverIdMap[$id] = $server;
        $this->serverUriMap[$uri] = $server;

        if (\substr($uri, 0, 6) == "tcp://") {
            $deferred = new Deferred;
            $server->connect = $deferred->promise();
            $watcher = Loop::onWritable($server->socket, static function ($watcher) use ($server, $deferred, &$timer) {
                Loop::cancel($watcher);
                Loop::cancel($timer);
                $server->connect = null;
                $deferred->resolve();
            });
            // TODO: Respect timeout
            $timer = Loop::delay(5000, function () use ($id, $deferred, $watcher, $uri) {
                Loop::cancel($watcher);
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
        Loop::cancel($server->watcherId);
        unset(
            $this->serverIdMap[$serverId],
            $this->serverUriMap[$server->uri]
        );
        if (\is_resource($server->socket)) {
            @\fclose($server->socket);
        }
        if ($error && $server->pendingRequests) {
            foreach (\array_keys($server->pendingRequests) as $requestId) {
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
                    $server->length = \unpack("n", $packet)[1];
                    $packet = \substr($packet, 2);
                }
                $server->buffer .= $packet;
                while ($server->length <= \strlen($server->buffer)) {
                    $this->decodeResponsePacket($serverId, \substr($server->buffer, 0, $server->length));
                    $server->buffer = substr($server->buffer, $server->length);
                    if (\strlen($server->buffer) >= 2 + $server->length) {
                        $server->length = \unpack("n", $server->buffer)[1];
                        $server->buffer = \substr($server->buffer, 2);
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
            // "it MUST NOT cache it for longer than five (5) minutes" per RFC 2308 section 7.1
            $this->cache->set($this->getCacheKey($name, $type), \json_encode([]), 300);
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
            $this->serverIdTimeoutMap[$server->id] = $this->now + self::IDLE_TIMEOUT;
            Loop::disable($server->watcherId);
            Loop::enable($this->serverTimeoutWatcher);
        }
        if ($error) {
            $deferred->fail($error);
        } else {
            foreach ($result as $type => $records) {
                $minttl = \PHP_INT_MAX;
                foreach ($records as list(, , $ttl)) {
                    if ($ttl < $minttl) {
                        $minttl = $ttl;
                    }
                }
                $this->cache->set(self::CACHE_PREFIX . "$name#$type", \json_encode($records), $minttl);
            }
            $deferred->resolve($result);
        }
    }

    private function getCacheKey(string $name, int $type): string {
        return self::CACHE_PREFIX . $name . "#" . $type;
    }
}
