<?php

namespace Amp\Dns;

use Amp\Success;
use Amp\Failure;

class Resolver {
    /**
     * @var \Amp\Dns\Client
     */
    private $client;

    /**
     * @var \Amp\Dns\HostsFile
     */
    private $hostsFile;

    /**
     * @var \Amp\Dns\NameValidator
     */
    private $nameValidator;

    /**
     * @param \Amp\Dns\Client $client
     * @param \Amp\Dns\HostsFile $hostsFile
     * @param \Amp\Dns\NameValidator $nameValidator
     */
    public function __construct(
        Client $client = null,
        HostsFile $hostsFile = null,
        NameValidator $nameValidator = null
    ) {
        $this->client = $client ?: new Client;
        $this->nameValidator = $nameValidator ?: new NameValidator;
        $this->hostsFile = $hostsFile ?: new HostsFile($this->nameValidator);
    }

    /**
     * Resolve a host name to an IP address
     *
     * @param string $name
     * @param int $mode
     * @return \Amp\Promise
     */
    public function resolve($name, $mode = AddressModes::ANY_PREFER_INET4) {
        if (strcasecmp($name, 'localhost') === 0) {
            return new Success($this->resolveLocalhost($mode));
        } elseif ($addrStruct = $this->resolveFromIp($name)) {
            return new Success($addrStruct);
        } elseif (!$this->nameValidator->validate($name)) {
            return new Failure(new ResolutionException(
                sprintf('Invalid DNS name format: %s', $name)
            ));
        } elseif ($this->hostsFile && ($addrStruct = $this->hostsFile->resolve($name, $mode))) {
            return new Success($addrStruct);
        } else {
            return $this->client->resolve($name, $mode);
        }
    }

    private function resolveLocalhost($mode) {
        return ($mode & AddressModes::PREFER_INET6)
            ? ['::1', AddressModes::INET6_ADDR]
            : ['127.0.0.1', AddressModes::INET4_ADDR];
    }

    private function resolveFromIp($name) {
        if (!$inAddr = @inet_pton($name)) {
            return [];
        } elseif (isset($inAddr['15'])) {
            return [$name, AddressModes::INET6_ADDR];
        } else {
            return [$name, AddressModes::INET4_ADDR];
        }
    }
}
