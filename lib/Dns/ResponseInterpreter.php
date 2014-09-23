<?php

namespace Amp\Dns;

use LibDNS\Decoder\Decoder;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\ResourceTypes;

class ResponseInterpreter {
    /**
     * @var Decoder
     */
    private $decoder;

    /**
     * Constructor
     *
     * @param Decoder $decoder
     */
    public function __construct(Decoder $decoder = null) {
        $this->decoder = $decoder ?: (new DecoderFactory)->create();
    }

    /**
     * Attempt to decode a data packet to a DNS response message
     *
     * @param string $packet
     * @return Message|null
     */
    public function decode($packet) {
        try {
            $message = $this->decoder->decode($packet);
        } catch (\Exception $e) {
            return null;
        }

        if ($message->getType() !== MessageTypes::RESPONSE || $message->getResponseCode() !== 0) {
            return null;
        }

        return [$message->getID(), $message];
    }

    /**
     * Extract the message ID and response data from a DNS response packet
     *
     * @param Message $message
     * @param int $expectedType
     * @return array|null
     */
    public function interpret($message, $expectedType) {
        static $typeMap = [
            AddressModes::INET4_ADDR => ResourceTypes::A,
            AddressModes::INET6_ADDR => ResourceTypes::AAAA,
        ];

        $answers = $message->getAnswerRecords();
        if (!count($answers)) {
            return null;
        }

        /** @var \LibDNS\Records\Resource $record */
        $cname = null;
        foreach ($answers as $record) {
            switch ($record->getType()) {
                case $typeMap[$expectedType]:
                    return [$expectedType, (string)$record->getData(), $record->getTTL()];

                case ResourceTypes::CNAME:
                    $cname = (string)$record->getData();
                    break;
            }
        }

        if ($cname) {
            return [AddressModes::CNAME, $cname, null];
        }

        return null;
    }
}
