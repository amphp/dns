<?php

namespace Addr;

use Alert\Reactor;

class ResolverFactory
{
    /**
     * Create a new resolver instance
     *
     * @param Reactor $reactor
     * @return Resolver
     */
    public function createResolver(Reactor $reactor)
    {
        return new Resolver($reactor, new Cache);
    }
}
