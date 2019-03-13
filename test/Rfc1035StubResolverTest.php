<?php

namespace Amp\Dns\Test;

use Amp\Dns\DnsException;
use Amp\Dns\InvalidNameException;
use Amp\Dns\Record;
use Amp\Dns\Rfc1035StubResolver;
use Amp\Loop;
use Amp\PHPUnit\TestCase;

class Rfc1035StubResolverTest extends TestCase
{
    public function testResolveSecondParameterAcceptedValues()
    {
        Loop::run(function () {
            $this->expectException(\Error::class);
            (new Rfc1035StubResolver)->resolve("abc.de", Record::TXT);
        });
    }

    public function testIpAsArgumentWithIPv4Restriction()
    {
        Loop::run(function () {
            $this->expectException(DnsException::class);
            yield (new Rfc1035StubResolver)->resolve("::1", Record::A);
        });
    }

    public function testIpAsArgumentWithIPv6Restriction()
    {
        Loop::run(function () {
            $this->expectException(DnsException::class);
            yield (new Rfc1035StubResolver)->resolve("127.0.0.1", Record::AAAA);
        });
    }

    public function testInvalidName()
    {
        Loop::run(function () {
            $this->expectException(InvalidNameException::class);
            yield (new Rfc1035StubResolver)->resolve("go@gle.com", Record::A);
        });
    }
}
