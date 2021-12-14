<?php

namespace Amp\Dns\Test;

use Amp\Dns;
use Amp\PHPUnit\AsyncTestCase;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\QuestionFactory;
use function Amp\now;

abstract class SocketTest extends AsyncTestCase
{
    public function testAsk(): void
    {
        $question = (new QuestionFactory)->create(Dns\Record::A);
        $question->setName("google.com");

        $socket = $this->connect();

        $result = $socket->ask($question, 5000);

        self::assertInstanceOf(Message::class, $result);
        self::assertSame(MessageTypes::RESPONSE, $result->getType());
    }

    public function testGetLastActivity(): void
    {
        $question = (new QuestionFactory)->create(Dns\Record::A);
        $question->setName("google.com");

        $socket = $this->connect();

        self::assertLessThan(now() + 1, $socket->getLastActivity());
    }

    abstract protected function connect(): Dns\Internal\Socket;
}
