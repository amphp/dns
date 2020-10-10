<?php

namespace Amp\Dns\Test;

use Amp\Dns;
use Amp\PHPUnit\AsyncTestCase;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\QuestionFactory;

abstract class SocketTest extends AsyncTestCase
{
    abstract protected function connect(): Dns\Internal\Socket;

    public function testAsk()
    {
        $question = (new QuestionFactory)->create(Dns\Record::A);
        $question->setName("google.com");

        $socket = $this->connect();

        $result = $socket->ask($question, 5000);

        $this->assertInstanceOf(Message::class, $result);
        $this->assertSame(MessageTypes::RESPONSE, $result->getType());
    }

    public function testGetLastActivity()
    {
        $question = (new QuestionFactory)->create(Dns\Record::A);
        $question->setName("google.com");

        $socket = $this->connect();

        $this->assertLessThan(\time() + 1, $socket->getLastActivity());
    }
}
