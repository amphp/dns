<?php

namespace AddrTest;

use Addr\AddressModes;
use Addr\ResolverFactory;
use Alert\ReactorFactory;
use Addr\ResponseInterpreter;
use LibDNS\Decoder\DecoderFactory;

class ResponseInterpreterTest extends \PHPUnit_Framework_TestCase
{
    public function testCatchesExceptionAndReturnsNull()
    {
        $decoder = \Mockery::mock('LibDNS\Decoder\Decoder');
        $decoder->shouldReceive('decode')->withAnyArgs()->andThrow("Exception", "Testing bad packet");
        $responseInterpreter = new ResponseInterpreter($decoder);
        $result = $responseInterpreter->decode("SomePacket");
        $this->assertNull($result);
    }

    public function testInvalidMessage()
    {
        $message = \Mockery::mock('LibDNS\Messages\Message');
        $message->shouldReceive('getType')->once()->andReturn(\LibDNS\Messages\MessageTypes::QUERY);

        $decoder = \Mockery::mock('LibDNS\Decoder\Decoder');
        $decoder->shouldReceive('decode')->once()->andReturn($message);

        $responseInterpreter = new ResponseInterpreter($decoder);
        $result = $responseInterpreter->decode("SomePacket");
        $this->assertNull($result);
    }

    public function testInvalidResponseCode()
    {
        $message = \Mockery::mock('LibDNS\Messages\Message');
        $message->shouldReceive('getType')->once()->andReturn(\LibDNS\Messages\MessageTypes::RESPONSE);
        $message->shouldReceive('getResponseCode')->once()->andReturn(42);

        $decoder = \Mockery::mock('LibDNS\Decoder\Decoder');
        $decoder->shouldReceive('decode')->once()->andReturn($message);

        $responseInterpreter = new ResponseInterpreter($decoder);
        $result = $responseInterpreter->decode("SomePacket");
        $this->assertNull($result);
    }

// Test string below was generated with
//
//    function getSaneString($result) {
//        $resultInHex = unpack('H*', $result);
//        $resultInHex = $resultInHex[1];
//
//        $charsAsHex = str_split($resultInHex, 2);
//
//        $output = '';
//
//        foreach ($charsAsHex as $charAsHex) {
//            $decimal = hexdec($charAsHex);
//            if ($decimal >= 32 && $decimal <= 126) {
//                $output .= chr($decimal);
//            }
//            else {
//                $output .= '\x'.$charAsHex;
//            }
//        }
//
//        return $output;
//    }
    
    
    /**
     * @group CNAME
     */
    public function testCNAME() {

        $testPacket = "\x00\x00\x81\x80\x00\x01\x00\x03\x00\x00\x00\x00\x04news\x03bbc\x02co\x02uk\x00\x00\x01\x00\x01\xc0\x0c\x00\x05\x00\x01\x00\x00\x01W\x00\x12\x07newswww\x03bbc\x03net\xc0\x18\xc0,\x00\x01\x00\x01\x00\x00\x00\xd2\x00\x04\xd4:\xf6P\xc0,\x00\x01\x00\x01\x00\x00\x00\xd2\x00\x04\xd4:\xf6Q";
        
        $decoder = (new DecoderFactory)->create();
        
        $responseInterpreter = new ResponseInterpreter($decoder);
        $decoded = $responseInterpreter->decode($testPacket);

        list($id, $response) = $decoded;

        //Check the IPV4 result
        $interpreted = $responseInterpreter->interpret($response, AddressModes::INET4_ADDR);
        list($type, $addr, $ttl) = $interpreted;
        $this->assertEquals(AddressModes::INET4_ADDR, $type);
        $this->assertSame("212.58.246.80", $addr);
        $this->assertSame(210, $ttl);


        
        $interpreted = $responseInterpreter->interpret($response, AddressModes::CNAME);
        list($type, $addr, $ttl) = $interpreted;
        $this->assertEquals(AddressModes::INET4_ADDR, $type);
        $this->assertSame("newswww.bbc.net.uk", $addr);
        $this->assertNull($ttl);

        $interpreted = $responseInterpreter->interpret($response, AddressModes::INET6_ADDR);
        list($type, $addr, $ttl) = $interpreted;
        //This looks borked - it's returning the CNAME but as the asserts above are going to fail 
        // this won't be reached.
        //$type = 8 aka CNAME
        //$addr = "newswww.bbc.net.uk" aka CNAME
        //$ttl = null
    }
    
}
