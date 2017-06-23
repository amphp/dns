<?php

namespace Amp\Dns;

use Amp;
use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\ByteStream\StreamException;
use Amp\Deferred;
use Amp\Promise;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\Question;
use function Amp\call;

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
                $questions = $this->questions;
                $this->questions = [];
                foreach ($questions as $deferred) {
                    $deferred->fail($exception);
                }
                return;
            }

            $id = $message->getId();

            if (!isset($this->questions[$id])) {
                return; // Ignore duplicate response.
            }

            $deferred = $this->questions[$id];
            unset($this->questions[$id]);

            $empty = empty($this->questions);

            $deferred->resolve($message);

            if (!$empty) {
                $this->receive()->onResolve($this->onResolve);
            }
        };
    }

    /**
     * @param \LibDNS\Records\Question $question
     * @param int $timeout
     *
     * @return \Amp\Promise<\LibDNS\Messages\Message>
     */
    public function ask(Question $question, int $timeout): Promise {
        return call(function () use ($question, $timeout) {
            $id = $this->nextId++;
            if ($this->nextId > 0xffff) {
                $this->nextId %= 0xffff;
            }

            $empty = empty($this->questions);

            if (isset($this->questions[$id])) {
                $deferred = $this->questions[$id];
                unset($this->questions[$id]);
                $deferred->fail(new ResolutionException("Request hasn't been answered with 65k requests in between"));
            }

            $message = $this->createMessage($question, $id);

            try {
                yield $this->send($message);
            } catch (StreamException $exception) {
                throw new ResolutionException("Sending the request failed", 0, $exception);
            }

            if ($empty) {
                $this->receive()->onResolve($this->onResolve);
            }

            $this->questions[$id] = $deferred = new Deferred;

            try {
                return yield Promise\timeout($deferred->promise(), $timeout);
            } catch (Amp\TimeoutException $e) {
                unset($this->questions[$id]);
                throw new TimeoutException("Didn't receive a response within {$timeout} milliseconds.");
            }
        });
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
