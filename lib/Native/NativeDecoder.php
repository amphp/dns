<?php

namespace Amp\Dns\Native;

use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\EncodingContext;
use LibDNS\Encoder\EncodingContextFactory;
use LibDNS\Messages\Message;
use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Packets\PacketFactory;
use LibDNS\Records\Question;
use LibDNS\Records\QuestionFactory;
use LibDNS\Records\Resource;
use LibDNS\Records\ResourceClasses;
use LibDNS\Records\Types\DomainName;
use LibDNS\Records\Types\Type;
use LibDNS\Records\Types\TypeBuilder;
use LibDNS\Records\Types\Types;

/**
 * Decodes JSON DNS strings to Message objects.
 *
 * @author Daniil Gentili <https://daniil.it>, Chris Wright <https://github.com/DaveRandom>
 */
class NativeDecoder
{
    /**
     * @var \LibDNS\Packets\PacketFactory
     */
    private $packetFactory;

    /**
     * @var \LibDNS\Messages\MessageFactory
     */
    private $messageFactory;

    /**
     * @var \LibDNS\Records\QuestionFactory
     */
    private $questionFactory;

    /**
     * @var \LibDNS\Records\Types\TypeBuilder
     */
    private $typeBuilder;
    /**
     * @var \LibDNS\Decoder\DecoderFactory
     */
    private $decoderFactory;

    /**
     * Map class names to IDs.
     *
     * @var array
     */
    private $classMap = [];
    /**
     * Constructor.
     *
     * @param \LibDNS\Packets\PacketFactory $packetFactory
     * @param \LibDNS\Messages\MessageFactory $messageFactory
     * @param \LibDNS\Records\QuestionFactory $questionFactory
     * @param \LibDNS\Records\Types\TypeBuilder $typeBuilder
     * @param \LibDNS\Encoder\EncodingContextFactory $encodingContextFactory
     * @param \LibDNS\Decoder\DecoderFactory $decoderFactory
     * @param bool $allowTrailingData
     */
    public function __construct(
        PacketFactory $packetFactory,
        MessageFactory $messageFactory,
        QuestionFactory $questionFactory,
        TypeBuilder $typeBuilder,
        EncodingContextFactory $encodingContextFactory,
        DecoderFactory $decoderFactory
    ) {
        $this->packetFactory = $packetFactory;
        $this->messageFactory = $messageFactory;
        $this->questionFactory = $questionFactory;
        $this->typeBuilder = $typeBuilder;
        $this->encodingContextFactory = $encodingContextFactory;
        $this->decoderFactory = $decoderFactory;

        $classes = new \ReflectionClass(ResourceClasses::class);
        foreach ($classes->getConstants() as $name => $value) {
            $this->classMap[$name] = $value;
        }
    }
    /**
     * Decode a question record.
     *
     *
     * @return \LibDNS\Records\Question
     * @throws \UnexpectedValueException When the record is invalid
     */
    private function decodeQuestionRecord(string $name, int $type): Question
    {
        /** @var \LibDNS\Records\Types\DomainName $domainName */
        $domainName = $this->typeBuilder->build(Types::DOMAIN_NAME);
        $labels = \explode('.', rtrim($name, '.'));
        $domainName->setLabels($labels);

        $question = $this->questionFactory->create($type);
        $question->setName($domainName);
        //$question->setClass($meta['class']);
        return $question;
    }

    /**
     * Encode a question record.
     *
     * @param \LibDNS\Encoder\EncodingContext $encodingContext
     * @param \LibDNS\Records\Question $record
     */
    private function encodeQuestionRecord(EncodingContext $encodingContext, string $name, int $type)
    {
        if (!$encodingContext->isTruncated()) {
            $packet = $encodingContext->getPacket();
            $record = $this->decodeQuestionRecord($name, $type);
            $name = $this->encodeDomainName($record->getName(), $encodingContext);
            $meta = \pack('n*', $record->getType(), $record->getClass());

            if (12 + $packet->getLength()+\strlen($name) + 4 > 512) {
                $encodingContext->isTruncated(true);
            } else {
                $packet->write($name);
                $packet->write($meta);
            }
        }
    }
    /**
     * Encode a DomainName field.
     *
     * @param \LibDNS\Records\Types\DomainName $domainName
     * @param \LibDNS\Encoder\EncodingContext $encodingContext
     * @return string
     */
    private function encodeDomainName(DomainName $domainName, EncodingContext $encodingContext): string
    {
        $packetIndex = $encodingContext->getPacket()->getLength() + 12;
        $labelRegistry = $encodingContext->getLabelRegistry();

        $result = '';
        $labels = $domainName->getLabels();

        if ($encodingContext->useCompression()) {
            do {
                $part = \implode('.', $labels);
                $index = $labelRegistry->lookupIndex($part);

                if ($index === null) {
                    $labelRegistry->register($part, $packetIndex);

                    $label = \array_shift($labels);
                    $length = \strlen($label);

                    $result .= \chr($length).$label;
                    $packetIndex += $length + 1;
                } else {
                    $result .= \pack('n', 0b1100000000000000 | $index);
                    break;
                }
            } while ($labels);

            if (!$labels) {
                $result .= "\x00";
            }
        } else {
            foreach ($labels as $label) {
                $result .= \chr(\strlen($label)).$label;
            }

            $result .= "\x00";
        }

        return $result;
    }

