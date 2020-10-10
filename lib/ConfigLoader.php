<?php

namespace Amp\Dns;

interface ConfigLoader
{
    public function loadConfig(): Config;
}
