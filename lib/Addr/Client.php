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
    private $pendingLookups = [];

    /**
     * @var array
     */
    private $pendingRequestsByNameAndType = [];

    /**
     * @var array
     */
    private $pendingRequestsById = [];

    /**
     * @var int
     */
    private $requestIdCounter = 0;

    /**
     * @var int
     */
    private $lookupIdCounter = 0;

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

        $serverAddress = $serverAddress !== null ? (string)$serverAddress : '8.8.8.8';
        $serverPort = $serverPort !== null ? (int)$serverPort : 53;
        $requestTimeout = $requestTimeout !== null ? (int)$requestTimeout : 2000;

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
        } while(isset($this->pendingRequestsById[$result]));

        return $result;
    }

    /**
     * Get the next available lookup ID
     *
     * @return int
     */
    private function getNextFreeLookupId()
    {
        do {
            $result = $this->lookupIdCounter++;

            if ($this->lookupIdCounter >= PHP_INT_MAX) {
                $this->lookupIdCounter = 0;
            }
        } while(isset($this->pendingLookups[$result]));

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
     * Send a request to the server
     *
     * @param array $request
     */
    private function sendRequest($request)
    {
        $packet = $this->requestBuilder->buildRequest($request['id'], $request['name'], $request['type']);
        fwrite($this->socket, $packet);

        $request['timeout_id'] = $this->reactor->once(function() use($request) {
            unset($this->pendingRequestsByNameAndType[$request['name']][$request['type']]);

            foreach ($request['lookups'] as $id => $lookup) {
                $this->completePendingLookup($id, null, ResolutionErrors::ERR_SERVER_TIMEOUT);
            }
        }, $this->requestTimeout);

        if ($this->readWatcherId === null) {
            $this->readWatcherId = $this->reactor->onReadable($this->socket, function() {
                $this->onSocketReadable();
            });
        }

        $this->pendingRequestsById[$request['id']] = $request;
        $this->pendingRequestsByNameAndType[$request['name']][$request['type']] = &$this->pendingRequestsById[$request['id']];
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
        $request = $this->pendingRequestsById[$id];
        $type = $request['type'];
        $name = $request['name'];

        $this->reactor->cancel($request['timeout_id']);
        unset($this->pendingRequestsById[$id], $this->pendingRequestsByNameAndType[$name][$type]);
        if (!$this->pendingRequestsById) {
            $this->reactor->cancel($this->readWatcherId);
            $this->readWatcherId = null;
        }

        if ($addr !== null) {
            foreach ($request['lookups'] as $id => $lookup) {
                $this->completePendingLookup($id, $addr, $type);
            }

            if ($request['cache_store']) {
                call_user_func($request['cache_store'], $name, $addr, $type, $ttl);
            }
        } else {
            foreach ($request['lookups'] as $id => $lookup) {
                $this->processPendingLookup($id);
            }
        }
    }

    /**
     * Call a response callback with the result
     *
     * @param int $id
     * @param string $addr
     * @param int $type
     */
    private function completePendingLookup($id, $addr, $type)
    {
        call_user_func($this->pendingLookups[$id]['callback'], $addr, $type);
        unset($this->pendingLookups[$id]);
    }

    /**
     * Send a request to the server
     *
     * @param int $id
     */
    private function processPendingLookup($id)
    {
        $lookup = &$this->pendingLookups[$id];

        if (!$lookup['requests']) {
            $this->completePendingLookup($id, null, ResolutionErrors::ERR_NO_RECORD);
            return;
        }

        $name = $lookup['name'];
        $type = array_shift($lookup['requests']);
        $lookup['last_type'] = $type;

        $this->pendingRequestsByNameAndType[$name][$type]['lookups'][$id] = $lookup;

        if (count($this->pendingRequestsByNameAndType[$name][$type]) === 1) {
            $request = [
                'id'          => $this->getNextFreeRequestId(),
                'name'        => $name,
                'type'        => $type,
                'lookups'     => [$id => $lookup],
                'timeout_id'  => null,
                'cache_store' => $lookup['cache_store'],
            ];

            $this->sendRequest($request);
        }
    }

    /**
     * Resolve a name from a server
     *
     * @param string $name
     * @param int $mode
     * @param callable $callback
     * @param callable $cacheStore
     */
    public function resolve($name, $mode, callable $callback, callable $cacheStore = null)
    {
        $id = $this->getNextFreeLookupId();

        $this->pendingLookups[$id] = [
            'name'        => $name,
            'requests'    => $this->getRequestList($mode),
            'last_type'   => null,
            'callback'    => $callback,
            'cache_store' => $cacheStore,
        ];

        $this->processPendingLookup($id);
    }
}
