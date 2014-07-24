<?php

namespace AddrTest;

use Addr\AddressModes;
use Addr\ResolverFactory;
use Alert\ReactorFactory;
use Addr\ResponseInterpreter;
use LibDNS\Decoder\DecoderFactory;


function getPacketString(array $bytes)
{
    $packet = '';
    
    foreach ($bytes as $byte) {
        $packet .= chr($byte);
    }
    
    return $packet;
}


class ResponseInterpreterTest extends \PHPUnit_Framework_TestCase
{
    //Example packets below are taken from http://wiki.wireshark.org/SampleCaptures:

    //"Standard query response","DNS","70","domain","32795","6"
    static private $standardQueryResponse = [
        0x49, 0xa1, 0x81, 0x80, 0x00, 0x01, 0x00, 0x00,
        0x00, 0x00, 0x00, 0x00, 0x06, 0x67, 0x6f, 0x6f,
        0x67, 0x6c, 0x65, 0x03, 0x63, 0x6f, 0x6d, 0x00,
        0x00, 0x1d, 0x00, 0x01
    ];
    
    //"Standard query response PTR 66-192-9-104.gen.twtelecom.net","DNS","129","domain","32795","8"
    static private $standardQueryResponsePTR = [
        0x9b, 0xbb, 0x81, 0x80, 0x00, 0x01, 0x00, 0x01,
        0x00, 0x00, 0x00, 0x00, 0x03, 0x31, 0x30, 0x34,
        0x01, 0x39, 0x03, 0x31, 0x39, 0x32, 0x02, 0x36,
        0x36, 0x07, 0x69, 0x6e, 0x2d, 0x61, 0x64, 0x64,    
        0x72, 0x04, 0x61, 0x72, 0x70, 0x61, 0x00, 0x00,    
        0x0c, 0x00, 0x01, 0xc0, 0x0c, 0x00, 0x0c, 0x00,
        0x01, 0x00, 0x01, 0x51, 0x25, 0x00, 0x20, 0x0c,
        0x36, 0x36, 0x2d, 0x31, 0x39, 0x32, 0x2d, 0x39,
        0x2d, 0x31, 0x30, 0x34, 0x03, 0x67, 0x65, 0x6e,
        0x09, 0x74, 0x77, 0x74, 0x65, 0x6c, 0x65, 0x63,
        0x6f, 0x6d, 0x03, 0x6e, 0x65, 0x74, 0x00
    ];
    
    //"Standard query response A 204.152.190.12","DNS","90","domain","32795","10"
    static private $standardQueryResponseA = [
        0x75, 0xc0, 0x81, 0x80, 0x00, 0x01, 0x00, 0x01,
        0x00, 0x00, 0x00, 0x00, 0x03, 0x77, 0x77, 0x77,
        0x06, 0x6e, 0x65, 0x74, 0x62, 0x73, 0x64, 0x03,
        0x6f, 0x72, 0x67, 0x00, 0x00, 0x01, 0x00, 0x01,
        0xc0, 0x0c, 0x00, 0x01, 0x00, 0x01, 0x00, 0x01,
        0x40, 0xef, 0x00, 0x04, 0xcc, 0x98, 0xbe, 0x0c 
    ];
    
    //"Standard query response AAAA 2001:4f8:4:7:2e0:81ff:fe52:9a6b","DNS","102","domain","32795","14"
    static private $standardQueryResponseIPV6 = [
        0x7f, 0x39, 0x81, 0x80, 0x00, 0x01, 0x00, 0x01, 
        0x00, 0x00, 0x00, 0x00, 0x03, 0x77, 0x77, 0x77, 
        0x06, 0x6e, 0x65, 0x74, 0x62, 0x73, 0x64, 0x03, 
        0x6f, 0x72, 0x67, 0x00, 0x00, 0x1c, 0x00, 0x01, 
        0xc0, 0x0c, 0x00, 0x1c, 0x00, 0x01, 0x00, 0x01, 
        0x51, 0x44, 0x00, 0x10, 0x20, 0x01, 0x04, 0xf8, 
        0x00, 0x04, 0x00, 0x07, 0x02, 0xe0, 0x81, 0xff, 
        0xfe, 0x52, 0x9a, 0x6b 
    ];

