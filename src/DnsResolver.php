<?php declare(strict_types=1);

namespace Amp\Dns;

use Amp\Cancellation;

interface DnsResolver
{
    /**
     * Resolves a hostname name to an IP address [hostname as defined by RFC 3986].
     *
     * Upon success the returned promise resolves to an array of Record objects.
     *
     * A null $ttl value indicates the DNS name was resolved from the cache or the local hosts file.
     *
     * @param string $name The hostname to resolve.
     * @param int|null $typeRestriction Optional type restriction to `Record::A` or `Record::AAAA`, otherwise `null`.
     *
     * @return array<DnsRecord>
     *
     * @throws MissingDnsRecordException
     * @throws DnsException
     */
    public function resolve(string $name, int $typeRestriction = null, ?Cancellation $cancellation = null): array;

    /**
     * Query specific DNS records.
     *
     * Upon success the returned promise resolves to an array of Record objects.
     *
     * @param string $name Record to question, A, AAAA and PTR queries are automatically normalized.
     * @param int $type Use constants of Amp\Dns\Record.
     *
     * @return array<DnsRecord>
     *
     * @throws DnsException
     */
    public function query(string $name, int $type, ?Cancellation $cancellation = null): array;
}
