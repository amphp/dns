<?php declare(strict_types=1);

namespace Amp\Dns\Test;

use Amp\Dns\DnsException;
use Amp\Dns\DnsRecord;
use Amp\Dns\InvalidNameException;
use Amp\Dns\Rfc1035StubDnsResolver;
use Amp\PHPUnit\AsyncTestCase;

class Rfc1035StubResolverTest extends AsyncTestCase
{
    public function testResolveSecondParameterAcceptedValues(): void
    {
        $this->expectException(\Error::class);
        (new Rfc1035StubDnsResolver)->resolve("abc.de", DnsRecord::TXT);
    }

    public function testIpAsArgumentWithIPv4Restriction(): void
    {
        $this->expectException(DnsException::class);
        (new Rfc1035StubDnsResolver)->resolve("::1", DnsRecord::A);
    }

    public function testIpAsArgumentWithIPv6Restriction(): void
    {
        $this->expectException(DnsException::class);
        (new Rfc1035StubDnsResolver)->resolve("127.0.0.1", DnsRecord::AAAA);
    }

    public function testInvalidName(): void
    {
        $this->expectException(InvalidNameException::class);
        (new Rfc1035StubDnsResolver)->resolve("go@gle.com", DnsRecord::A);
    }
}
