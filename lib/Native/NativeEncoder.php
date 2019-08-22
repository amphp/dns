<?php
/**
 * Encodes Message objects to query strings.
 *
 * PHP version 5.4
 *
 * @category LibDNS
 * @package Encoder
 * @author Daniil Gentili <https://daniil.it>, Chris Wright <https://github.com/DaveRandom>
 * @copyright Copyright (c) Chris Wright <https://github.com/DaveRandom>
 * @license http://www.opensource.org/licenses/mit-license.html MIT License
 * @version 2.0.0
 */

namespace Amp\Dns\Native;

use \LibDNS\Messages\Message;
use LibDNS\Messages\MessageTypes;

/**
 * Encodes Message objects to query strings.
 *
 * @category LibDNS
 * @package Encoder
 * @author Daniil Gentili <https://daniil.it>, Chris Wright <https://github.com/DaveRandom>
 */
class NativeEncoder
{
    /**
     * Encode a Message to URL payload.
     *
     * @param \LibDNS\Messages\Message $message  The Message to encode
     * @return array Array of parameters to pass to the \dns_get_record function
     */
    public function encode(Message $message): array
    {
        if ($message->getType() !== MessageTypes::QUERY) {
            throw new \InvalidArgumentException('Invalid question: is not a question record');
        }
        $questions = $message->getQuestionRecords();
        if ($questions->count() === 0) {
            throw new \InvalidArgumentException('Invalid question: 0 question records provided');
        }
        if ($questions->count() !== 1) {
            throw new \InvalidArgumentException('Invalid question: only one question record can be provided at a time');
        }

        $question = $questions->getRecordByIndex(0);

        return [
            \implode('.', $question->getName()->getLabels()), // Name
            $question->getType(), // Type
            null, // Authority records
            null, // Additional records
            true, // Raw results
        ];
    }
}
