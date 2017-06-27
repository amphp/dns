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

    /** @var array */
    private $pending = [];

    /** @var MessageFactory */
    private $messageFactory;

    /** @var int */
    private $nextId = 0;

    /** @var callable */
    private $onResolve;

    /** @var int */
    private $lastActivity;

    /** @var bool */
    private $receiving = false;

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
            $this->receiving = false;

            if ($exception) {
                $this->error($exception);
                return;
            }

            $id = $message->getId();

            // Ignore duplicate and invalid responses.
            if (isset($this->pending[$id]) && $this->matchesQuestion($message, $this->pending[$id]->question)) {
                /** @var Deferred $deferred */
                $deferred = $this->pending[$id]->deferred;
                unset($this->pending[$id]);
                $deferred->resolve($message);
            }

            if (empty($this->pending)) {
                $this->input->unreference();
            } elseif (!$this->receiving) {
                $this->input->reference();
                $this->receiving = true;
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

            if (isset($this->pending[$id])) {
                /** @var Deferred $deferred */
                $deferred = $this->pending[$id]->deferred;
                unset($this->pending[$id]);
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

            $deferred = new Deferred;
            $pending = new class {
                use Amp\Struct;

                public $deferred;
                public $question;
            };

            $pending->deferred = $deferred;
            $pending->question = $question;
            $this->pending[$id] = $pending;

            $this->input->reference();

            if (!$this->receiving) {
                $this->receiving = true;
                $this->receive()->onResolve($this->onResolve);
            }

            try {
                return yield Promise\timeout($deferred->promise(), $timeout);
            } catch (Amp\TimeoutException $exception) {
                unset($this->pending[$id]);
                if (empty($this->pending)) {
                    $this->input->unreference();
                }
                throw new TimeoutException("Didn't receive a response within {$timeout} milliseconds.");
            }
        });
    }

    public function close() {
        $this->input->close();
        $this->output->close();
    }

    private function error(\Throwable $exception) {
        $this->close();

        if (empty($this->pending)) {
            return;
        }

        if (!$exception instanceof ResolutionException) {
            $message = "Unexpected error during resolution: " . $exception->getMessage();
            $exception = new ResolutionException($message, 0, $exception);
        }

        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $pendingQuestion) {
            /** @var Deferred $deferred */
            $deferred = $pendingQuestion->deferred;
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

    private function matchesQuestion(Message $message, Question $question): bool {
        if ($message->getType() !== MessageTypes::RESPONSE) {
            return false;
        }

        $questionRecords = $message->getQuestionRecords();

        // We only ever ask one question at a time
        if (\count($questionRecords) !== 1) {
            return false;
        }

        $questionRecord = $questionRecords->current();

        if ($questionRecord->getClass() !== $question->getClass()) {
            return false;
        }

        if ($questionRecord->getType() !== $question->getType()) {
            return false;
        }

        if ($questionRecord->getName()->getValue() !== $question->getName()->getValue()) {
            return false;
        }

        return true;
    }
}
