<?php

namespace Amp\Dns;

use Amp\File;
use Amp\Promise;
use function Amp\call;

final class UnixConfigLoader implements ConfigLoader {
    private $path;
    private $hostLoader;
    private $reader;

    public function __construct(string $path = "/etc/resolv.conf", HostLoader $hostLoader = null, ConfigFileReader $reader = null) {
        $this->path = $path;
        $this->hostLoader = $hostLoader ?? new DefaultHostLoader;
        $this->reader = $reader ?? (\class_exists(File\Driver::class, true) ? new AsyncConfigFileReader : new BlockingConfigFileReader);
    }

    public function loadConfig(): Promise {
        return call(function () {
            $nameservers = [];
            $timeout = 3000;
            $attempts = 2;

            $fileContent = yield $this->reader->read($this->path);

            $lines = \explode("\n", $fileContent);

            foreach ($lines as $line) {
                $line = \preg_split('#\s+#', $line, 2);

                if (\count($line) !== 2) {
                    continue;
                }

                list($type, $value) = $line;

                if ($type === "nameserver") {
                    $value = \trim($value);
                    $ip = @\inet_pton($value);

                    if ($ip === false) {
                        continue;
                    }

                    if (isset($ip[15])) { // IPv6
                        $nameservers[] = "[" . $value . "]:53";
                    } else { // IPv4
                        $nameservers[] = $value . ":53";
                    }
                } elseif ($type === "options") {
                    $optline = \preg_split('#\s+#', $value, 2);

                    if (\count($optline) !== 2) {
                        continue;
                    }

                    list($option, $value) = $optline;

                    switch ($option) {
                        case "timeout":
                            $timeout = (int) $value;
                            break;

                        case "attempts":
                            $attempts = (int) $value;
                    }
                }
            }

            $hosts = yield $this->hostLoader->loadHosts();

            return new Config($nameservers, $hosts, $timeout, $attempts);
        });
    }
}
