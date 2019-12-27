<?php

namespace Amp\Dns;

use Amp\Dns\Native\NativeDecoderFactory;
use Amp\Dns\Native\NativeEncoderFactory;
use Amp\Failure;
use Amp\Promise;
use Amp\Success;
use LibDNS\Messages\MessageFactory;
use LibDNS\Messages\MessageTypes;
use LibDNS\Records\Question;
use LibDNS\Records\QuestionFactory;
use function Amp\call;

class BlockingFallbackResolver implements Resolver
{

    /** @var QuestionFactory */
    private $questionFactory;
    /** @var MessageFactory */
    private $messageFactory;
    /** @var NativeEncoderFactory */
    private $encoderFactory;
    /** @var NativeDecoderFactory */
    private $decoderFactory;
    /**
     * Constructor function.
     */
    public function __construct()
    {
        $this->questionFactory = new QuestionFactory;
        $this->messageFactory = new MessageFactory;
        $this->encoderFactory = new NativeEncoderFactory;
        $this->decoderFactory = new NativeDecoderFactory;
    }

    public function resolve(string $name, int $typeRestriction = null): Promise
    {
        if ($typeRestriction !== null && $typeRestriction !== Record::A && $typeRestriction !== Record::AAAA) {
            throw new \Error("Invalid value for parameter 2: null|Record::A|Record::AAAA expected");
        }

        switch ($typeRestriction) {
            case Record::A:
                if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return new Success([new Record($name, Record::A, null)]);
                }

                if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    return new Failure(new DnsException("Got an IPv6 address, but type is restricted to IPv4"));
                }
                break;
            case Record::AAAA:
                if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    return new Success([new Record($name, Record::AAAA, null)]);
                }

                if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return new Failure(new DnsException("Got an IPv4 address, but type is restricted to IPv6"));
                }
                break;
            default:
                if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return new Success([new Record($name, Record::A, null)]);
                }

                if (\filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                    return new Success([new Record($name, Record::AAAA, null)]);
                }
                break;
        }

        $name = normalizeName($name);

        // Follow RFC 6761 and never send queries for localhost to the caching DNS server
        // Usually, these queries are already resolved via queryHosts()
        if ($name === 'localhost') {
            return new Success($typeRestriction === Record::AAAA
                ? [new Record('::1', Record::AAAA, null)]
                : [new Record('127.0.0.1', Record::A, null)]);
        }

        return call(function () use ($name, $typeRestriction) {
            if ($typeRestriction) {
                return yield $this->query($name, $typeRestriction);
            }

            list(, $records) = yield Promise\some([
                $this->query($name, Record::A),
                $this->query($name, Record::AAAA),
            ]);

            return \array_merge(...$records);
        });
    }

    public function query(string $name, int $type): Promise
    {
        $name = $this->normalizeName($name, $type);
        $question = $this->createQuestion($name, $type);

        $request = $this->messageFactory->create(MessageTypes::QUERY);
        $request->getQuestionRecords()->add($question);

        $encoder = $this->encoderFactory->create();
        $question = $encoder->encode($request);

        $result = @\dns_get_record(...$question);
        if ($result === false) {
            if ($type !== Record::A) {
                return new Failure(new DnsException("Query for '{$name}' failed, because loading the system's DNS configuration failed and querying records other than A records isn't supported in blocking fallback mode."));
            }
            $result = \gethostbynamel($name);
            if ($result === false) {
                return new Failure(new DnsException("Query for '{$name}' failed, because loading the system's DNS configuration failed and blocking fallback via gethostbynamel() failed, too."));
            }
            if ($result === []) {
                return new Failure(new NoRecordException("No records returned for '{$name}' using blocking fallback mode."));
            }
            $records = [];
            foreach ($result as $record) {
                $records[] = new Record($record, Record::A, null);
            }
            return new Success($records);
        }

        $decoder = $this->decoderFactory->create();
        $result = $decoder->decode($result, ...$question);

        if ($result->isTruncated()) {
            return new Failure(new DnsException("Query for '{$name}' failed, because loading the system's DNS configuration failed and blocking fallback via dns_get_record() returned a truncated response for '{$name}' (".Record::getName($type).")"));
        }

        $answers = $result->getAnswerRecords();
        $result = [];
        $ttls = [];

        /** @var \LibDNS\Records\Resource $record */
        foreach ($answers as $record) {
            $recordType = $record->getType();
            $result[$recordType][] = (string) $record->getData();

            // Cache for max one day
            $ttls[$recordType] = \min($ttls[$recordType] ?? 86400, $record->getTTL());
        }
        if (!isset($result[$type])) {
            return new Failure(new NoRecordException("Query for '{$name}' failed, because loading the system's DNS configuration failed and no records were returned for '{$name}' (".Record::getName($type).")"));
        }

        return new Success(
            \array_map(
                static function ($data) use ($type, $ttls) {
                    return new Record($data, $type, $ttls[$type]);
                },
                $result[$type]
            )
        );
    }

    private function normalizeName(string $name, int $type)
    {
        if ($type === Record::PTR) {
            if (($packedIp = @\inet_pton($name)) !== false) {
                if (isset($packedIp[4])) { // IPv6
                    $name = \wordwrap(\strrev(\bin2hex($packedIp)), 1, ".", true).".ip6.arpa";
                } else { // IPv4
                    $name = \inet_ntop(\strrev($packedIp)).".in-addr.arpa";
                }
            }
        } elseif (\in_array($type, [Record::A, Record::AAAA], true)) {
            $name = normalizeName($name);
        }

        return $name;
    }

    /**
     * @param string $name
     * @param int    $type
     *
     * @return Question
     */
    private function createQuestion(string $name, int $type): Question
    {
        if (0 > $type || 0xffff < $type) {
            $message = \sprintf('%d does not correspond to a valid record type (must be between 0 and 65535).', $type);
            throw new \Error($message);
        }

        $question = $this->questionFactory->create($type);
        $question->setName($name);

        return $question;
    }
}
