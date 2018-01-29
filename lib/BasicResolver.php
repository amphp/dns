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
use Amp\Uri\InvalidDnsNameException;
use DaveRandom\LibDNS\Protocol\Messages\Message;
use DaveRandom\LibDNS\Protocol\Messages\MessageResponseCodes;
use DaveRandom\LibDNS\Records\QuestionRecord;
use DaveRandom\LibDNS\Records\ResourceData;
use DaveRandom\Network\DomainName;
use DaveRandom\Network\IPAddress;
use DaveRandom\Network\IPv4Address;
use DaveRandom\Network\IPv6Address;
use function Amp\call;

final class BasicResolver implements Resolver {
    const CACHE_PREFIX = "amphp.dns.";
    const CACHE_UNSERIALIZE_ALLOWED_CLASSES = [
        IPv4Address::class, IPv6Address::class, DomainName::class,
        ResourceData\UnknownResourceData::class,
        ResourceData\A::class,
        ResourceData\AAAA::class,
        ResourceData\CNAME::class,
        ResourceData\DNAME::class,
        ResourceData\MX::class,
        ResourceData\NAPTR::class,
        ResourceData\NS::class,
        ResourceData\PTR::class,
        ResourceData\RP::class,
        ResourceData\SOA::class,
        ResourceData\SRV::class,
        ResourceData\TXT::class,
    ];

    /** @var \Amp\Dns\ConfigLoader */
    private $configLoader;

    /** @var \Amp\Dns\Config|null */
    private $config;

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

