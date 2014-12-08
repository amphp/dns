<?php

namespace Amp\Dns\Test;

use Amp\NativeReactor;
use Amp\Dns\AddressModes;
use Amp\Dns\Client;

class ClientTest extends \PHPUnit_Framework_TestCase {

    /**
     * @expectedException \Amp\Dns\ResolutionException
     */
    public function testFailToConnect() {
        $reactor = new NativeReactor;
        $client = new Client($reactor);
        $client->setOption(Client::OP_SERVER_ADDRESS, '260.260.260.260');
        $promise = $client->resolve('example.com', AddressModes::INET4_ADDR);
        $result = \Amp\wait($promise, $reactor);
    }

    /**
     * This is just for coverage - which is not worthwhile in itself,
     * but it makes it easier to detect missing important coverage.
     */
    public function testSetRequestTime() {
        $reactor = new NativeReactor;
        $client = new Client($reactor);
        $client->setOption(Client::OP_MS_REQUEST_TIMEOUT, 1000);
        $client->setOption(Client::OP_SERVER_PORT, 53);
    }

    /**
     * @expectedException \DomainException
     */
    public function testUnknownOptionThrowsException() {
        $reactor = new NativeReactor;
        $client = new Client($reactor);
        $client->setOption('foo', 1000);
    }

    /**
     * @expectedException \RuntimeException
     * @group internet
     */
    public function testSetAddressAfterConnectException() {
        $reactor = new NativeReactor;
        $client = new Client($reactor);
        $promise = $client->resolve('google.com', AddressModes::INET4_ADDR);
        $result = \Amp\wait($promise, $reactor);
        $client->setOption(Client::OP_SERVER_ADDRESS, '260.260.260.260');
    }

    /**
     * @expectedException \RuntimeException
     * @group internet
     */
    public function testSetPortAfterConnectException() {
        $reactor = new NativeReactor;
        $client = new Client($reactor);
        $promise = $client->resolve('google.com', AddressModes::INET4_ADDR);
        $result = \Amp\wait($promise, $reactor);
        $client->setOption(Client::OP_SERVER_PORT, 53);
    }


    /**
     * @expectedException \Amp\Dns\ResolutionException
     */
    public function testNoAnswers() {
        $reactor = new NativeReactor;
        $client = new Client($reactor);
        $promise = $client->resolve('googleaiusdisuhdihas.apsidjpasjdisjdajsoidaugiug.com', AddressModes::INET4_ADDR);
        $result = \Amp\wait($promise, $reactor);
    }

    /**
     * Test that the overflow of lookupIdCounter and requestIdCounter to
     * zero occurs.
     */
    public function testPendingIdOverflow() {
        $reactor = new NativeReactor;
        $class = new \ReflectionClass('Amp\Dns\Client');
        $lookupIdProperty = $class->getProperty("lookupIdCounter");
        $requestIdCounterProperty = $class->getProperty("requestIdCounter");

        /** @var Client $client */
        $client = $class->newInstance($reactor);
        $lookupIdProperty->setAccessible(true);
        $lookupIdProperty->setValue($client, PHP_INT_MAX);
        $requestIdCounterProperty->setAccessible(true);
        $requestIdCounterProperty->setValue($client, 65535);

        $promise = $client->resolve('google.com', AddressModes::INET4_ADDR);
        $result = \Amp\wait($promise, $reactor);
        $lookupIdCounter = $lookupIdProperty->getValue($client);
        $this->assertEquals(0, $lookupIdCounter);

        $requestIdCounter = $lookupIdProperty->getValue($client);
        $this->assertEquals(0, $requestIdCounter);
    }
}
