<?php declare(strict_types = 1);
/**
 * Creates NativeEncoder objects.
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

/**
 * Creates NativeEncoder objects.
 *
 * @category LibDNS
 * @package Encoder
 * @author Daniil Gentili <https://daniil.it>, Chris Wright <https://github.com/DaveRandom>
 */
class NativeEncoderFactory
{
    /**
     * Create a new Encoder object.
     *
     * @return \LibDNS\Encoder\Encoder
     */
    public function create(): NativeEncoder
    {
        return new NativeEncoder();
    }
}
