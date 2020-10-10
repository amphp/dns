<?php

namespace Amp\Dns\Test;

use Amp\Dns;

class UdpSocketTest extends SocketTest
{
    protected function connect(): Dns\Internal\Socket
    {
        return Dns\Internal\UdpSocket::connect("udp://8.8.8.8:53");
    }

    public function testInvalidUri()
    {
        $this->expectException(Dns\DnsException::class);
        Dns\Internal\UdpSocket::connect("udp://8.8.8.8");
    }
}
