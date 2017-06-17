<?php

namespace Amp\Dns;

class Config {
    private $nameservers;
    private $knownHosts;
    private $timeout;
    private $attempts;

    public function __construct(array $nameservers, array $knownHosts = [], int $timeout = 3000, int $attempts = 2) {
        if (\count($nameservers) < 1) {
            throw new ConfigException("At least one nameserver is required for a valid config");
        }

        if ($timeout < 0) {
            throw new ConfigException("Invalid timeout ({$timeout}), must be 0 or greater");
        }

        if ($attempts < 1) {
            throw new ConfigException("Invalid attempt count ({$attempts}), must be 1 or greater");
        }

        $this->nameservers = $nameservers;
        $this->knownHosts = $knownHosts;
        $this->timeout = $timeout;
        $this->attempts = $attempts;
    }

    public function getNameservers(): array {
        return $this->nameservers;
    }

    public function getKnownHosts(): array {
        return $this->knownHosts;
    }

    public function getTimeout(): int {
        return $this->timeout;
    }

    public function getAttempts(): int {
        return $this->attempts;
    }
}
