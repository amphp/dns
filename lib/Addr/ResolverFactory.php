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
     * @param string $hostsFilePath
     * @return Resolver
     */
    public function createResolver(
        Reactor $reactor,
        $serverAddr = '8.8.8.8',
        $serverPort = 53,
        $requestTimeout = 2000,
        $hostsFilePath = null
    ) {
        $nameValidator = new NameValidator;

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
            $serverAddr, $serverPort, $requestTimeout
        );

        $cache = new Cache;
        $hostsFile = new HostsFile($nameValidator, $hostsFilePath);

        return new Resolver($reactor, $nameValidator, $client, $cache, $hostsFile);
    }
}
