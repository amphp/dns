<?php

namespace Amp\Dns;

final class Config
{
    /** @var array */
    private $nameservers;
    /** @var array */
    private $knownHosts;
    /** @var int */
    private $timeout;
    /** @var int */
    private $attempts;
    /** @var array */
    private $searchList;
    /** @var int */
    private $ndots;
    /** @var bool */
    private $rotate;

    public function __construct(
        array $nameservers,
        array $knownHosts = [],
        int $timeout = 3000,
        int $attempts = 2,
        array $searchList = [],
        int $ndots = 1,
        bool $rotate = false
    ) {
        if (\count($nameservers) < 1) {
            throw new ConfigException("At least one nameserver is required for a valid config");
        }

        foreach ($nameservers as $nameserver) {
            $this->validateNameserver($nameserver);
        }

        if ($timeout < 0) {
            throw new ConfigException("Invalid timeout ({$timeout}), must be 0 or greater");
        }

        if ($attempts < 1) {
            throw new ConfigException("Invalid attempt count ({$attempts}), must be 1 or greater");
        }
        if ($ndots > 1 && \count($searchList) === 0) {
            throw new ConfigException("Invalid ndots count ({$ndots}), must be 1 if search list is empty");
        }
        if ($ndots < 1) {
            throw new ConfigException("Invalid ndots ({$timeout}), must be 1 or greater");
        }
        if ($ndots > 15) {
            $ndots = 15;
        }

        // Windows does not include localhost in its host file. Fetch it from the system instead
        if (!isset($knownHosts[Record::A]["localhost"]) && !isset($knownHosts[Record::AAAA]["localhost"])) {
            // PHP currently provides no way to **resolve** IPv6 hostnames (not even with fallback)
            $local = \gethostbyname("localhost");
            if ($local !== "localhost") {
                $knownHosts[Record::A]["localhost"] = $local;
            } else {
                $knownHosts[Record::AAAA]["localhost"] = '::1';
            }
        }

        $this->nameservers = $nameservers;
        $this->knownHosts = $knownHosts;
        $this->timeout = $timeout;
        $this->attempts = $attempts;
        $this->searchList = $searchList;
        $this->ndots = $ndots;
        $this->rotate = $rotate;
    }

    private function validateNameserver($nameserver)
    {
        if (!$nameserver || !\is_string($nameserver)) {
            throw new ConfigException("Invalid nameserver: {$nameserver}");
        }

        if ($nameserver[0] === "[") { // IPv6
            $addr = \strstr(\substr($nameserver, 1), "]", true);
            $port = \substr($nameserver, \strrpos($nameserver, "]") + 1);

            if ($port !== "" && !\preg_match("(^:(\\d+)$)", $port, $match)) {
                throw new ConfigException("Invalid nameserver: {$nameserver}");
            }

            $port = $port === "" ? 53 : \substr($port, 1);
        } else { // IPv4
            $arr = \explode(":", $nameserver, 2);

            if (\count($arr) === 2) {
                list($addr, $port) = $arr;
            } else {
                $addr = $arr[0];
                $port = 53;
            }
        }

        $addr = \trim($addr, "[]");
        $port = (int) $port;

        if (!$inAddr = @\inet_pton($addr)) {
            throw new ConfigException("Invalid server IP: {$addr}");
        }

        if ($port < 1 || $port > 65535) {
            throw new ConfigException("Invalid server port: {$port}");
        }
    }

    public function getNameservers(): array
    {
        return $this->nameservers;
    }

    public function getKnownHosts(): array
    {
        return $this->knownHosts;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getSearchList(): array
    {
        return $this->searchList;
    }

    public function getNdots(): int
    {
        return $this->ndots;
    }

    public function shouldRotate(): bool
    {
        return $this->rotate;
    }
}
