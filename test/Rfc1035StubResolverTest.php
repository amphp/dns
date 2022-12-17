<?php declare(strict_types=1);

namespace Amp\Dns\Test;

use Amp\Dns\DnsException;
use Amp\Dns\InvalidNameException;
use Amp\Dns\Record;
use Amp\Dns\Rfc1035StubResolver;
use Amp\PHPUnit\AsyncTestCase;

class Rfc1035StubResolverTest extends AsyncTestCase
{
    public function testResolveSecondParameterAcceptedValues(): void
    {
        $this->expectException(\Error::class);
        (new Rfc1035StubResolver)->resolve("abc.de", Record::TXT);
    }

    public function testIpAsArgumentWithIPv4Restriction(): void
    {
        $this->expectException(DnsException::class);
        (new Rfc1035StubResolver)->resolve("::1", Record::A);
    }

    public function testIpAsArgumentWithIPv6Restriction(): void
    {
        $this->expectException(DnsException::class);
        (new Rfc1035StubResolver)->resolve("127.0.0.1", Record::AAAA);
    }

    public function testInvalidName(): void
    {
        $this->expectException(InvalidNameException::class);
        (new Rfc1035StubResolver)->resolve("go@gle.com", Record::A);
    }
}
