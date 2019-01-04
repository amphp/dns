<?php

namespace Amp\Dns\Test;

use Amp\Dns;
use Amp\Promise;
use function Amp\Promise\wait;

class UdpSocketTest extends SocketTest {
    protected function connect(): Promise {
        return Dns\Internal\UdpSocket::connect("udp://8.8.8.8:53");
    }

    public function testInvalidUri() {
        $this->expectException(Dns\DnsException::class);
        wait(Dns\Internal\UdpSocket::connect("udp://8.8.8.8"));
    }
}
