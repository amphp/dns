<?php

namespace Amp\Dns;

use Amp\Coroutine;
use Amp\Promise;
use LibDNS\Records\Question;
use LibDNS\Records\QuestionFactory;

class BasicResolver implements Resolver {
    /** @var \Amp\Dns\ConfigLoader */
    private $configLoader;

    /** @var \LibDNS\Records\QuestionFactory */
    private $questionFactory;

    /** @var \Amp\Dns\Config|null */
    private $config;

    public function __construct(ConfigLoader $configLoader = null) {
        $this->configLoader = $configLoader ?? \stripos(PHP_OS, "win") === 0
                ? new WindowsConfigLoader
                : new UnixConfigLoader;

        $this->questionFactory = new QuestionFactory;
    }

    /**
     * @see \Amp\Dns\resolve
     */
    public function resolve(string $name): Promise {
        // TODO: Implement resolve() method.
    }

    public function query(string $name, $type): Promise {
        return new Coroutine($this->doQuery($name, $type));
    }

    public function doQuery(string $name, int $type): \Generator {
        if (!$this->config) {
            $this->config = yield $this->configLoader->loadConfig();
        }

        $question = $this->createQuestion($name, $type);

        $nameservers = $this->config->getNameservers();
        $attempts = $this->config->getAttempts();

        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            $i = $attempt % \count($nameservers);
            $uri = "udp://" . $nameservers[$i];

            var_dump($uri);

            /** @var \Amp\Dns\Server $server */
            $server = yield UdpServer::connect($uri);

            /** @var \LibDNS\Messages\Message $response */
            $response = yield $server->ask($question);

            if ($response->getResponseCode() !== 0) {
                throw new ResolutionException(\sprintf("Got a response code of %d", $response->getResponseCode()));
            }

            $answers = $response->getAnswerRecords();

            $result = [];
            /** @var \LibDNS\Records\Resource $record */
            foreach ($answers as $record) {
                $result[] = $record;
            }
            return $result;
        }

        throw new ResolutionException("No response from any nameserver");
    }

    /**
     * @param string $name
     * @param int $type
     *
     * @return \LibDNS\Records\Question
     */
    private function createQuestion(string $name, int $type): Question {
        if (0 > $type || 0xffff < $type) {
            throw new \Error(\sprintf('%d does not correspond to a valid record type (must be between 0 and 65535).', $type));
        }
        $question = $this->questionFactory->create($type);
        $question->setName($name);
        return $question;
    }
}