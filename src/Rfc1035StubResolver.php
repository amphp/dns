<?php declare(strict_types=1);

namespace Amp\Dns;

use Amp\Cache\Cache;
use Amp\Cache\LocalCache;
use Amp\Cancellation;
use Amp\CompositeException;
use Amp\Dns\Internal\Socket;
use Amp\Dns\Internal\TcpSocket;
use Amp\Dns\Internal\UdpSocket;
use Amp\ForbidCloning;
use Amp\ForbidSerialization;
use Amp\Future;
use LibDNS\Messages\Message;
use LibDNS\Records\Question;
use LibDNS\Records\QuestionFactory;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\now;

final class Rfc1035StubResolver implements Resolver
{
    use ForbidCloning;
    use ForbidSerialization;

    public const CACHE_PREFIX = "amphp.dns.";

    private const CONFIG_NOT_LOADED = 0;
    private const CONFIG_LOADED = 1;
    private const CONFIG_FAILED = 2;

    private readonly DnsConfigLoader $configLoader;

    private readonly QuestionFactory $questionFactory;

    private ?DnsConfig $config = null;

    private int $configStatus = self::CONFIG_NOT_LOADED;

    private ?Future $pendingConfig = null;

    private readonly Cache $cache;

    /** @var Socket[] */
    private array $sockets = [];

    /** @var Future[] */
    private array $pendingSockets = [];

    /** @var Future[] */
    private array $pendingQueries = [];

    private readonly string $gcCallbackId;

    private readonly BlockingFallbackResolver $blockingFallbackResolver;

    private int $nextNameserver = 0;

    public function __construct(?Cache $cache = null, ?DnsConfigLoader $configLoader = null)
    {
        $this->cache = $cache ?? new LocalCache(256);
        $this->configLoader = $configLoader ?? (\PHP_OS_FAMILY === 'Windows'
                ? new WindowsDnsConfigLoader
                : new UnixDnsConfigLoader);

        $this->questionFactory = new QuestionFactory;
        $this->blockingFallbackResolver = new BlockingFallbackResolver;

        $sockets = &$this->sockets;
        $this->gcCallbackId = EventLoop::repeat(5, static function () use (&$sockets): void {
            if (!$sockets) {
                return;
            }

            $now = now();
            foreach ($sockets as $key => $server) {
                if ($server->getLastActivity() < $now - 60) {
                    $server->close();
                    unset($sockets[$key]);
                }
            }
        });

        EventLoop::unreference($this->gcCallbackId);
    }

    public function __destruct()
    {
        EventLoop::cancel($this->gcCallbackId);
    }

