<?php

namespace Amp\Dns\Test;

use Amp\Dns\BasicResolver;
use Amp\Dns\DnsException;
use Amp\Dns\InvalidNameException;
use Amp\Dns\Record;
use Amp\Loop;
use Amp\PHPUnit\TestCase;

class BasicResolverTest extends TestCase {
    public function testResolveSecondParameterAcceptedValues() {
        Loop::run(function () {
            $this->expectException(\Error::class);
            (new BasicResolver)->resolve("abc.de", Record::TXT);
        });
    }

    public function testIpAsArgumentWithIPv4Restriction() {
        Loop::run(function () {
            $this->expectException(DnsException::class);
            yield (new BasicResolver)->resolve("::1", Record::A);
        });
    }

    public function testIpAsArgumentWithIPv6Restriction() {
        Loop::run(function () {
            $this->expectException(DnsException::class);
            yield (new BasicResolver)->resolve("127.0.0.1", Record::AAAA);
        });
    }

    public function testInvalidName() {
        Loop::run(function () {
            $this->expectException(InvalidNameException::class);
            yield (new BasicResolver)->resolve("go@gle.com", Record::A);
        });
    }
}