    /**
     * Encode a resource record.
     *
     * @param \LibDNS\Encoder\EncodingContext $encodingContext
     * @param array $record
     */
    private function encodeResourceRecord(EncodingContext $encodingContext, array $record)
    {
        if (!$encodingContext->isTruncated()) {
            /** @var \LibDNS\Records\Types\DomainName $domainName */
            $domainName = $this->typeBuilder->build(Types::DOMAIN_NAME);
            $labels = \explode('.', rtrim($record['host'], '.'));
            $domainName->setLabels($labels);

            $packet = $encodingContext->getPacket();
            $name = $this->encodeDomainName($domainName, $encodingContext);

            $data = $record['data'];
            $meta = \pack('n2Nn', $record['type'], $this->classMap[$record['class']], $record['ttl'], \strlen($data));

            if (12 + $packet->getLength()+\strlen($name) + 10+\strlen($data) > 512) {
                $encodingContext->isTruncated(true);
            } else {
                $packet->write($name);
                $packet->write($meta);
                $packet->write($data);
            }
        }
    }

    /**
     * Decode a Message from JSON-encoded string.
     *
     * @param array $result The actual response
     * @param string $domain The domain name that was queried
     * @param int   $type The record type that was queried
     * @param array $authoritative Authoritative NS results
     * @param array $additional Additional results
     * @return \LibDNS\Messages\Message
     * @throws \UnexpectedValueException When the packet data is invalid
     * @throws \InvalidArgumentException When a type subtype is unknown
     */
    public function decode(array $result, string $domain, int $type, array $authoritative = null, array $additional = null): Message
    {
        $additional = $additional ?? [];
        $authoritative = $additional ?? [];

        $packet = $this->packetFactory->create();
        $encodingContext = $this->encodingContextFactory->create($packet, false);
        $message = $this->messageFactory->create();

        //$message->isAuthoritative(true);
        $message->setType(MessageTypes::RESPONSE);
        //$message->setID($requestId);
        $message->setResponseCode(0);
        $message->isTruncated(false);
        $message->isRecursionDesired(false);
        $message->isRecursionAvailable(false);

        $this->encodeQuestionRecord($encodingContext, $domain, $type);

        $expectedAnswers = \count($result);
        for ($i = 0; $i < $expectedAnswers; $i++) {
            $this->encodeResourceRecord($encodingContext, $result[$i]);
        }

        $expectedAuth = \count($authoritative);
        for ($i = 0; $i < $expectedAuth; $i++) {
            $this->encodeResourceRecord($encodingContext, $authoritative[$i]);
        }

        $expectedAdditional = \count($additional);
        for ($i = 0; $i < $expectedAdditional; $i++) {
            $this->encodeResourceRecord($encodingContext, $additional[$i]);
        }

        $header = [
            'id' => $message->getID(),
            'meta' => 0,
            'qd' => 1,
            'an' => $expectedAnswers,
            'ns' => $expectedAuth,
            'ar' => $expectedAdditional,
        ];

        $header['meta'] |= $message->getType() << 15;
        $header['meta'] |= $message->getOpCode() << 11;
        $header['meta'] |= ((int) $message->isAuthoritative()) << 10;
        $header['meta'] |= ((int) $encodingContext->isTruncated()) << 9;
        $header['meta'] |= ((int) $message->isRecursionDesired()) << 8;
        $header['meta'] |= ((int) $message->isRecursionAvailable()) << 7;
        $header['meta'] |= $message->getResponseCode();

        $data = \pack('n*', $header['id'], $header['meta'], $header['qd'], $header['an'], $header['ns'], $header['ar']).$packet->read($packet->getLength());

        return $this->decoderFactory->create()->decode($data);
    }
}
