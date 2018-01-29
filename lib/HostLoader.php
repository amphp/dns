<?php

namespace Amp\Dns;

use Amp\File;
use Amp\Promise;
use DaveRandom\LibDNS\HostsFile\Parser;
use function Amp\call;

class HostLoader {
    private $path;
    private $parser;

    public function __construct(string $path = null) {
        $this->path = $path ?? $this->getDefaultPath();
        $this->parser = new Parser();
    }

    private function getDefaultPath(): string {
        return \stripos(PHP_OS, "win") === 0
            ? 'C:\Windows\system32\drivers\etc\hosts'
            : '/etc/hosts';
    }

    public function loadHosts(): Promise {
        return call(function () {
            try {
                $data = yield File\get($this->path);
            } catch (File\FilesystemException $e) {
                return null;
            }

            return $this->parser->parseString($data);
        });
    }
}