    public function __construct(Cache $cache = null, ConfigLoader $configLoader = null) {
        $this->cache = $cache ?? new ArrayCache(5000 /* default gc interval */, 256 /* size */);
        $this->configLoader = $configLoader ?? (\stripos(PHP_OS, "win") === 0
                ? new WindowsConfigLoader
                : new UnixConfigLoader);

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

    public function __destruct() {
        Loop::cancel($this->gcWatcher);
    }

    private function createRecordFromIpAddress($address, int $typeRestriction = null): Record {
        if (!$address instanceof IPAddress) {
            $address = IPAddress::parse($address);
        }

        if ($address instanceof IPv4Address) {
            if ($typeRestriction === Record::AAAA) {
                throw new ResolutionException("Got an IPv4 address, but type is restricted to IPv6");
            }

            return new Record(new ResourceData\A($address), Record::A);
        }

        if ($address instanceof IPv6Address) {
            if ($typeRestriction === Record::A) {
                throw new ResolutionException("Got an IPv6 address, but type is restricted to IPv4");
            }

            return new Record(new ResourceData\AAAA($address), Record::AAAA);
        }

        throw new ResolutionException("Got a valid IP address, but no known record type is associated with it");
    }

    /** @inheritdoc */
    public function resolve($name, int $typeRestriction = null): Promise {
        if ($typeRestriction !== null && $typeRestriction !== Record::A && $typeRestriction !== Record::AAAA) {
            throw new \Error("Invalid value for parameter 2: null|Record::A|Record::AAAA expected");
        }

        return call(function () use ($name, $typeRestriction) {
            if (!$this->config) {
                yield $this->reloadConfig();
            }

            if (!$name instanceof DomainName) {
                try {
                    // If it's already a valid IP, don't query, immediately return
                    return [$this->createRecordFromIpAddress($name, $typeRestriction)];
                } catch (\InvalidArgumentException $e) {
                    // Ignore failure and continue to query server
                }
            }

            try {
                $name = DomainName::createFromString($name, true);
            } catch (\InvalidArgumentException $e) {
                throw new InvalidDnsNameException($e->getMessage(), 0, $e);
            }

            if ($records = $this->queryHosts($name, $typeRestriction)) {
                return $records;
            }

            for ($redirects = 0; $redirects < 5; $redirects++) {
                try {
                    if ($typeRestriction) {
                        $records = yield $this->query($name, $typeRestriction);
                    } else {
                        try {
                            list(, $records) = yield Promise\some([
                                $this->query($name, Record::A),
                                $this->query($name, Record::AAAA),
                            ]);

                            $records = \array_merge(...$records);

                            break; // Break redirect loop, otherwise we query the same records 5 times
                        } catch (MultiReasonException $e) {
                            foreach ($e->getReasons() as $reason) {
                                if ($reason instanceof NoRecordException) {
                                    throw $reason;
                                }
                            }

                            throw new ResolutionException("All query attempts failed", 0, $e);
                        }
                    }
                } catch (NoRecordException $e) {
                    try {
                        /** @var Record[] $cnameRecords */
                        $cnameRecords = yield $this->query($name, Record::CNAME);
                        /** @var ResourceData\CNAME $cname */
                        $cname = $cnameRecords[0]->getValue();
                        $name = $cname->getCanonicalName();
                        continue;
                    } catch (NoRecordException $e) {
                        /** @var Record[] $dnameRecords */
                        $dnameRecords = yield $this->query($name, Record::DNAME);
                        $name = $dnameRecords[0]->getValue();
                        continue;
                    }
                }
            }

            return $records;
        });
    }

    private function queryHosts(DomainName $name, int $typeRestriction = null): array {
        $hosts = $this->config->getKnownHosts();
        $records = [];

        $returnIPv4 = $typeRestriction === null || $typeRestriction === Record::A;
        $returnIPv6 = $typeRestriction === null || $typeRestriction === Record::AAAA;

        if ($returnIPv4 && null !== $address = $hosts->getAddressForName($name, Record::A)) {
            /** @var IPv4Address $address */
            $records[] = new Record(new ResourceData\A($address), Record::A);
        }

        if ($returnIPv6 && null !== $address = $hosts->getAddressForName($name, Record::AAAA)) {
            /** @var IPv6Address $address */
            $records[] = new Record(new ResourceData\AAAA($address), Record::AAAA);
        }

        return $records;
    }

    /** @inheritdoc */
    public function query($name, int $type): Promise {
        $pendingQueryKey = $type . " " . $name;

        if (isset($this->pendingQueries[$pendingQueryKey])) {
            return $this->pendingQueries[$pendingQueryKey];
        }

        $promise = call(function () use ($name, $type) {
            if (!$this->config) {
                yield $this->reloadConfig();
            }

            if (!$name instanceof DomainName) {
                $name = $this->normalizeNameString($name, $type);
            }
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

            while ($attempt < $attempts) {
                try {
                    if (!$socket->isAlive()) {
                        unset($this->sockets[$uri]);
                        $socket->close();

                        /** @var Socket $server */
                        $i = $attempt % \count($nameservers);
                        $socket = yield $this->getSocket($protocol . "://" . $nameservers[$i]);
                    }

                    /** @var Message $response */
                    $response = yield $socket->ask($question, $this->config->getTimeout());
                    $this->assertAcceptableResponse($response);

                    // UDP sockets are never reused, they're not in the $this->sockets map
                    if ($protocol === "udp") {
                        // Defer call, because it interferes with the unreference() call in Internal\Socket otherwise
                        Loop::defer(function () use ($socket) {
                            $socket->close();
                        });
                    }

                    if ($response->isTruncated()) {
                        if ($protocol !== "tcp") {
                            // Retry with TCP, don't count attempt
                            $protocol = "tcp";
                            $i = $attempt % \count($nameservers);
                            $socket = yield $this->getSocket($protocol . "://" . $nameservers[$i]);
                            continue;
                        }

                        throw new ResolutionException("Server returned truncated response");
                    }

                    $answers = $response->getAnswerRecords();
                    $result = [];
                    $ttls = [];

                    foreach ($answers as $record) {
                        $recordType = $record->getType();
                        $result[$recordType][] = $record->getData();

                        // Cache for max one day
                        $ttls[$recordType] = \min($ttls[$recordType] ?? 86400, $record->getTTL());
                    }

                    foreach ($result as $recordType => $records) {
                        // We don't care here whether storing in the cache fails
                        $this->cache->set($this->getCacheKey($name, $recordType), \serialize($records), $ttls[$recordType]);
                    }

                    if (!isset($result[$type])) {
                        // "it MUST NOT cache it for longer than five (5) minutes" per RFC 2308 section 7.1
                        $this->cache->set($this->getCacheKey($name, $type), \json_encode([]), 300);
                        throw new NoRecordException("No records returned for {$name}");
                    }

                    return \array_map(function ($data) use ($type, $ttls) {
                        return new Record($data, $type, $ttls[$type]);
                    }, $result[$type]);
                } catch (TimeoutException $e) {
                    // Defer call, because it might interfere with the unreference() call in Internal\Socket otherwise
                    Loop::defer(function () use ($socket, $uri) {
                        unset($this->sockets[$uri]);
                        $socket->close();
                    });

                    $i = ++$attempt % \count($nameservers);
                    $socket = yield $this->getSocket($protocol . "://" . $nameservers[$i]);

                    continue;
                }
            }

            throw new TimeoutException("No response from any nameserver after {$attempts} attempts");
        });

        $this->pendingQueries[$type . " " . $name] = $promise;
        $promise->onResolve(function () use ($name, $type) {
            unset($this->pendingQueries[$type . " " . $name]);
        });

        return $promise;
    }

    /**
     * Reloads the configuration in the background.
     *
     * Once it's finished, the configuration will be used for new requests.
     *
     * @return Promise
     */
    public function reloadConfig(): Promise {
        if ($this->pendingConfig) {
            return $this->pendingConfig;
        }

        $promise = call(function () {
            $this->config = yield $this->configLoader->loadConfig();
        });

        $this->pendingConfig = $promise;

        $promise->onResolve(function () {
            $this->pendingConfig = null;
        });

        return $promise;
    }

    /**
     * @param DomainName $name
     * @param int        $type
     *
     * @return \DaveRandom\LibDNS\Records\QuestionRecord
     */
    private function createQuestion(DomainName $name, int $type): QuestionRecord {
        if (0 > $type || 0xffff < $type) {
            $message = \sprintf('%d does not correspond to a valid record type (must be between 0 and 65535).', $type);
            throw new \Error($message);
        }

        return new QuestionRecord($name, $type);
    }

    private function getCacheKey(DomainName $name, int $type): string {
        return self::CACHE_PREFIX . $name . "#" . $type;
    }

    private function decodeCachedResult(DomainName $name, string $type, string $encoded) {
        $decoded = \unserialize($encoded, ['allowed_classes' => self::CACHE_UNSERIALIZE_ALLOWED_CLASSES]);

        if (!$decoded) {
            throw new NoRecordException("No records returned for {$name} (cached result)");
        }

        $result = [];

        foreach ($decoded as $data) {
            $result[] = new Record($data, $type);
        }

        return $result;
    }

    private function normalizeNameString(string $name, int $type): DomainName {
        try {
            if ($type !== Record::PTR) {
                $strict = \in_array($type, [Record::A, Record::AAAA, Record::CNAME, Record::DNAME]);
                return DomainName::createFromString($name, $strict);
            }

            if (!$name instanceof IPAddress) {
                $name = IPAddress::parse($name);
            }

            return \DaveRandom\LibDNS\ipaddress_to_ptr_name($name);
        } catch (\InvalidArgumentException $e) {
            $type = Record::getName($type);
            throw new InvalidDnsNameException("Invalid name for lookup of type {$type}: {$name}");
        }
    }

    private function getSocket($uri): Promise {
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

    private function assertAcceptableResponse(Message $response) {
        if ($response->getResponseCode() !== MessageResponseCodes::NO_ERROR) {
            try {
                $errorDescription = MessageResponseCodes::parseValue($response->getResponseCode());
            } catch (\InvalidArgumentException $e) {
                $errorDescription = 'Unknown error';
            }

            throw new ResolutionException(\sprintf(
                "Server returned error code: %d: %s",
                $response->getResponseCode(),
                $errorDescription
            ));
        }
    }
}
