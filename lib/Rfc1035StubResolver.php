<?php

namespace Amp\Dns;

use Amp\Cache\ArrayCache;
use Amp\Cache\Cache;
use Amp\Dns\Internal\Socket;
use Amp\Dns\Internal\TcpSocket;
use Amp\Dns\Internal\UdpSocket;
use Amp\Loop;
use Amp\MultiReasonException;
use Amp\Promise;
use Amp\Success;
use LibDNS\Messages\Message;
use LibDNS\Records\Question;
use LibDNS\Records\QuestionFactory;
use function Amp\call;

final class Rfc1035StubResolver implements Resolver
{
    const CACHE_PREFIX = "amphp.dns.";
    const CONFIG_NOT_LOADED = 0;
    const CONFIG_LOADED = 1;
    const CONFIG_FAILED = 2;

    /** @var ConfigLoader */
    private $configLoader;

    /** @var QuestionFactory */
    private $questionFactory;

    /** @var Config|null */
    private $config;

    /** @var int */
    private $configStatus = self::CONFIG_NOT_LOADED;

    /** @var Promise|null */
    private $pendingConfig;

    /** @var Cache */
    private $cache;

    /** @var Socket[] */
    private $sockets = [];

    /** @var Promise[] */
    private $pendingSockets = [];

    /** @var Promise[] */
    private $pendingQueries = [];

    /** @var string */
    private $gcWatcher;

    /** @var BlockingFallbackResolver */
    private $blockingFallbackResolver;

    public function __construct(Cache $cache = null, ConfigLoader $configLoader = null)
    {
        $this->cache = $cache ?? new ArrayCache(5000 /* default gc interval */, 256 /* size */);
        $this->configLoader = $configLoader ?? (\stripos(PHP_OS, "win") === 0
                ? new WindowsConfigLoader
                : new UnixConfigLoader);

        $this->questionFactory = new QuestionFactory;
        $this->blockingFallbackResolver = new BlockingFallbackResolver;

        $sockets = &$this->sockets;
        $this->gcWatcher = Loop::repeat(5000, static function () use (&$sockets) {
            if (!$sockets) {
                return;
            }

            $now = \time();

            foreach ($sockets as $key => $server) {
                if ($server->getLastActivity() < $now - 60) {
                    $server->close();
                    unset($sockets[$key]);
                }
            }
        });

        Loop::unreference($this->gcWatcher);
    }

    public function __destruct()
    {
        Loop::cancel($this->gcWatcher);
    }

