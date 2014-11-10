<?php

namespace Amp\Dns;

use Amp\Reactor;
use Amp\Success;
use Amp\Failure;
use Amp\Future;
use Amp\Dns\Cache\MemoryCache;

class Client {
    const OP_MS_REQUEST_TIMEOUT = 0b0001;
    const OP_SERVER_ADDRESS = 0b0010;
    const OP_SERVER_PORT = 0b0011;

    /**
     * @var \Amp\Reactor
     */
    private $reactor;

    /**
     * @var \Amp\Dns\RequestBuilder
     */
    private $requestBuilder;

    /**
     * @var \Amp\Dns\ResponseInterpreter
     */
    private $responseInterpreter;

    /**
     * @var \Amp\Dns\Cache
     */
    private $cache;

    /**
     * @var resource
     */
    private $socket;

    /**
     * @var int
     */
    private $msRequestTimeout = 2000;

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
     * @var string
     */
    private $serverAddress = '8.8.8.8';

    /**
     * @var int
     */
    private $serverPort = 53;

    /**
     * @var bool
     */
    private $isReadWatcherEnabled = false;

    /**
     * @param \Amp\Reactor $reactor
     * @param \Amp\Dns\RequestBuilder $requestBuilder
     * @param \Amp\Dns\ResponseInterpreter $responseInterpreter
     * @param \Amp\Dns\Cache $cache
     */
    public function __construct(
        Reactor $reactor = null,
        RequestBuilder $requestBuilder = null,
        ResponseInterpreter $responseInterpreter = null,
        Cache $cache = null
    ) {
        $this->reactor = $reactor ?: \Amp\reactor();
        $this->requestBuilder = $requestBuilder ?: new RequestBuilder;
        $this->responseInterpreter = $responseInterpreter ?: new ResponseInterpreter;
        $this->cache = $cache ?: new MemoryCache;
    }

    /**
     * Resolve a name from a DNS server
     *
     * @param string $name
     * @param int $mode
     * @return \Amp\Promise
     */
    public function resolve($name, $mode) {
        // Defer UDP server connect until needed to allow custom address/port option assignment
        // after object instantiation.
        if (empty($this->socket) && !$this->connect()) {
            return new Failure(new ResolutionException(
                sprintf(
                    "Failed connecting to DNS server at %s:%d",
                    $this->serverAddress,
                    $this->serverPort
                )
            ));
        }

        if (!$this->isReadWatcherEnabled) {
            $this->isReadWatcherEnabled = true;
            $this->reactor->enable($this->readWatcherId);
        }

        $promisor = new Future($this->reactor);
        $id = $this->getNextFreeLookupId();
        $this->pendingLookups[$id] = [
            'name'        => $name,
            'requests'    => $this->getRequestList($mode),
            'last_type'   => null,
            'future'      => $promisor,
        ];

        $this->processPendingLookup($id);

        return $promisor->promise();
    }

    private function connect() {
        $address = sprintf('udp://%s:%d', $this->serverAddress, $this->serverPort);
        if (!$this->socket = @stream_socket_client($address, $errNo, $errStr)) {
            return false;
        }

        stream_set_blocking($this->socket, 0);

        $this->readWatcherId = $this->reactor->onReadable($this->socket, function() {
            $this->onReadableSocket();
        }, $enableNow = false);

        return true;
    }

    private function getNextFreeLookupId() {
        do {
            $result = $this->lookupIdCounter++;

            if ($this->lookupIdCounter >= PHP_INT_MAX) {
                $this->lookupIdCounter = 0;
            }
        } while(isset($this->pendingLookups[$result]));

        return $result;
    }

