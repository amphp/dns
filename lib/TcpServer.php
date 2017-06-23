<?php

namespace Amp\Dns;

use Amp\Deferred;
use Amp\Loop;
use Amp\Parser\Parser;
use Amp\Promise;
use Amp\Success;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Messages\Message;
use function Amp\call;

class TcpServer extends Server {
    private $encoder;

    private $queue;

    private $parser;

    public static function connect(string $uri, int $timeout = 5000): Promise {
        if (!$socket = @\stream_socket_client($uri, $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT)) {
            throw new ResolutionException(\sprintf(
                "Connection to %s failed: [Error #%d] %s",
                $uri,
                $errno,
                $errstr
            ));
        }

        \stream_set_blocking($socket, false);

        $deferred = new Deferred;
        $watcher = Loop::onWritable($socket, static function ($watcher) use ($socket, $deferred, &$timer) {
            Loop::cancel($watcher);
            Loop::cancel($timer);
            $deferred->resolve(new self($socket));
        });
        $timer = Loop::delay($timeout, function () use ($deferred, $watcher, $uri) {
            Loop::cancel($watcher);
            $deferred->fail(new TimeoutException("Name resolution timed out, could not connect to server at $uri"));
        });

        return $deferred->promise();
    }

    public static function parser(callable $callback) {
        $decoder = (new DecoderFactory)->create();
        $length = \unpack("n", yield 2)[1];
        $callback($decoder->decode(yield $length));
    }

    protected function __construct($socket) {
        parent::__construct($socket);
        $this->encoder = (new EncoderFactory)->create();
        $this->queue = new \SplQueue;
        $this->parser = new Parser(self::parser([$this->queue, 'push']));
    }

    public function send(Message $message): Promise {
        $data = $this->encoder->encode($message);
        return $this->write(\pack("n", \strlen($data)) . $data);
    }

    public function receive(): Promise {
        if ($this->queue->isEmpty()) {
            return call(function () {
                do {
                    $chunk = $this->read();

                    if ($chunk === null) {
                        throw new ResolutionException("Reading from the server failed");
                    }

                    $this->parser->push($chunk);
                } while ($this->queue->isEmpty());

                return $this->queue->shift();
            });
        }

        return new Success($this->queue->shift());
    }
}