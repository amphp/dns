<?php

namespace Amp\Dns;

use Amp\File;
use Amp\Promise;
use function Amp\call;

class DefaultHostLoader implements HostLoader {
    private $path;
    private $reader;

    public function __construct(string $path = null, ConfigFileReader $reader = null) {
        $this->path = $path ?? $this->getDefaultPath();
        $this->reader = $reader ?? (\class_exists(File\Driver::class, true) ? new AsyncConfigFileReader : new SyncConfigFileReader);
    }

    private function getDefaultPath(): string {
        return \stripos(PHP_OS, "win") === 0
            ? 'C:\Windows\system32\drivers\etc\hosts'
            : '/etc/hosts';
    }

    public function loadHosts(): Promise {
        return call(function () {
            try {
                $contents = yield $this->reader->read($this->path);
            } catch (ConfigException $exception) {
                return [];
            }

            $data = [];

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

            // Windows does not include localhost in its host file. Fetch it from the system instead
            if (!isset($data[Record::A]["localhost"]) && !isset($data[Record::AAAA]["localhost"])) {
                // PHP currently provides no way to **resolve** IPv6 hostnames (not even with fallback)
                $local = \gethostbyname("localhost");
                if ($local !== "localhost") {
                    $data[Record::A]["localhost"] = $local;
                } else {
                    $data[Record::AAAA]["localhost"] = "::1";
                }
            }

            return $data;
        });
    }
}
