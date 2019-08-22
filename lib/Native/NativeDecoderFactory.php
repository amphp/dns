<?php
/**
 * Creates NativeDecoder objects.
 *
 * @author Daniil Gentili <https://daniil.it>, Chris Wright <https://github.com/DaveRandom>
 * @copyright Copyright (c) Chris Wright <https://github.com/DaveRandom>,
 * @license http://www.opensource.org/licenses/mit-license.html MIT License
 */

namespace Amp\Dns\Native;

use \LibDNS\Messages\MessageFactory;
use \LibDNS\Packets\PacketFactory;
use \LibDNS\Records\QuestionFactory;
use \LibDNS\Records\RecordCollectionFactory;
use \LibDNS\Records\TypeDefinitions\TypeDefinitionManager;
use \LibDNS\Records\Types\TypeBuilder;
use \LibDNS\Records\Types\TypeFactory;
use LibDNS\Decoder\DecoderFactory;
use LibDNS\Encoder\EncodingContextFactory;

/**
 * Creates NativeDecoder objects.
 *
 * @author Daniil Gentili <https://daniil.it>, Chris Wright <https://github.com/DaveRandom>
 */
class NativeDecoderFactory
{
    /**
     * Create a new NativeDecoder object.
     *
     * @param \LibDNS\Records\TypeDefinitions\TypeDefinitionManager $typeDefinitionManager
     * @return NativeDecoder
     */
    public function create(TypeDefinitionManager $typeDefinitionManager = null): NativeDecoder
    {
        $typeBuilder = new TypeBuilder(new TypeFactory);

        return new NativeDecoder(
            new PacketFactory,
            new MessageFactory(new RecordCollectionFactory),
            new QuestionFactory,
            $typeBuilder,
            new EncodingContextFactory,
            new DecoderFactory
        );
    }
}
