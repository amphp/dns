<?php

namespace Addr;

use Alert\Reactor;

class Client
{
    /**
     * @var Reactor
     */
    private $reactor;

    /**
     * @var RequestBuilder
     */
    private $requestBuilder;

    /**
     * @var ResponseInterpreter
     */
    private $responseInterpreter;

    /**
     * @var resource
     */
    private $socket;

    /**
     * @var int
     */
    private $requestTimeout;

    /**
     * @var int
     */
    private $readWatcherId;

    /**
     * @var array
     */
    private $outstandingRequests = [];

    /**
     * @var int
     */
    private $requestIdCounter = 0;

    /**
     * Constructor
     *
     * @param Reactor $reactor
     * @param RequestBuilder $requestBuilder
     * @param ResponseInterpreter $responseInterpreter
     * @param string $serverAddress
     * @param int $serverPort
     * @param int $requestTimeout
     * @throws \RuntimeException
     */
    public function __construct(
        Reactor $reactor,
        RequestBuilder $requestBuilder,
        ResponseInterpreter $responseInterpreter,
        $serverAddress = null,
        $serverPort = null,
        $requestTimeout = null
    ) {
        $this->reactor = $reactor;
        $this->requestBuilder = $requestBuilder;
        $this->responseInterpreter = $responseInterpreter;

        $serverAddress = $serverAddress === null ? (string)$serverAddress : '8.8.8.8';
        $serverPort = $serverPort === null ? (int)$serverPort : 53;
        $requestTimeout = $requestTimeout === null ? (int)$requestTimeout : 2000;

        $address = sprintf('udp://%s:%d', $serverAddress, $serverPort);
        $this->socket = stream_socket_client($address, $errNo, $errStr);
        if (!$this->socket) {
            throw new \RuntimeException("Creating socket {$address} failed: {$errNo}: {$errStr}");
        }

        stream_set_blocking($this->socket, 0);
        $this->requestTimeout = $requestTimeout;
    }

    /**
     * Get the next available request ID
     *
     * @return int
     */
    private function getNextFreeRequestId()
    {
        do {
            $result = $this->requestIdCounter++;

            if ($this->requestIdCounter >= 65536) {
                $this->requestIdCounter = 0;
            }
        } while(isset($this->outstandingRequests[$result]));

        return $result;
    }

    /**
     * Get a list of requests to execute for a given mode mask
     *
     * @param int $mode
     * @return array
     */
    private function getRequestList($mode)
    {
        $result = [];

        if ($mode & AddressModes::PREFER_INET6) {
            if ($mode & AddressModes::INET6_ADDR) {
                $result[] = AddressModes::INET6_ADDR;
            }
            if ($mode & AddressModes::INET4_ADDR) {
                $result[] = AddressModes::INET4_ADDR;
            }
        } else {
            if ($mode & AddressModes::INET4_ADDR) {
                $result[] = AddressModes::INET4_ADDR;
            }
            if ($mode & AddressModes::INET6_ADDR) {
                $result[] = AddressModes::INET6_ADDR;
            }
        }

        return $result;
    }

    /**
     * Handle data waiting to be read from the socket
     */
    private function onSocketReadable()
    {
        $packet = fread($this->socket, 512);

        $response = $this->responseInterpreter->interpret($packet);
        if ($response === null) {
            return;
        }

        list($id, $addr, $ttl) = $response;
        if ($addr !== null) {
            $this->completeOutstandingRequest($id, $addr, $this->outstandingRequests[$id]['last_type'], $ttl);
        } else {
            $this->processOutstandingRequest($id);
        }
    }

    /**
     * Call a response callback with the result
     *
     * @param int $id
     * @param string $addr
     * @param int $type
     * @param int $ttl
     */
    private function completeOutstandingRequest($id, $addr, $type, $ttl = null)
    {
        $this->reactor->cancel($this->outstandingRequests[$id]['timeout_id']);
        call_user_func($this->outstandingRequests[$id]['callback'], $addr, $type, $ttl);
        unset($this->outstandingRequests[$id]);

        if (!$this->outstandingRequests) {
            $this->reactor->cancel($this->readWatcherId);
            $this->readWatcherId = null;
        }
    }

    /**
     * Send a request to the server
     *
     * @param int $id
     */
    private function processOutstandingRequest($id)
    {
        if (!$this->outstandingRequests[$id]['requests']) {
            $this->completeOutstandingRequest($id, null, ResolutionErrors::ERR_NO_RECORD);
            return;
        }

        $type = array_shift($this->outstandingRequests[$id]['requests']);
        $this->outstandingRequests[$id]['last_type'] = $type;

        $packet = $this->requestBuilder->buildRequest($id, $this->outstandingRequests[$id]['name'], $type);
        fwrite($this->socket, $packet);

        $this->outstandingRequests[$id]['timeout_id'] = $this->reactor->once(function() use($id) {
            $this->completeOutstandingRequest($id, null, ResolutionErrors::ERR_SERVER_TIMEOUT);
        }, $this->requestTimeout);

        if ($this->readWatcherId === null) {
            $this->readWatcherId = $this->reactor->onReadable($this->socket, function() {
                $this->onSocketReadable();
            });
        }
    }

    /**
     * Resolve a name from a server
     *
     * @param string $name
     * @param int $mode
     * @param callable $callback
     */
    public function resolve($name, $mode, callable $callback)
    {
        $requests = $this->getRequestList($mode);
        $id = $this->getNextFreeRequestId();

        $this->outstandingRequests[$id] = [
            'name'       => $name,
            'requests'   => $requests,
            'last_type'  => null,
            'timeout_id' => null,
            'callback'   => $callback
        ];

        $this->processOutstandingRequest($id);
    }
}
