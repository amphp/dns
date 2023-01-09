<?php declare(strict_types=1);

namespace Amp\Dns\Test;

use Amp\Dns;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\QuestionFactory;
use function Amp\delay;

class TcpSocketTest extends SocketTest
{
    public function testTimeout(): void
    {
        $this->expectException(Dns\DnsTimeoutException::class);
        Dns\Internal\TcpSocket::connect("tcp://8.8.9.9:53", 0);
    }

    public function testInvalidUri(): void
    {
        $this->expectException(Dns\DnsException::class);
        Dns\Internal\TcpSocket::connect("tcp://8.8.8.8");
    }

    public function testAfterConnectionTimedOut(): void
    {
        $question = (new QuestionFactory)->create(Dns\DnsRecord::A);
        $question->setName("google.com");

        $socket = $this->connect();

        $result = $socket->ask($question, 3);

        self::assertInstanceOf(Message::class, $result);
        self::assertSame(MessageTypes::RESPONSE, $result->getType());

        // Google's DNS times out really fast
        delay(3);

        $this->expectException(Dns\DnsException::class);

        $this->expectExceptionMessageMatches("(Sending the request failed|Reading from the server failed)");

        $socket->ask($question, 3);
    }

    protected function connect(): Dns\Internal\Socket
    {
        return Dns\Internal\TcpSocket::connect("tcp://8.8.8.8:53");
    }
}
