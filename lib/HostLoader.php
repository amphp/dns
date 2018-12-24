<?php

namespace Amp\Dns;

use Amp\File;
use Amp\Promise;
use Amp\Uri\InvalidDnsNameException;
use function Amp\call;
use function Amp\Uri\normalizeDnsName;

class HostLoader {
    private $path;

    public function __construct(string $path = null) {
        $this->path = $path ?? $this->getDefaultPath();
    }

    private function getDefaultPath(): string {
        return \stripos(PHP_OS, "win") === 0
            ? 'C:\Windows\system32\drivers\etc\hosts'
            : '/etc/hosts';
    }

    public function loadHosts(): Promise {
        return call(function () {
            $data = [];

            try {
                $contents = yield File\get($this->path);
            } catch (File\FilesystemException $e) {
                return [];
            }

            $lines = \array_filter(\array_map("trim", \explode("\n", $contents)));

            foreach ($lines as $line) {
                if ($line[0] === "#") { // Skip comments
                    continue;
                }

                $parts = \preg_split('/\s+/', $line);

                if (!($ip = @\inet_pton($parts[0]))) {
                    continue;
                } elseif (isset($ip[4])) {
                    $key = Record::AAAA;
                } else {
                    $key = Record::A;
                }

                for ($i = 1, $l = \count($parts); $i < $l; $i++) {
                    try {
                        $normalizedName = normalizeDnsName($parts[$i]);
                        $data[$key][$normalizedName] = $parts[0];
                    } catch (InvalidDnsNameException $e) {
                        // ignore invalid entries
                    }
                }
            }

            return $data;
        });
    }
}
