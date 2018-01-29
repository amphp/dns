<?php

namespace Amp\Dns\Test;

use Amp\Dns;
use Amp\Loop;
use Amp\PHPUnit\TestCase;
use Amp\Promise;
use DaveRandom\LibDNS\Protocol\Messages\Response;
use DaveRandom\LibDNS\Records\QuestionRecord;
use DaveRandom\Network\DomainName;

abstract class SocketTest extends TestCase {
    abstract protected function connect(): Promise;

    public function testAsk() {
        Loop::run(function () {
            $question = new QuestionRecord(DomainName::createFromString("google.com"), Dns\Record::A);

            /** @var Dns\Internal\Socket $socket */
            $socket = yield $this->connect();

            $result = yield $socket->ask($question, 5000);

            $this->assertInstanceOf(Response::class, $result);
        });
    }
}
