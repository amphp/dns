<?php declare(strict_types=1);

namespace Amp\Dns\Test;

use Amp\Dns;
use LibDNS\Records\QuestionFactory;
use Revolt\EventLoop;

class UdpSocketTest extends SocketTest
{
    public function testInvalidUri(): void
    {
        $this->expectException(Dns\DnsException::class);
        Dns\Internal\UdpSocket::connect("udp://8.8.8.8");
    }

    public function testMalformedResponse(): void
    {
        $server = \stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, \STREAM_SERVER_BIND);
        EventLoop::onReadable($server, function () use ($server) {
            \stream_socket_recvfrom($server, 512, 0, $addr);
            \stream_socket_sendto($server, 'invalid', 0, $addr);
        });

        $question = (new QuestionFactory)->create(Dns\DnsRecord::A);
        $question->setName("google.com");

        $socket = Dns\Internal\UdpSocket::connect('udp://' . \stream_socket_get_name($server, false));

        $this->expectException(Dns\DnsTimeoutException::class);
        $this->expectErrorMessage("Didn't receive a response within 1 seconds, but received 1 invalid packets on this socket");

        $socket->ask($question, 1);
    }

    protected function connect(): Dns\Internal\Socket
    {
        return Dns\Internal\UdpSocket::connect("udp://8.8.8.8:53");
    }
}
