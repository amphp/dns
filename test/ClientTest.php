<?php

namespace Amp\Dns\Test;

use Amp\Dns\AddressModes;
use Amp\Dns\Client;


class ClientTest extends \PHPUnit_Framework_TestCase {

    /**
     * @expectedException \Amp\Dns\ResolutionException
     */
    public function testFailToConnect() {
        $client = new Client();
        $client->setOption(Client::OP_SERVER_ADDRESS, '260.260.260.260');
        $promise = $client->resolve('example.com', AddressModes::INET4_ADDR);
        $promise->wait();
    }

    /**
     * This is just for coverage - which is not worthwhile in itself,
     * but it makes it easier to detect missing important coverage.
     */
    public function testSetRequestTime() {
        $client = new Client();
        $client->setOption(Client::OP_MS_REQUEST_TIMEOUT, 1000);
        $client->setOption(Client::OP_SERVER_PORT, 53);
    }

    /**
     * @expectedException \DomainException
     */
    public function testUnknownOptionThrowsException() {
        $client = new Client();
        $client->setOption('foo', 1000);
    }

    /**
     * @expectedException \RuntimeException
     * @group internet
     */
    public function testSetAddressAfterConnectException() {
        $client = new Client();
        $promise = $client->resolve('google.com', AddressModes::INET4_ADDR);
        $promise->wait();
        $client->setOption(Client::OP_SERVER_ADDRESS, '260.260.260.260');
    }

    /**
     * @expectedException \RuntimeException
     * @group internet
     */
    public function testSetPortAfterConnectException() {
        $client = new Client();
        $promise = $client->resolve('google.com', AddressModes::INET4_ADDR);
        $promise->wait();
        $client->setOption(Client::OP_SERVER_PORT, 53);
    }


    /**
     * @expectedException \Amp\Dns\ResolutionException
     */
    public function testNoAnswers() {
        $client = new Client();
        $promise = $client->resolve('googleaiusdisuhdihas.apsidjpasjdisjdajsoidaugiug.com', AddressModes::INET4_ADDR);
        $promise->wait();
    }
}
