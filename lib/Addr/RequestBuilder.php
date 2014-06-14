<?php

namespace Addr;

use LibDNS\Messages\MessageFactory,
    LibDNS\Messages\MessageTypes,
    LibDNS\Records\QuestionFactory,
    LibDNS\Records\ResourceQTypes,
    LibDNS\Encoder\Encoder;

class RequestBuilder
{
    /**
     * @var MessageFactory
     */
    private $messageFactory;

    /**
     * @var QuestionFactory
     */
    private $questionFactory;

    /**
     * @var Encoder
     */
    private $encoder;

    /**
     * Constructor
     *
     * @param MessageFactory $messageFactory
     * @param QuestionFactory $questionFactory
     * @param Encoder $encoder
     */
    public function __construct(MessageFactory $messageFactory, QuestionFactory $questionFactory, Encoder $encoder)
    {
        $this->messageFactory = $messageFactory;
        $this->questionFactory = $questionFactory;
        $this->encoder = $encoder;
    }

    /**
     * Build a request packet for a name and record type
     *
     * @param int $id
     * @param string $name
     * @param int $type
     * @return string
     */
    public function buildRequest($id, $name, $type)
    {
        $qType = $type === AddressModes::INET4_ADDR ? ResourceQTypes::A : ResourceQTypes::AAAA;

        $question = $this->questionFactory->create($qType);
        $question->setName($name);

        $request = $this->messageFactory->create(MessageTypes::QUERY);
        $request->setID($id);
        $request->getQuestionRecords()->add($question);
        $request->isRecursionDesired(true);

        return $this->encoder->encode($request);
    }
}
