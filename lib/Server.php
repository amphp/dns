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

/** @internal */
abstract class Server {
    /** @var ResourceInputStream */
    private $input;

    /** @var ResourceOutputStream */
    private $output;

    /** @var Deferred[] */
    private $questions = [];

    /** @var MessageFactory */
    private $messageFactory;

    /** @var int */
    private $nextId = 0;

    /** @var callable */
    private $onResolve;

    /** @var int */
    private $lastActivity;

    /**
     * @param string $uri
     *
     * @return Promise<\Amp\Dns\Server>
     */
    abstract public static function connect(string $uri): Promise;

    /**
     * @param Message $message
     *
     * @return Promise<int>
     */
    abstract protected function send(Message $message): Promise;

    /**
     * @return Promise<Message>
     */
    abstract protected function receive(): Promise;

    /**
     * @return bool
     */
    abstract public function isAlive(): bool;

    public function getLastActivity(): int {
        return $this->lastActivity;
    }

    protected function __construct($socket) {
        $this->input = new ResourceInputStream($socket);
        $this->output = new ResourceOutputStream($socket);
        $this->messageFactory = new MessageFactory;
        $this->lastActivity = \time();

        $this->onResolve = function (\Throwable $exception = null, Message $message = null) {
            $this->lastActivity = \time();

            if ($exception) {
                $this->error($exception);
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
            $this->lastActivity = \time();

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
                $exception = new ResolutionException("Sending the request failed", 0, $exception);
                $this->error($exception);
                throw $exception;
            }

            $this->questions[$id] = $deferred = new Deferred;

            if ($empty) {
                $this->receive()->onResolve($this->onResolve);
            }

            try {
                return yield Promise\timeout($deferred->promise(), $timeout);
            } catch (Amp\TimeoutException $exception) {
                unset($this->questions[$id]);
                $exception = new TimeoutException("Didn't receive a response within {$timeout} milliseconds.");
                $this->error($exception);
                throw $exception;
            }
        });
    }

    public function close() {
        if ($this->input === null) {
            return;
        }

        $this->input->close();
        $this->output->close();

        $this->input = null;
        $this->output = null;
    }

    private function error(\Throwable $exception) {
        $this->close();

        if (empty($this->questions)) {
            return;
        }

        if (!$exception instanceof ResolutionException) {
            $message = "Unexpected error during resolution: " . $exception->getMessage();
            $exception = new ResolutionException($message, 0, $exception);
        }

        $questions = $this->questions;
        $this->questions = [];

        foreach ($questions as $deferred) {
            $deferred->fail($exception);
        }
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