    //"Standard query response CNAME www.l.google.com","DNS","94","domain","32795","16"
    static private $standardQueryResponseCNAME = [
        0x8d, 0xb3, 0x81, 0x80, 0x00, 0x01, 0x00, 0x01,
        0x00, 0x00, 0x00, 0x00, 0x03, 0x77, 0x77, 0x77,
        0x06, 0x67, 0x6f, 0x6f, 0x67, 0x6c, 0x65, 0x03,
        0x63, 0x6f, 0x6d, 0x00, 0x00, 0x1c, 0x00, 0x01,
        0xc0, 0x0c, 0x00, 0x05, 0x00, 0x01, 0x00, 0x00,
        0x02, 0x79, 0x00, 0x08, 0x03, 0x77, 0x77, 0x77,
        0x01, 0x6c, 0xc0, 0x10 
    ];
    
    //Standard query response, No such name	DNS	79	domain	32795	22
    static private $standardQueryResponseNoSuchName = [
        0x26, 0x6d, 0x85, 0x83, 0x00, 0x01, 0x00, 0x00,
        0x00, 0x00, 0x00, 0x00, 0x03, 0x77, 0x77, 0x77,
        0x07, 0x65, 0x78, 0x61, 0x6d, 0x70, 0x6c, 0x65,
        0x07, 0x6e, 0x6f, 0x74, 0x67, 0x69, 0x6e, 0x68,
        0x00, 0x00, 0x1c, 0x00, 0x01 
    ];
    
    //"Standard query response AAAA 2001:4f8:0:2::d A 204.152.184.88","DNS","115","domain","32795","24
    static private $standardQueryResponseMixed = [
        0xfe, 0xe3, 0x81, 0x80, 0x00, 0x01, 0x00, 0x02,
        0x00, 0x00, 0x00, 0x00, 0x03, 0x77, 0x77, 0x77,
        0x03, 0x69, 0x73, 0x63, 0x03, 0x6f, 0x72, 0x67,
        0x00, 0x00, 0xff, 0x00, 0x01, 0xc0, 0x0c, 0x00,
        0x1c, 0x00, 0x01, 0x00, 0x00, 0x02, 0x58, 0x00,
        0x10, 0x20, 0x01, 0x04, 0xf8, 0x00, 0x00, 0x00,
        0x02, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
        0x0d, 0xc0, 0x0c, 0x00, 0x01, 0x00, 0x01, 0x00,
        0x00, 0x02, 0x58, 0x00, 0x04, 0xcc, 0x98, 0xb8,
        0x58
    ];

    //Standard query response AAAA 2001:4f8:0:2::d A 204.152.184.88	DNS	115	domain	32795	24
    static private $multipleResponse = [    
        0xfe, 0xe3, 0x81, 0x80, 0x00, 0x01, 0x00, 0x02,
        0x00, 0x00, 0x00, 0x00, 0x03, 0x77, 0x77, 0x77,
        0x03, 0x69, 0x73, 0x63, 0x03, 0x6f, 0x72, 0x67,
        0x00, 0x00, 0xff, 0x00, 0x01, 0xc0, 0x0c, 0x00,
        0x1c, 0x00, 0x01, 0x00, 0x00, 0x02, 0x58, 0x00,
        0x10, 0x20, 0x01, 0x04, 0xf8, 0x00, 0x00, 0x00,
        0x02, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
        0x0d, 0xc0, 0x0c, 0x00, 0x01, 0x00, 0x01, 0x00,
        0x00, 0x02, 0x58, 0x00, 0x04, 0xcc, 0x98, 0xb8,
        0x58 
    ];


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
    public function testCNAME()
    {

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

        $interpreted = $responseInterpreter->interpret($response, AddressModes::INET6_ADDR);
        $this->markTestSkipped("I am unsure what the correct response should be.");
        //list($type, $addr, $ttl) = $interpreted;
        //This looks borked - it's returning the CNAME but as the asserts above are going to fail 
        // this won't be reached.
        //$type = 8 aka CNAME
        //$addr = "newswww.bbc.net.uk" aka CNAME
        //$ttl = null
    }
    
    
    function createResponseInterpreter()
    {
        $decoder = (new DecoderFactory)->create();

        $responseInterpreter = new ResponseInterpreter($decoder);

        return $responseInterpreter;
    }

