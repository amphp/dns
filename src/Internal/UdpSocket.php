<?php

namespace Amp\Dns\Internal;

use Amp\Dns\DnsException;
use LibDNS\Decoder\Decoder;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\Encoder;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Messages\Message;

/** @internal */
final class UdpSocket extends Socket
{
    /**
     * @throws DnsException
     */
    public static function connect(string $uri): self
    {
        if (!$socket = @\stream_socket_client($uri, $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT)) {
            throw new DnsException(\sprintf(
                "Connection to %s failed: [Error #%d] %s",
                $uri,
                $errno,
                $errstr
            ));
        }

        return new self($socket);
    }

    private readonly Encoder $encoder;
    private readonly Decoder $decoder;

    /**
     * @param resource $socket
     */
    protected function __construct($socket)
    {
        parent::__construct($socket);

        $this->encoder = (new EncoderFactory)->create();
        $this->decoder = (new DecoderFactory)->create();
    }

    public function isAlive(): bool
    {
        return true;
    }

    protected function send(Message $message): void
    {
        $data = $this->encoder->encode($message);
        $this->write($data);
    }

    protected function receive(): Message
    {
        $data = $this->read();

        if ($data === null) {
            throw new DnsException("Reading from the server failed");
        }

        return $this->decoder->decode($data);
    }
}
