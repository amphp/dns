<?php

namespace Amp\Dns;

use Amp\Promise;
use DaveRandom\Network\DomainName;
use DaveRandom\Network\IPAddress;

interface Resolver {
    /**
     * Resolves a hostname name to an IP address [hostname as defined by RFC 3986].
     *
     * Upon success the returned promise resolves to an array of Record objects.
     *
     * A null $ttl value indicates the DNS name was resolved from the cache or the local hosts file.
     *
     * @param string|DomainName $name The hostname to resolve.
     * @param int    $typeRestriction Optional type restriction to `Record::A` or `Record::AAAA`, otherwise `null`.
     *
     * @return Promise
     */
    public function resolve($name, int $typeRestriction = null): Promise;

    /**
     * Query specific DNS records.
     *
     * Upon success the returned promise resolves to an array of Record objects.
     *
     * @param string|DomainName|IPAddress $name Record to query; A, AAAA and PTR queries are automatically normalized.
     * @param int    $type Use constants of Amp\Dns\Record.
     *
     * @return Promise
     */
    public function query($name, int $type): Promise;
}
