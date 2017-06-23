<?php

namespace Amp\Dns;

use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\Coroutine;
use Amp\Deferred;
use Amp\Promise;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\Question;

abstract class Server {
    /** @var \Amp\ByteStream\ResourceInputStream */
    private $input;

    /** @var \Amp\ByteStream\ResourceOutputStream */
    private $output;

    /** @var \Amp\Deferred[] */
    private $questions = [];

    private $messageFactory;

    /** @var int */
    private $nextId = 0;

    /** @var callable */
    private $onResolve;

    /**
     * @param string $uri
     *
     * @return \Amp\Promise<\Amp\Dns\Server>
     */
    abstract public static function connect(string $uri): Promise;

    /**
     * @param \LibDNS\Messages\Message $message
     *
     * @return \Amp\Promise<int>
     */
    abstract protected function send(Message $message): Promise;

    /**
     * @return \Amp\Promise<\LibDNS\Messages\Message>
     */
    abstract protected function receive(): Promise;

    /**
     * @return bool
     */
    abstract public function isAlive(): bool;

    protected function __construct($socket) {
        $this->input = new ResourceInputStream($socket);
        $this->output = new ResourceOutputStream($socket);
        $this->messageFactory = new MessageFactory;

        $this->onResolve = function (\Throwable $exception = null, Message $message = null) {
            if ($exception) {
                return;
            }

            $id = $message->getId();

            if (!isset($this->questions[$id])) {
                return;
            }

            $deferred = $this->questions[$id];
            unset($this->questions[$id]);
            $deferred->resolve($message);
        };
    }

    public function ask(Question $question): Promise {
        return new Coroutine($this->doAsk($question));
    }

    private function doAsk(Question $question): \Generator {
        $id = $this->nextId++;
        if ($this->nextId > 0xffff) {
            $this->nextId %= 0xffff;
        }

        if (isset($this->questions[$id])) {
            $deferred = $this->questions[$id];
            unset($this->questions[$id]);
            $deferred->fail(new ResolutionException("Request hasn't been answered with 65k requests in between"));
        }

        $message = $this->createMessage($question, $id);
        $this->questions[$id] = $deferred = new Deferred;

        yield $this->send($message);
        $this->receive()->onResolve($this->onResolve);

        return yield $deferred->promise();
    }

    protected function read(): Promise {
        return $this->input->read();
    }

    protected function write(string $data): Promise {
        return $this->output->write($data);
    }

    protected function createMessage(Question $question, int $id): Message {
        $request = $this->messageFactory->create(MessageTypes::QUERY);
        $request->getQuestionRecords()->add($question);
        $request->isRecursionDesired(true);
        $request->setID($id);
        return $request;
    }
}
