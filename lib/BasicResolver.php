<?php

namespace Amp\Dns;

use Amp\Cache\ArrayCache;
use Amp\Cache\Cache;
use Amp\Coroutine;
use Amp\Promise;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\Question;
use LibDNS\Records\QuestionFactory;

class BasicResolver implements Resolver {
    const CACHE_PREFIX = "amphp.dns.";

    /** @var \Amp\Dns\ConfigLoader */
    private $configLoader;

    /** @var \LibDNS\Records\QuestionFactory */
    private $questionFactory;

    /** @var \Amp\Dns\Config|null */
    private $config;

    /** @var Cache */
    private $cache;

    public function __construct(Cache $cache = null, ConfigLoader $configLoader = null) {
        $this->cache = $cache ?? new ArrayCache;
        $this->configLoader = $configLoader ?? \stripos(PHP_OS, "win") === 0
                ? new WindowsConfigLoader
                : new UnixConfigLoader;

        $this->questionFactory = new QuestionFactory;
    }

    /** @inheritdoc */
    public function resolve(string $name): Promise {
        // TODO: Implement resolve() method.
    }

    /** @inheritdoc */
    public function query(string $name, int $type): Promise {
        return new Coroutine($this->doQuery($name, $type));
    }

    public function doQuery(string $name, int $type): \Generator {
        if (!$this->config) {
            $this->config = yield $this->configLoader->loadConfig();
        }

        $name = $this->normalizeName($name, $type);
        $question = $this->createQuestion($name, $type);

        if (null !== $cachedValue = yield $this->cache->get($this->getCacheKey($name, $type))) {
            return $this->decodeCachedResult($name, $type, $cachedValue);
        }

        $nameservers = $this->config->getNameservers();
        $attempts = $this->config->getAttempts();

        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            $i = $attempt % \count($nameservers);
            $uri = "udp://" . $nameservers[$i];

            /** @var \Amp\Dns\Server $server */
            $server = yield UdpServer::connect($uri);

            /** @var \LibDNS\Messages\Message $response */
            $response = yield $server->ask($question);

            if ($response->getResponseCode() !== 0) {
                throw new ResolutionException(\sprintf("Server returned error code: %d", $response->getResponseCode()));
            }

            if ($response->getType() !== MessageTypes::RESPONSE) {
                throw new ResolutionException("Invalid server reply; expected RESPONSE but received QUERY");
            }

            if ($response->isTruncated()) {
                // TODO: Retry via TCP
            }

            $answers = $response->getAnswerRecords();
            $result = [];
            $ttls = [];

            /** @var \LibDNS\Records\Resource $record */
            foreach ($answers as $record) {
                $recordType = $record->getType();

                $result[$recordType][] = $record->getData();
                $ttls[$recordType] = \min($ttls[$recordType] ?? \PHP_INT_MAX, $record->getTTL());
            }

            foreach ($result as $recordType => $records) {
                // We don't care here whether storing in the cache fails
                $this->cache->set($this->getCacheKey($name, $recordType), \json_encode($records), $ttls[$recordType]);
            }

            if (!isset($result[$type])) {
                // "it MUST NOT cache it for longer than five (5) minutes" per RFC 2308 section 7.1
                $this->cache->set($this->getCacheKey($name, $type), \json_encode([]), 300);
                throw new NoRecordException("No records returned for {$name}");
            }

            return array_map(function ($data) use ($type, $ttls) {
                return new Record($data, $type, $ttls[$type]);
            }, $result[$type]);
        }

        throw new ResolutionException("No response from any nameserver after {$attempts} attempts");
    }

    /**
     * @param string $name
     * @param int    $type
     *
     * @return \LibDNS\Records\Question
     */
    private function createQuestion(string $name, int $type): Question {
        if (0 > $type || 0xffff < $type) {
            $message = \sprintf('%d does not correspond to a valid record type (must be between 0 and 65535).', $type);
            throw new \Error($message);
        }

        $question = $this->questionFactory->create($type);
        $question->setName($name);

        return $question;
    }

    private function getCacheKey(string $name, int $type): string {
        return self::CACHE_PREFIX . $name . "#" . $type;
    }

    private function decodeCachedResult(string $name, string $type, string $encoded) {
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

    private function normalizeName(string $name, int $type) {
        if ($type === Record::PTR) {
            if (($packedIp = @inet_pton($name)) !== false) {
                if (isset($packedIp[4])) { // IPv6
                    $name = wordwrap(strrev(bin2hex($packedIp)), 1, ".", true) . ".ip6.arpa";
                } else { // IPv4
                    $name = inet_ntop(strrev($packedIp)) . ".in-addr.arpa";
                }
            }
        } else if (\in_array($type, [Record::A, Record::AAAA])) {
            $name = normalizeDnsName($name);
        }

        return $name;
    }
}
