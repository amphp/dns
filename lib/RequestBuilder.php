<?php

namespace Amp\Dns;

use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\QuestionFactory;
use LibDNS\Records\ResourceQTypes;
use LibDNS\Encoder\EncoderFactory;
use LibDNS\Encoder\Encoder;

class RequestBuilder {
    /**
     * @var \LibDNS\Messages\MessageFactory
     */
    private $messageFactory;

    /**
     * @var \LibDNS\Records\QuestionFactory
     */
    private $questionFactory;

    /**
     * @var \LibDNS\Encoder\Encoder
     */
    private $encoder;

    /**
     * Constructor
     *
     * @param MessageFactory $messageFactory
     * @param QuestionFactory $questionFactory
     * @param Encoder $encoder
     */
    public function __construct(
        MessageFactory $messageFactory = null,
        QuestionFactory $questionFactory = null,
        Encoder $encoder = null
    ) {
        $this->messageFactory = $messageFactory ?: new MessageFactory;
        $this->questionFactory = $questionFactory ?: new QuestionFactory;
        $this->encoder = $encoder ?: (new EncoderFactory)->create();
    }

    /**
     * Build a request packet for a name and record type
     *
     * @param int $id
     * @param string $name
     * @param int $type
     * @return string
     */
    public function buildRequest($id, $name, $type) {
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
