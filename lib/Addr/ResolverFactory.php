<?php

namespace Addr;

use Alert\Reactor,
    LibDNS\Decoder\DecoderFactory,
    LibDNS\Encoder\EncoderFactory,
    LibDNS\Messages\MessageFactory,
    LibDNS\Records\QuestionFactory;

class ResolverFactory
{
    /**
     * Create a new resolver instance
     *
     * @param Reactor $reactor
     * @param string $serverAddr
     * @param int $serverPort
     * @param int $requestTimeout
     * @param Cache $cache
     * @param string $hostsFilePath
     * @return Resolver
     */
    public function createResolver(
        Reactor $reactor,
        $serverAddr = null,
        $serverPort = null,
        $requestTimeout = null,
        Cache $cache = null,
        $hostsFilePath = null
    ) {
        $nameValidator = new NameValidator;
        $cache = $cache ?: new Cache\MemoryCache;

        $client = new Client(
            $reactor,
            new RequestBuilder(
                new MessageFactory,
                new QuestionFactory,
                (new EncoderFactory)->create()
            ),
            new ResponseInterpreter(
                (new DecoderFactory)->create()
            ),
            $cache, $serverAddr, $serverPort, $requestTimeout
        );

        $hostsFile = new HostsFile($nameValidator, $hostsFilePath);

        return new Resolver($reactor, $nameValidator, $client, $hostsFile);
    }
}
