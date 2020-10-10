<?php

namespace Amp\Dns\Test;

use Amp\Dns;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\QuestionFactory;
use function Amp\delay;

class TcpSocketTest extends SocketTest
{
    protected function connect(): Dns\Internal\Socket
    {
        return Dns\Internal\TcpSocket::connect("tcp://8.8.8.8:53");
    }

    public function testTimeout()
    {
        $this->expectException(Dns\TimeoutException::class);
        Dns\Internal\TcpSocket::connect("tcp://8.8.8.8:53", 0);
    }

    public function testInvalidUri()
    {
        $this->expectException(Dns\DnsException::class);
        Dns\Internal\TcpSocket::connect("tcp://8.8.8.8");
    }

    public function testAfterConnectionTimedOut()
    {
        $question = (new QuestionFactory)->create(Dns\Record::A);
        $question->setName("google.com");

        $socket = $this->connect();

        $result = $socket->ask($question, 3000);

        $this->assertInstanceOf(Message::class, $result);
        $this->assertSame(MessageTypes::RESPONSE, $result->getType());

        // Google's DNS times out really fast
        delay(3000);

        $this->expectException(Dns\DnsException::class);

        $this->expectExceptionMessageMatches("(Sending the request failed|Reading from the server failed)");

        $socket->ask($question, 3000);
    }
}
