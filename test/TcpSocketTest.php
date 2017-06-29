<?php

namespace Amp\Dns\Test;

use Amp\Delayed;
use Amp\Dns;
use Amp\Loop;
use Amp\Promise;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\QuestionFactory;
use function Amp\Promise\wait;

class TcpSocketTest extends SocketTest {
    protected function connect(): Promise {
        return Dns\Internal\TcpSocket::connect("tcp://8.8.8.8:53");
    }

    public function testTimeout() {
        $this->expectException(Dns\TimeoutException::class);
        wait(Dns\Internal\TcpSocket::connect("tcp://8.8.8.8:53", 0));
    }

    public function testInvalidUri() {
        $this->expectException(Dns\ResolutionException::class);
        wait(Dns\Internal\TcpSocket::connect("tcp://8.8.8.8"));
    }

    public function testAfterConnectionTimedOut() {
        Loop::run(function () {
            $question = (new QuestionFactory)->create(Dns\Record::A);
            $question->setName("google.com");

            /** @var Dns\Internal\Socket $socket */
            $socket = yield $this->connect();

            /** @var Message $result */
            $result = yield $socket->ask($question, 3000);

            $this->assertInstanceOf(Message::class, $result);
            $this->assertSame(MessageTypes::RESPONSE, $result->getType());

            // Google's DNS times out really fast
            yield new Delayed(3000);

            $this->expectException(Dns\ResolutionException::class);
            $this->expectExceptionMessage("Reading from the server failed");

            yield $socket->ask($question, 3000);
        });
    }
}