    private function getRequestList($mode) {
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

    private function getNextFreeRequestId() {
        do {
            $result = $this->requestIdCounter++;

            if ($this->requestIdCounter >= 65536) {
                $this->requestIdCounter = 0;
            }
        } while (isset($this->pendingRequestsById[$result]));

        return $result;
    }

    private function sendRequest($request) {
        $packet = $this->requestBuilder->buildRequest($request['id'], $request['name'], $request['type']);

        $bytesWritten = fwrite($this->socket, $packet);
        if ($bytesWritten < strlen($packet)) {
            $this->completeRequest($request, null, ResolutionErrors::ERR_REQUEST_SEND_FAILED);
            return;
        }

        $request['timeout_id'] = $this->reactor->once(function() use($request) {
            unset($this->pendingRequestsByNameAndType[$request['name']][$request['type']]);
            $this->completeRequest($request, null, ResolutionErrors::ERR_SERVER_TIMEOUT);
        }, $this->msRequestTimeout);

        $this->pendingRequestsById[$request['id']] = $request;
        $this->pendingRequestsByNameAndType[$request['name']][$request['type']] = &$this->pendingRequestsById[$request['id']];
    }

    private function onReadableSocket() {
        $packet = fread($this->socket, 512);

        // Decode the response and clean up the pending requests list
        $decoded = $this->responseInterpreter->decode($packet);
        if ($decoded === null) {
            return;
        }

        list($id, $response) = $decoded;
        $request = $this->pendingRequestsById[$id];
        $name = $request['name'];

        $this->reactor->cancel($request['timeout_id']);
        unset(
            $this->pendingRequestsById[$id],
            $this->pendingRequestsByNameAndType[$name][$request['type']]
        );

        // Interpret the response and make sure we have at least one resource record
        $interpreted = $this->responseInterpreter->interpret($response, $request['type']);
        if ($interpreted === null) {
            foreach ($request['lookups'] as $id => $lookup) {
                $this->processPendingLookup($id);
            }

            return;
        }

        // Distribute the result to the appropriate lookup routine
        list($type, $addr, $ttl) = $interpreted;
        if ($type === AddressModes::CNAME) {
            foreach ($request['lookups'] as $id => $lookup) {
                $this->redirectPendingLookup($id, $addr);
            }
        } else if ($addr !== null) {
            $this->cache->store($name, $type, $addr, $ttl);
            $this->completeRequest($request, $addr, $type);
        } else {
            foreach ($request['lookups'] as $id => $lookup) {
                $this->processPendingLookup($id);
            }
        }
    }

    private function completePendingLookup($id, $addr, $type) {
        if (!isset($this->pendingLookups[$id])) {
            return;
        }

        $lookupStruct = $this->pendingLookups[$id];
        $future = $lookupStruct['future'];
        unset($this->pendingLookups[$id]);

        if ($addr) {
            $future->succeed([$addr, $type]);
        } else {
            $future->fail(new ResolutionException(
                $msg = sprintf('DNS resolution failed: %s', $lookupStruct['name']),
                $code = $type
            ));
        }

        if (empty($this->pendingLookups)) {
            $this->isReadWatcherEnabled = false;
            $this->reactor->disable($this->readWatcherId);
        }
    }

    private function completeRequest($request, $addr, $type) {
        foreach ($request['lookups'] as $id => $lookup) {
            $this->completePendingLookup($id, $addr, $type);
        }
    }

    private function processPendingLookup($id) {
        if (!$this->pendingLookups[$id]['requests']) {
            $this->completePendingLookup($id, null, ResolutionErrors::ERR_NO_RECORD);
            return;
        }

        $name = $this->pendingLookups[$id]['name'];
        $type = array_shift($this->pendingLookups[$id]['requests']);

        $this->cache->get($name, $type, function($cacheHit, $addr) use($id, $name, $type) {
            if ($cacheHit) {
                $this->completePendingLookup($id, $addr, $type);
            } else {
                $this->dispatchRequest($id, $name, $type);
            }
        });
    }

    private function dispatchRequest($id, $name, $type) {
        $this->pendingLookups[$id]['last_type'] = $type;
        $this->pendingRequestsByNameAndType[$name][$type]['lookups'][$id] = $this->pendingLookups[$id];

        if (count($this->pendingRequestsByNameAndType[$name][$type]) === 1) {
            $request = [
                'id'          => $this->getNextFreeRequestId(),
                'name'        => $name,
                'type'        => $type,
                'lookups'     => [$id => $this->pendingLookups[$id]],
                'timeout_id'  => null,
            ];

            $this->sendRequest($request);
        }
    }

    private function redirectPendingLookup($id, $name) {
        array_unshift($this->pendingLookups[$id]['requests'], $this->pendingLookups[$id]['last_type']);
        $this->pendingLookups[$id]['last_type'] = null;
        $this->pendingLookups[$id]['name'] = $name;

        $this->processPendingLookup($id);
    }

    /**
     * Set the Client options
     *
     * @param int $option
     * @param mixed $value
     * @throws \RuntimeException If modifying server address/port once connected
     * @throws \DomainException On unknown option key
     * @return self
     */
    public function setOption($option, $value) {
        switch ($option) {
            case self::OP_MS_REQUEST_TIMEOUT:
                $this->msRequestTimeout = (int) $value;
                break;
            case self::OP_SERVER_ADDRESS:
                if ($this->server) {
                    throw new \RuntimeException(
                        'Server address cannot be modified once connected'
                    );
                } else {
                    $this->serverAddress = $value;
                }
                break;
            case self::OP_SERVER_PORT:
                if ($this->server) {
                    throw new \RuntimeException(
                        'Server port cannot be modified once connected'
                    );
                } else {
                    $this->serverPort = $value;
                }
                break;
            default:
                throw new \DomainException(
                    sprintf("Unkown option: %s", $option)
                );
        }

        return $this;
    }
}
