<?php

namespace Addr;

use LibDNS\Decoder\Decoder,
    LibDNS\Messages\MessageTypes;

class ResponseInterpreter
{
    /**
     * @var Decoder
     */
    private $decoder;

    /**
     * Constructor
     *
     * @param Decoder $decoder
     */
    public function __construct(Decoder $decoder)
    {
        $this->decoder = $decoder;
    }

    /**
     * Extract the message ID and response data from a DNS response packet
     *
     * @param string $packet
     * @return array|null
     */
    public function interpret($packet)
    {
        try {
            $message = $this->decoder->decode($packet);
        } catch (\Exception $e) {
            return null;
        }

        if ($message->getType() !== MessageTypes::RESPONSE || $message->getResponseCode() !== 0) {
            return null;
        }

        $answers = $message->getAnswerRecords();
        if (!count($answers)) {
            return [$message->getID(), null];
        }

        /** @var \LibDNS\Records\Resource $record */
        $record = $answers->getRecordByIndex(0);
        return [$message->getID(), (string)$record->getData(), $record->getTTL()];
    }
}
