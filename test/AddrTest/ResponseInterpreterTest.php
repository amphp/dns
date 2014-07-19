<?php


namespace AddrTest;

use Addr\ResponseInterpreter;


class ResponseInterpreterTest extends \PHPUnit_Framework_TestCase{
    
    function testCatchesExceptionAndReturnsNull() {
        $decoder = \Mockery::mock('LibDNS\Decoder\Decoder');
        $decoder->shouldReceive('decode')->withAnyArgs()->andThrow("Exception", "Testing bad packet");
        $responseInterpreter = new ResponseInterpreter($decoder);
        $result = $responseInterpreter->decode("SomePacket");
        $this->assertNull($result);
    }

    function testInvalidMessage() {
        $message = \Mockery::mock('LibDNS\Messages\Message');
        $message->shouldReceive('getType')->once()->andReturn(\LibDNS\Messages\MessageTypes::QUERY);
        
        $decoder = \Mockery::mock('LibDNS\Decoder\Decoder');
        $decoder->shouldReceive('decode')->once()->andReturn($message);

        $responseInterpreter = new ResponseInterpreter($decoder);
        $result = $responseInterpreter->decode("SomePacket");
        $this->assertNull($result);
    }

    function testInvalidResponseCode() {
        $message = \Mockery::mock('LibDNS\Messages\Message');
        $message->shouldReceive('getType')->once()->andReturn(\LibDNS\Messages\MessageTypes::RESPONSE);
        $message->shouldReceive('getResponseCode')->once()->andReturn(42);
        
        $decoder = \Mockery::mock('LibDNS\Decoder\Decoder');
        $decoder->shouldReceive('decode')->once()->andReturn($message);

        $responseInterpreter = new ResponseInterpreter($decoder);
        $result = $responseInterpreter->decode("SomePacket");
        $this->assertNull($result);
    }
}

 