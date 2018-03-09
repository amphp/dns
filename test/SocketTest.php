<?php

namespace Amp\Dns\Test;

use Amp\Dns;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\QuestionFactory;

abstract class SocketTest extends TestCase {
    abstract protected function connect(): Promise;

    public function testAsk() {
        Loop::run(function () {
            $question = (new QuestionFactory)->create(Dns\Record::A);
            $question->setName("google.com");

            /** @var Dns\Internal\Socket $socket */
            $socket = yield $this->connect();

            /** @var Message $result */
            $result = yield $socket->ask($question, 5000);

            $this->assertInstanceOf(Message::class, $result);
            $this->assertSame(MessageTypes::RESPONSE, $result->getType());
        });
    }

    public function testGetLastActivity() {
        Loop::run(function () {
            $question = (new QuestionFactory)->create(Dns\Record::A);
            $question->setName("google.com");

            /** @var Dns\Internal\Socket $socket */
            $socket = yield $this->connect();

            $this->assertRegExp("/152061[0-9][0-9][0-9][0-9]/", (string) $socket->getLastActivity());
        });
    }
}
