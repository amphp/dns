<?php

namespace Amp\Dns\Internal;

use Amp\Dns\ResolutionException;
use Amp\Promise;
use Amp\Success;
use DaveRandom\LibDNS\Protocol\Decoding\Decoder;
use DaveRandom\LibDNS\Protocol\Encoding\Encoder;
use DaveRandom\LibDNS\Protocol\Messages\Message;
use function Amp\call;

/** @internal */
class UdpSocket extends Socket {
    /** @var \DaveRandom\LibDNS\Protocol\Encoding\Encoder */
    private $encoder;

    /** @var \DaveRandom\LibDNS\Protocol\Decoding\Decoder */
    private $decoder;

    public static function connect(string $uri): Promise {
        if (!$socket = @\stream_socket_client($uri, $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT)) {
            throw new ResolutionException(\sprintf(
                "Connection to %s failed: [Error #%d] %s",
                $uri,
                $errno,
                $errstr
            ));
        }

        return new Success(new self($socket));
    }

    protected function __construct($socket) {
        parent::__construct($socket);

        $this->encoder = new Encoder();
        $this->decoder = new Decoder();
    }

    protected function send(Message $message): Promise {
        $data = $this->encoder->encode($message);
        return $this->write($data);
    }

    protected function receive(): Promise {
        return call(function () {
            $data = yield $this->read();

            if ($data === null) {
                throw new ResolutionException("Reading from the server failed");
            }

            return $this->decoder->decode($data);
        });
    }

    public function isAlive(): bool {
        return true;
    }
}
