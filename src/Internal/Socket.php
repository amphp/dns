<?php

namespace Amp\Dns\Internal;

use Amp\ByteStream\ClosedException;
use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\StreamException;
use Amp\ByteStream\WritableResourceStream;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Dns\DnsException;
use Amp\Dns\TimeoutException;
use Amp\TimeoutCancellation;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\Question;
use Revolt\EventLoop;
use function Amp\now;

/** @internal */
abstract class Socket
{
    private const MAX_CONCURRENT_REQUESTS = 500;

    abstract public static function connect(string $uri): self;

    private ReadableResourceStream $input;
    private WritableResourceStream $output;

    /** @var array Contains already sent queries with no response yet. For UDP this is exactly zero or one item. */
    private array $pending = [];

    private MessageFactory $messageFactory;

    /** @var float Used for determining whether the socket can be garbage collected, because it's inactive. */
    private float $lastActivity;

    private bool $receiving = false;

    /** @var EventLoop\Suspension[] Queued requests if the number of concurrent requests is too large. */
    private array $queue = [];

    /**
     * @param resource $socket
     */
    protected function __construct($socket)
    {
        $this->input = new ReadableResourceStream($socket);
        $this->output = new WritableResourceStream($socket);
        $this->messageFactory = new MessageFactory;
        $this->lastActivity = now();
    }

    private function fetch(): void
    {
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
        $this->lastActivity = now();
        $this->receiving = false;

        if ($exception) {
            $this->error($exception);
            return;
        }

        \assert($message instanceof Message);
        $id = $message->getId();

        // Ignore duplicate and invalid responses.
        if (isset($this->pending[$id]) && $this->matchesQuestion($message, $this->pending[$id]->question)) {
            /** @var DeferredFuture $deferred */
            $deferred = $this->pending[$id]->deferred;
            unset($this->pending[$id]);
            $deferred->complete($message);
        }

        /** @psalm-suppress RedundantCondition */
        if (empty($this->pending)) {
            $this->input->unreference();
        } elseif (!$this->receiving) {
            $this->input->reference();
            $this->receiving = true;
            $this->fetch();
        }
    }

    abstract public function isAlive(): bool;

    public function getLastActivity(): float
    {
        return $this->lastActivity;
    }

    /**
     * @throws DnsException
     */
    final public function ask(Question $question, float $timeout): Message
    {
        $this->lastActivity = now();

        if (\count($this->pending) > self::MAX_CONCURRENT_REQUESTS) {
            $suspension = EventLoop::getSuspension();
            $this->queue[] = $suspension;
            $suspension->suspend();
        }

        do {
            $id = \random_int(0, 0xffff);
        } while (isset($this->pending[$id]));

        $deferred = new DeferredFuture;

        /** @psalm-suppress MissingConstructor */
        $pending = new class {
            public DeferredFuture $deferred;
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
            return $deferred->getFuture()->await(new TimeoutCancellation($timeout));
        } catch (CancelledException $exception) {
            unset($this->pending[$id]);

            if (empty($this->pending)) {
                $this->input->unreference();
            }

            throw new TimeoutException("Didn't receive a response within {$timeout} seconds.");
        } finally {
            if ($this->queue) {
                $deferred = \array_shift($this->queue);
                $deferred->resume();
            }
        }
    }

    final public function close(): void
    {
        $this->error(new ClosedException('Socket has been closed'));
    }

    /**
     * @throws StreamException
     */
    abstract protected function send(Message $message): void;

    /**
     * @throws DnsException
     */
    abstract protected function receive(): Message;

    final protected function read(): ?string
    {
        return $this->input->read();
    }

    /**
     * @throws ClosedException
     */
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
        $this->input->close();
        $this->output->close();

        if (!$exception instanceof DnsException) {
            $message = "Unexpected error during resolution: " . $exception->getMessage();
            $exception = new DnsException($message, 0, $exception);
        }

        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $pendingQuestion) {
            /** @var DeferredFuture $deferred */
            $deferred = $pendingQuestion->deferred;
            $deferred->error($exception);
        }

        $queue = $this->queue;
        $this->queue = [];

        foreach ($queue as $suspension) {
            $suspension->throw($exception);
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
