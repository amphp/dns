<?php declare(strict_types=1);

namespace Amp\Dns\Test;

use Amp\Dns;

class UdpSocketTest extends SocketTest
{
    public function testInvalidUri(): void
    {
        $this->expectException(Dns\DnsException::class);
        Dns\Internal\UdpSocket::connect("udp://8.8.8.8");
    }

    protected function connect(): Dns\Internal\Socket
    {
        return Dns\Internal\UdpSocket::connect("udp://8.8.8.8:53");
    }
}