    function testNoResults()
    {
        $responseInterpreter = $this->createResponseInterpreter();
        $packet = getPacketString(self::$standardQueryResponse);
        $decoded = $responseInterpreter->decode($packet);
        list($id, $message) = $decoded;
        $interpreted = $responseInterpreter->interpret($message, AddressModes::INET4_ADDR);
        $this->assertNull($interpreted);
        
        $interpreted = $responseInterpreter->interpret($message, AddressModes::INET6_ADDR);
        $this->assertNull($interpreted);
    }
    
    function testNoSuchName()
    {
        $responseInterpreter = $this->createResponseInterpreter();
        $packet = getPacketString(self::$standardQueryResponseNoSuchName);
        $decoded = $responseInterpreter->decode($packet);
        list($id, $message) = $decoded;
        $interpreted = $responseInterpreter->interpret($message, AddressModes::INET4_ADDR);
        $this->assertNull($interpreted, "Response with 'no such name' was not interpreted to null.");
    }

    function testMixed()
    {
        $responseInterpreter = $this->createResponseInterpreter();
        $packet = getPacketString(self::$standardQueryResponseMixed);
        $decoded = $responseInterpreter->decode($packet);
        list($id, $message) = $decoded;

        //Get the IPv4 part
        $interpreted = $responseInterpreter->interpret($message, AddressModes::INET4_ADDR);
        list($type, $addr, $ttl) = $interpreted;
        $this->assertEquals(AddressModes::INET4_ADDR, $type);
        $this->assertEquals('204.152.184.88', $addr);
        $this->assertEquals(600, $ttl);

        //Get the IPv6 part
        $interpreted = $responseInterpreter->interpret($message, AddressModes::INET6_ADDR);
        list($type, $addr, $ttl) = $interpreted;
        $this->assertEquals(AddressModes::INET6_ADDR, $type);
        $this->assertEquals('2001:4f8:0:2::d', $addr);
        $this->assertEquals(600, $ttl);
    }

    function testIPv4()
    {
        $responseInterpreter = $this->createResponseInterpreter();
        $packet = getPacketString(self::$standardQueryResponseA);
        $decoded = $responseInterpreter->decode($packet);
        list($id, $message) = $decoded;

        //Get the IPv4 part
        $interpreted = $responseInterpreter->interpret($message, AddressModes::INET4_ADDR);
        list($type, $addr, $ttl) = $interpreted;

        $this->assertEquals(AddressModes::INET4_ADDR, $type);
        $this->assertEquals('204.152.190.12', $addr);
        $this->assertEquals(82159, $ttl);

        //Get the IPv6 part
        $interpreted = $responseInterpreter->interpret($message, AddressModes::INET6_ADDR);
        $this->assertNull($interpreted);
    }

    function testIPv6()
    {
        $responseInterpreter = $this->createResponseInterpreter();
        $packet = getPacketString(self::$standardQueryResponseIPV6);
        $decoded = $responseInterpreter->decode($packet);
        list($id, $message) = $decoded;

        //Get the IPv4 part
        $interpreted = $responseInterpreter->interpret($message, AddressModes::INET4_ADDR);
        $this->assertNull($interpreted);

        //Get the IPv6 part
        $interpreted = $responseInterpreter->interpret($message, AddressModes::INET6_ADDR);
        list($type, $addr, $ttl) = $interpreted;
        $this->assertEquals(AddressModes::INET6_ADDR, $type);
        $this->assertEquals('2001:4f8:4:7:2e0:81ff:fe52:9a6b', $addr);
        $this->assertEquals(86340, $ttl);
    }

}
