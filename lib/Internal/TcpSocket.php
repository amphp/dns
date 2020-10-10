<?php

namespace Amp\Dns\Internal;

use Amp\Deferred;
use Amp\Dns\DnsException;
use Amp\Dns\TimeoutException;
use Amp\Loop;
use Amp\Parser\Parser;
use Amp\Promise;
use Amp\TimeoutException as PromiseTimeoutException;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\Encoder;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Messages\Message;
use function Amp\await;

/** @internal */
final class TcpSocket extends Socket
{
    private Encoder $encoder;

    private \SplQueue $queue;

    private Parser $parser;

    private bool $isAlive = true;

    public static function connect(string $uri, int $timeout = 5000): self
    {
        if (!$socket = @\stream_socket_client($uri, $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT)) {
            throw new DnsException(\sprintf(
                "Connection to %s failed: [Error #%d] %s",
                $uri,
                $errno,
                $errstr
            ));
        }

        \stream_set_blocking($socket, false);

        $deferred = new Deferred;

        $watcher = Loop::onWritable($socket, static function (string $watcher) use ($socket, $deferred): void {
            Loop::cancel($watcher);
            $deferred->resolve(new self($socket));
        });

        try {
            return await(Promise\timeout($deferred->promise(), $timeout));
        } catch (PromiseTimeoutException $e) {
            Loop::cancel($watcher);
            throw new TimeoutException("Name resolution timed out, could not connect to server at $uri");
        }
    }

    public static function parser(callable $callback): \Generator
    {
        $decoder = (new DecoderFactory)->create();

        while (true) {
            $length = yield 2;
            $length = \unpack("n", $length)[1];

            $rawData = yield $length;
            $callback($decoder->decode($rawData));
        }
    }

    protected function __construct($socket)
    {
        parent::__construct($socket);

        $this->encoder = (new EncoderFactory)->create();
        $this->queue = new \SplQueue;
        $this->parser = new Parser(self::parser([$this->queue, 'push']));
    }

    protected function send(Message $message): void
    {
        $data = $this->encoder->encode($message);
        try {
            $this->write(\pack("n", \strlen($data)) . $data);
        } catch (\Throwable $exception) {
            $this->isAlive = false;
            throw $exception;
        }
    }

    protected function receive(): Message
    {
        if ($this->queue->isEmpty()) {
            do {
                $chunk = $this->read();

                if ($chunk === null) {
                    $this->isAlive = false;
                    throw new DnsException("Reading from the server failed");
                }

                $this->parser->push($chunk);
            } while ($this->queue->isEmpty());

            return $this->queue->shift();
        }

        return $this->queue->shift();
    }

    public function isAlive(): bool
    {
        return $this->isAlive;
    }
}