    /** @inheritdoc */
    public function resolve(string $name, int $typeRestriction = null): Promise
    {
        if ($typeRestriction !== null && $typeRestriction !== Record::A && $typeRestriction !== Record::AAAA) {
            throw new \Error("Invalid value for parameter 2: null|Record::A|Record::AAAA expected");
        }

        return call(function () use ($name, $typeRestriction) {
            if ($this->configStatus === self::CONFIG_NOT_LOADED) {
                yield $this->reloadConfig();
            }

            if ($this->configStatus === self::CONFIG_FAILED) {
                return $this->blockingFallbackResolver->resolve($name, $typeRestriction);
            }

            switch ($typeRestriction) {
                case Record::A:
                    if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        return [new Record($name, Record::A, null)];
                    }

                    if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        throw new DnsException("Got an IPv6 address, but type is restricted to IPv4");
                    }
                    break;
                case Record::AAAA:
                    if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        return [new Record($name, Record::AAAA, null)];
                    }

                    if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        throw new DnsException("Got an IPv4 address, but type is restricted to IPv6");
                    }
                    break;
                default:
                    if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        return [new Record($name, Record::A, null)];
                    }

                    if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        return [new Record($name, Record::AAAA, null)];
                    }
                    break;
            }

            $name = normalizeName($name);

            if ($records = $this->queryHosts($name, $typeRestriction)) {
                return $records;
            }

            // Follow RFC 6761 and never send queries for localhost to the caching DNS server
            // Usually, these queries are already resolved via queryHosts()
            if ($name === 'localhost') {
                return $typeRestriction === Record::AAAA
                    ? [new Record('::1', Record::AAAA, null)]
                    : [new Record('127.0.0.1', Record::A, null)];
            }

            $dots = \substr_count($name, ".");
            // Should be replaced with $name[-1] from 7.1
            $trailingDot = \substr($name, -1, 1) === ".";

            if (!$dots && \count($this->config->getSearchList()) === 0) {
                throw new DnsException("Giving up resolution of '{$name}', unknown host");
            }

            $searchList = [null];
            if ($trailingDot) {
                $searchList = $this->config->getSearchList();
            } elseif ($dots < $this->config->getNdots()) {
                $searchList = \array_merge($this->config->getSearchList(), $searchList);
            }

            $searchName = $name;

            foreach ($searchList as $search) {
                for ($redirects = 0; $redirects < 5; $redirects++) {
                    if ($search !== null) {
                        $searchName = $trailingDot ? $name . $search : $name . "." . $search;
                    }

                    try {
                        if ($typeRestriction) {
                            return yield $this->query($searchName, $typeRestriction);
                        }

                        try {
                            list(, $records) = yield Promise\some([
                                $this->query($searchName, Record::A),
                                $this->query($searchName, Record::AAAA),
                            ]);

                            return \array_merge(...$records);
                        } catch (MultiReasonException $e) {
                            $errors = [];

                            foreach ($e->getReasons() as $reason) {
                                if ($reason instanceof NoRecordException) {
                                    throw $reason;
                                }

                                $errors[] = $reason->getMessage();
                            }

                            throw new DnsException(
                                "All query attempts failed for {$searchName}: " . \implode(", ", $errors),
                                0,
                                $e
                            );
                        }
                    } catch (NoRecordException $e) {
                        try {
                            /** @var Record[] $cnameRecords */
                            $cnameRecords = yield $this->query($searchName, Record::CNAME);
                            $name = $cnameRecords[0]->getValue();
                            continue;
                        } catch (NoRecordException $e) {
                            /** @var Record[] $dnameRecords */
                            $dnameRecords = yield $this->query($searchName, Record::DNAME);
                            $name = $dnameRecords[0]->getValue();
                            continue;
                        }
                    }
                }
            }

            throw new DnsException("Giving up resolution of '{$searchName}', too many redirects");
        });
    }

    /**
     * Reloads the configuration in the background.
     *
     * Once it's finished, the configuration will be used for new requests.
     *
     * @return Promise
     */
    public function reloadConfig(): Promise
    {
        if ($this->pendingConfig) {
            return $this->pendingConfig;
        }

        $promise = call(function () {
            try {
                $this->config = yield $this->configLoader->loadConfig();
                $this->configStatus = self::CONFIG_LOADED;
            } catch (ConfigException $e) {
                $this->configStatus = self::CONFIG_FAILED;

                try {
                    \trigger_error(
                        "Could not load the system's DNS configuration, using synchronous, blocking fallback",
                        \E_USER_WARNING
                    );
                } catch (\Throwable $triggerException) {
                    \set_error_handler(null);
                    \trigger_error(
                        "Could not load the system's DNS configuration, using synchronous, blocking fallback",
                        \E_USER_WARNING
                    );
                    \restore_error_handler();
                }
            }
        });

        $this->pendingConfig = $promise;

        $promise->onResolve(function () {
            $this->pendingConfig = null;
        });

        return $promise;
    }

    /** @inheritdoc */
    public function query(string $name, int $type): Promise
    {
        $pendingQueryKey = $type . " " . $name;

        if (isset($this->pendingQueries[$pendingQueryKey])) {
            return $this->pendingQueries[$pendingQueryKey];
        }

        $promise = call(function () use ($name, $type) {
            if ($this->configStatus === self::CONFIG_NOT_LOADED) {
                yield $this->reloadConfig();
            }
            if ($this->configStatus === self::CONFIG_FAILED) {
                return $this->blockingFallbackResolver->query($name, $type);
            }

            $name = $this->normalizeName($name, $type);
            $question = $this->createQuestion($name, $type);

            if (null !== $cachedValue = yield $this->cache->get($this->getCacheKey($name, $type))) {
                return $this->decodeCachedResult($name, $type, $cachedValue);
            }

            $nameservers = $this->config->getNameservers();
            $attempts = $this->config->getAttempts();
            $protocol = "udp";
            $attempt = 0;

            /** @var Socket $socket */
            $uri = $protocol . "://" . $nameservers[0];
            $socket = yield $this->getSocket($uri);

            $attemptDescription = [];

            while ($attempt < $attempts) {
                try {
                    if (!$socket->isAlive()) {
                        unset($this->sockets[$uri]);
                        $socket->close();

                        /** @var Socket $server */
                        $i = $attempt % \count($nameservers);
                        $uri = $protocol . "://" . $nameservers[$i];
                        $socket = yield $this->getSocket($uri);
                    }

                    $attemptDescription[] = $uri;

                    /** @var Message $response */
                    $response = yield $socket->ask($question, $this->config->getTimeout());
                    $this->assertAcceptableResponse($response);

                    // UDP sockets are never reused, they're not in the $this->sockets map
                    if ($protocol === "udp") {
                        // Defer call, because it interferes with the unreference() call in Internal\Socket otherwise
                        Loop::defer(static function () use ($socket) {
                            $socket->close();
                        });
                    }

                    if ($response->isTruncated()) {
                        if ($protocol !== "tcp") {
                            // Retry with TCP, don't count attempt
                            $protocol = "tcp";
                            $i = $attempt % \count($nameservers);
                            $uri = $protocol . "://" . $nameservers[$i];
                            $socket = yield $this->getSocket($uri);
                            continue;
                        }

                        throw new DnsException("Server returned a truncated response for '{$name}' (" . Record::getName($type) . ")");
                    }

                    $answers = $response->getAnswerRecords();
                    $result = [];
                    $ttls = [];

                    /** @var \LibDNS\Records\Resource $record */
                    foreach ($answers as $record) {
                        $recordType = $record->getType();
                        $result[$recordType][] = (string) $record->getData();

                        // Cache for max one day
                        $ttls[$recordType] = \min($ttls[$recordType] ?? 86400, $record->getTTL());
                    }

                    foreach ($result as $recordType => $records) {
                        // We don't care here whether storing in the cache fails
                        $this->cache->set(
                            $this->getCacheKey($name, $recordType),
                            \json_encode($records),
                            $ttls[$recordType]
                        );
                    }

                    if (!isset($result[$type])) {
                        // "it MUST NOT cache it for longer than five (5) minutes" per RFC 2308 section 7.1
                        $this->cache->set($this->getCacheKey($name, $type), \json_encode([]), 300);
                        throw new NoRecordException("No records returned for '{$name}' (" . Record::getName($type) . ")");
                    }

                    return \array_map(static function ($data) use ($type, $ttls) {
                        return new Record($data, $type, $ttls[$type]);
                    }, $result[$type]);
                } catch (TimeoutException $e) {
                    // Defer call, because it might interfere with the unreference() call in Internal\Socket otherwise
                    Loop::defer(function () use ($socket, $uri) {
                        unset($this->sockets[$uri]);
                        $socket->close();
                    });

                    $i = ++$attempt % \count($nameservers);
                    $uri = $protocol . "://" . $nameservers[$i];
                    $socket = yield $this->getSocket($uri);

                    continue;
                }
            }

            throw new TimeoutException(\sprintf(
                "No response for '%s' (%s) from any nameserver after %d attempts, tried %s",
                $name,
                Record::getName($type),
                $attempts,
                \implode(", ", $attemptDescription)
            ));
        });

        $this->pendingQueries[$type . " " . $name] = $promise;
        $promise->onResolve(function () use ($name, $type) {
            unset($this->pendingQueries[$type . " " . $name]);
        });

        return $promise;
    }

    private function queryHosts(string $name, int $typeRestriction = null): array
    {
        $hosts = $this->config->getKnownHosts();
        $records = [];

        $returnIPv4 = $typeRestriction === null || $typeRestriction === Record::A;
        $returnIPv6 = $typeRestriction === null || $typeRestriction === Record::AAAA;

        if ($returnIPv4 && isset($hosts[Record::A][$name])) {
            $records[] = new Record($hosts[Record::A][$name], Record::A, null);
        }

        if ($returnIPv6 && isset($hosts[Record::AAAA][$name])) {
            $records[] = new Record($hosts[Record::AAAA][$name], Record::AAAA, null);
        }

        return $records;
    }

    private function normalizeName(string $name, int $type)
    {
        if ($type === Record::PTR) {
            if (($packedIp = @\inet_pton($name)) !== false) {
                if (isset($packedIp[4])) { // IPv6
                    $name = \wordwrap(\strrev(\bin2hex($packedIp)), 1, ".", true) . ".ip6.arpa";
                } else { // IPv4
                    $name = \inet_ntop(\strrev($packedIp)) . ".in-addr.arpa";
                }
            }
        } elseif (\in_array($type, [Record::A, Record::AAAA], true)) {
            $name = normalizeName($name);
        }

        return $name;
    }

    /**
     * @param string $name
     * @param int    $type
     *
     * @return Question
     */
    private function createQuestion(string $name, int $type): Question
    {
        if (0 > $type || 0xffff < $type) {
            $message = \sprintf('%d does not correspond to a valid record type (must be between 0 and 65535).', $type);
            throw new \Error($message);
        }

        $question = $this->questionFactory->create($type);
        $question->setName($name);

        return $question;
    }

    private function getCacheKey(string $name, int $type): string
    {
        return self::CACHE_PREFIX . $name . "#" . $type;
    }

    private function decodeCachedResult(string $name, int $type, string $encoded): array
    {
        $decoded = \json_decode($encoded, true);

        if (!$decoded) {
            throw new NoRecordException("No records returned for {$name} (cached result)");
        }

        $result = [];

        foreach ($decoded as $data) {
            $result[] = new Record($data, $type);
        }

        return $result;
    }

    private function getSocket($uri): Promise
    {
        // We use a new socket for each UDP request, as that increases the entropy and mitigates response forgery.
        if (\substr($uri, 0, 3) === "udp") {
            return UdpSocket::connect($uri);
        }

        // Over TCP we might reuse sockets if the server allows to keep them open. Sequence IDs in TCP are already
        // better than a random port. Additionally, a TCP connection is more expensive.
        if (isset($this->sockets[$uri])) {
            return new Success($this->sockets[$uri]);
        }

        if (isset($this->pendingSockets[$uri])) {
            return $this->pendingSockets[$uri];
        }

        $server = TcpSocket::connect($uri);

        $server->onResolve(function ($error, $server) use ($uri) {
            unset($this->pendingSockets[$uri]);

            if (!$error) {
                $this->sockets[$uri] = $server;
            }
        });

        return $server;
    }

    private function assertAcceptableResponse(Message $response)
    {
        if ($response->getResponseCode() !== 0) {
            throw new DnsException(\sprintf("Server returned error code: %d", $response->getResponseCode()));
        }
    }
}