    /** @inheritdoc */
    public function resolve(string $name, int $typeRestriction = null, ?Cancellation $cancellation = null): array
    {
        if ($typeRestriction !== null && $typeRestriction !== Record::A && $typeRestriction !== Record::AAAA) {
            throw new \Error("Invalid value for parameter 2: null|Record::A|Record::AAAA expected");
        }

        if ($this->configStatus === self::CONFIG_NOT_LOADED) {
            $this->reloadConfig();
        }

        if ($this->configStatus === self::CONFIG_FAILED) {
            return $this->blockingFallbackResolver->resolve($name, $typeRestriction, $cancellation);
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

        $dots = \substr_count($name, ".");
        $trailingDot = $name[-1] === ".";
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

        \assert($this->config !== null);

        $searchList = [null];
        if (!$trailingDot && $dots < $this->config->getNdots()) {
            $searchList = \array_merge($this->config->getSearchList(), $searchList);
        }

        foreach ($searchList as $searchIndex => $search) {
            for ($redirects = 0; $redirects < 5; $redirects++) {
                $searchName = $name;

                if ($search !== null) {
                    $searchName = $name . "." . $search;
                }

                try {
                    if ($typeRestriction) {
                        return $this->query($searchName, $typeRestriction, $cancellation);
                    }

                    [$exceptions, $records] = Future\awaitAll([
                        async(fn () => $this->query($searchName, Record::A, $cancellation)),
                        async(fn () => $this->query($searchName, Record::AAAA, $cancellation)),
                    ]);

                    if (\count($exceptions) === 2) {
                        $errors = [];

                        foreach ($exceptions as $reason) {
                            if ($reason instanceof NoRecordException) {
                                throw $reason;
                            }

                            if ($searchIndex < \count($searchList) - 1 && \in_array($reason->getCode(), [2, 3], true)) {
                                continue 2;
                            }

                            $errors[] = $reason->getMessage();
                        }

                        throw new DnsException(
                            "All query attempts failed for {$searchName}: " . \implode(", ", $errors),
                            0,
                            new CompositeException($exceptions)
                        );
                    }

                    return \array_merge(...$records);
                } catch (NoRecordException) {
                    try {
                        $cnameRecords = $this->query($searchName, Record::CNAME, $cancellation);
                        $name = $cnameRecords[0]->getValue();
                        continue;
                    } catch (NoRecordException) {
                        $dnameRecords = $this->query($searchName, Record::DNAME, $cancellation);
                        $name = $dnameRecords[0]->getValue();
                        continue;
                    }
                } catch (DnsException $e) {
                    if ($searchIndex < \count($searchList) - 1 && \in_array($e->getCode(), [2, 3], true)) {
                        continue 2;
                    }

                    throw $e;
                }
            }
        }

        \assert(isset($searchName));

        throw new DnsException("Giving up resolution of '{$searchName}', too many redirects");
    }

    /**
     * Reloads the configuration in the background.
     *
     * Once it's finished, the configuration will be used for new requests.
     */
    public function reloadConfig(): DnsConfig
    {
        if ($this->pendingConfig) {
            return $this->pendingConfig->await();
        }

        $this->pendingConfig = async(function (): DnsConfig {
            try {
                $this->config = $this->configLoader->loadConfig();
                $this->configStatus = self::CONFIG_LOADED;
            } catch (ConfigException $e) {
                $this->configStatus = self::CONFIG_FAILED;

                $message = "Could not load the system's DNS configuration; "
                    . "falling back to synchronous, blocking resolver; "
                    . \get_class($e) . ": " . $e->getMessage();

                try {
                    \trigger_error(
                        $message,
                        \E_USER_WARNING
                    );
                } catch (\Throwable) {
                    \set_error_handler(null);
                    \trigger_error(
                        $message,
                        \E_USER_WARNING
                    );
                    \restore_error_handler();
                }
            } finally {
                $this->pendingConfig = null;
            }

            \assert($this->config !== null);

            return $this->config;
        });

        return $this->pendingConfig->await();
    }

    public function query(string $name, int $type, ?Cancellation $cancellation = null): array
    {
        $pendingQueryKey = $type . " " . $name;

        if (isset($this->pendingQueries[$pendingQueryKey])) {
            return $this->pendingQueries[$pendingQueryKey]->await($cancellation);
        }

        $future = async(function () use ($name, $type, $cancellation): array {
            try {
                if ($this->configStatus === self::CONFIG_NOT_LOADED) {
                    $this->reloadConfig();
                }

                if ($this->configStatus === self::CONFIG_FAILED) {
                    return $this->blockingFallbackResolver->query($name, $type, $cancellation);
                }

                \assert($this->config !== null);

                $name = $this->normalizeName($name, $type);
                $question = $this->createQuestion($name, $type);

                if (null !== $cachedValue = $this->cache->get($this->getCacheKey($name, $type))) {
                    if (!$cachedValue) {
                        throw new NoRecordException("No records returned for {$name} (cached result)");
                    }

                    $result = [];

                    foreach ($cachedValue as [$data, $type]) {
                        $result[] = new Record($data, $type);
                    }

                    return $result;
                }

                $nameservers = $this->selectNameservers();
                $nameserversCount = \count($nameservers);
                $attempts = $this->config->getAttempts();
                $protocol = "udp";
                $attempt = 0;

                /** @var Socket $socket */
                $uri = $protocol . "://" . $nameservers[0];
                $socket = $this->getSocket($uri);

                $attemptDescription = [];

                while ($attempt < $attempts) {
                    try {
                        if (!$socket->isAlive()) {
                            unset($this->sockets[$uri]);
                            $socket->close();

                            $uri = $protocol . "://" . $nameservers[$attempt % $nameserversCount];
                            $socket = $this->getSocket($uri);
                        }

                        $attemptDescription[] = $uri;

                        $response = $socket->ask($question, $this->config->getTimeout(), $cancellation);
                        $this->assertAcceptableResponse($response, $name);

                        // UDP sockets are never reused, they're not in the $this->sockets map
                        if ($protocol === "udp") {
                            $socket->close();
                        }

                        if ($response->isTruncated()) {
                            if ($protocol !== "tcp") {
                                // Retry with TCP, don't count attempt
                                $protocol = "tcp";
                                $uri = $protocol . "://" . $nameservers[$attempt % $nameserversCount];
                                $socket = $this->getSocket($uri);
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
                                \array_map(static fn (string $record) => [
                                    $record,
                                    $recordType
                                ], $records),
                                $ttls[$recordType]
                            );
                        }

                        if (!isset($result[$type])) {
                            // "it MUST NOT cache it for longer than five (5) minutes" per RFC 2308 section 7.1
                            $this->cache->set($this->getCacheKey($name, $type), [], 300);
                            throw new NoRecordException("No records returned for '{$name}' (" . Record::getName($type) . ")");
                        }

                        return \array_map(static function ($data) use ($type, $ttls) {
                            return new Record($data, $type, $ttls[$type]);
                        }, $result[$type]);
                    } catch (TimeoutException) {
                        unset($this->sockets[$uri]);
                        $socket->close();

                        $uri = $protocol . "://" . $nameservers[++$attempt % $nameserversCount];
                        $socket = $this->getSocket($uri);

                        continue;
                    }
                }

                throw new TimeoutException(\sprintf(
                    "No response for '%s' (%s) from any nameserver within %d ms after %d attempts, tried %s",
                    $name,
                    Record::getName($type),
                    $this->config->getTimeout(),
                    $attempts,
                    \implode(", ", $attemptDescription)
                ));
            } finally {
                unset($this->pendingQueries[$type . " " . $name]);
            }
        });

        $this->pendingQueries[$type . " " . $name] = $future;

        return $future->await($cancellation);
    }

    private function queryHosts(string $name, int $typeRestriction = null): array
    {
        \assert($this->config !== null);

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

    private function normalizeName(string $name, int $type): string
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

    private function getSocket(string $uri): Internal\Socket
    {
        // We use a new socket for each UDP request, as that increases the entropy and mitigates response forgery.
        if (\str_starts_with($uri, "udp")) {
            return UdpSocket::connect($uri);
        }

        // Over TCP we might reuse sockets if the server allows to keep them open. Sequence IDs in TCP are already
        // better than a random port. Additionally, a TCP connection is more expensive.
        if (isset($this->sockets[$uri])) {
            return $this->sockets[$uri];
        }

        if (isset($this->pendingSockets[$uri])) {
            return $this->pendingSockets[$uri]->await();
        }

        $future = async(function () use ($uri) {
            try {
                $socket = TcpSocket::connect($uri);
                $this->sockets[$uri] = $socket;
                return $socket;
            } finally {
                unset($this->pendingSockets[$uri]);
            }
        });

        $this->pendingSockets[$uri] = $future;

        return $future->await();
    }

    /**
     * @throws DnsException
     */
    private function assertAcceptableResponse(Message $response, string $name): void
    {
        if ($response->getResponseCode() !== 0) {
            // https://www.iana.org/assignments/dns-parameters/dns-parameters.xhtml
            $errors = [
                1 => 'FormErr',
                2 => 'ServFail',
                3 => 'NXDomain',
                4 => 'NotImp',
                5 => 'Refused',
                6 => 'YXDomain',
                7 => 'YXRRSet',
                8 => 'NXRRSet',
                9 => 'NotAuth',
                10 => 'NotZone',
                11 => 'DSOTYPENI',
                16 => 'BADVERS',
                17 => 'BADKEY',
                18 => 'BADTIME',
                19 => 'BADMODE',
                20 => 'BADNAME',
                21 => 'BADALG',
                22 => 'BADTRUNC',
                23 => 'BADCOOKIE',
            ];

            throw new DnsException(\sprintf(
                "Name resolution failed for '%s'; server returned error code: %d (%s)",
                $name,
                $response->getResponseCode(),
                $errors[$response->getResponseCode()] ?? 'UNKNOWN'
            ), $response->getResponseCode());
        }
    }

    private function selectNameservers(): array
    {
        \assert($this->config !== null);

        $nameservers = $this->config->getNameservers();

        if ($this->config->isRotationEnabled() && ($nameserversCount = \count($nameservers)) > 1) {
            $nameservers = \array_merge(
                \array_slice($nameservers, $this->nextNameserver),
                \array_slice($nameservers, 0, $this->nextNameserver)
            );
            $this->nextNameserver = ++$this->nextNameserver % $nameserversCount;
        }

        return $nameservers;
    }
}
