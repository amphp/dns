<?php

namespace Amp\Dns\Internal;

use Amp\ByteStream\ResourceInputStream;
use Amp\ByteStream\ResourceOutputStream;
use Amp\ByteStream\StreamException;
use Amp\CancelledException;
use Amp\Deferred;
use Amp\Dns\DnsException;
use Amp\Dns\TimeoutException;
use Amp\TimeoutCancellationToken;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\Question;
use Revolt\EventLoop;

/** @internal */
abstract class Socket
{
    private const MAX_CONCURRENT_REQUESTS = 500;

    /**
     * @param string $uri
     *
     * @return self
     */
    abstract public static function connect(string $uri): self;

    private ResourceInputStream $input;
    private ResourceOutputStream $output;
    /** @var array Contains already sent queries with no response yet. For UDP this is exactly zero or one item. */
    private array $pending = [];
    private MessageFactory $messageFactory;
    /** @var int Used for determining whether the socket can be garbage collected, because it's inactive. */
    private int $lastActivity;
    private bool $receiving = false;
    /** @var array Queued requests if the number of concurrent requests is too large. */
    private array $queue = [];

    protected function __construct($socket)
    {
        $this->input = new ResourceInputStream($socket);
        $this->output = new ResourceOutputStream($socket);
        $this->messageFactory = new MessageFactory;
        $this->lastActivity = \time();
    }

    private function fetch(): void {
        EventLoop::queue(function (): void {
            try {
                $this->handleResolution(null, $this->receive());
            } catch (\Throwable $exception) {
                $this->handleResolution($exception);
            }
        });
    }

    private function handleResolution(?\Throwable $exception, ?Message $message = null): void
    {
        $this->lastActivity = \time();
        $this->receiving = false;

        if ($exception) {
            $this->error($exception);
            return;
        }

        \assert($message instanceof Message);
        $id = $message->getId();

        // Ignore duplicate and invalid responses.
        if (isset($this->pending[$id]) && $this->matchesQuestion($message, $this->pending[$id]->question)) {
            /** @var Deferred $deferred */
            $deferred = $this->pending[$id]->deferred;
            unset($this->pending[$id]);
            $deferred->complete($message);
        }

        if (empty($this->pending)) {
            $this->input->unreference();
        } elseif (!$this->receiving) {
            $this->input->reference();
            $this->receiving = true;
            $this->fetch();
        }
    }

    abstract public function isAlive(): bool;

    public function getLastActivity(): int
    {
        return $this->lastActivity;
    }

    /**
     * @param Question $question
     * @param float $timeout
     *
     * @return Message
     */
    final public function ask(Question $question, float $timeout): Message
    {
        $this->lastActivity = \time();

        if (\count($this->pending) > self::MAX_CONCURRENT_REQUESTS) {
            $deferred = new Deferred;
            $this->queue[] = $deferred;
            $deferred->getFuture()->await();
        }

        do {
            $id = \random_int(0, 0xffff);
        } while (isset($this->pending[$id]));

        $deferred = new Deferred;
        $pending = new class {
            public Deferred $deferred;
            public Question $question;
        };

        $pending->deferred = $deferred;
        $pending->question = $question;
        $this->pending[$id] = $pending;

        $message = $this->createMessage($question, $id);

        try {
            $this->send($message);
        } catch (StreamException $exception) {
            $exception = new DnsException("Sending the request failed", 0, $exception);
            $this->error($exception);
            throw $exception;
        }

        $this->input->reference();

        if (!$this->receiving) {
            $this->receiving = true;
            $this->fetch();
        }

        try {
            return $deferred->getFuture()->await(new TimeoutCancellationToken($timeout));
        } catch (CancelledException $exception) {
            unset($this->pending[$id]);

            if (empty($this->pending)) {
                $this->input->unreference();
            }

            throw new TimeoutException("Didn't receive a response within {$timeout} seconds.");
        } finally {
            if ($this->queue) {
                $deferred = \array_shift($this->queue);
                $deferred->resolve();
            }
        }
    }

    final public function close(): void
    {
        $this->input->close();
        $this->output->close();
    }

    abstract protected function send(Message $message): void;

    abstract protected function receive(): Message;

    final protected function read(): ?string
    {
        return $this->input->read();
    }

    final protected function write(string $data): void
    {
        $this->output->write($data);
    }

    final protected function createMessage(Question $question, int $id): Message
    {
        $request = $this->messageFactory->create(MessageTypes::QUERY);
        $request->getQuestionRecords()->add($question);
        $request->isRecursionDesired(true);
        $request->setID($id);
        return $request;
    }

    private function error(\Throwable $exception): void
    {
        $this->close();

        if (empty($this->pending)) {
            return;
        }

        if (!$exception instanceof DnsException) {
            $message = "Unexpected error during resolution: " . $exception->getMessage();
            $exception = new DnsException($message, 0, $exception);
        }

        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $pendingQuestion) {
            /** @var Deferred $deferred */
            $deferred = $pendingQuestion->deferred;
            $deferred->error($exception);
        }
    }

    private function matchesQuestion(Message $message, Question $question): bool
    {
        if ($message->getType() !== MessageTypes::RESPONSE) {
            return false;
        }

        $questionRecords = $message->getQuestionRecords();

        // We only ever ask one question at a time
        if (\count($questionRecords) !== 1) {
            return false;
        }

        $questionRecord = $questionRecords->getIterator()->current();

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
