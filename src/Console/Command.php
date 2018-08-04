<?php

namespace Dhensby\GitHubSync\Console;

use Github\Client;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Command extends BaseCommand
{
    /**
     * @var InputInterface
     */
    private $input;

    /**
     * @var OutputInterface
     */
    private $output;

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this
            ->setInput($input)
            ->setOutput($output);
    }

    /**
     * @param InputInterface $input
     * @return $this
     */
    public function setInput($input)
    {
        $this->input = $input;
        return $this;
    }

    /**
     * @return InputInterface
     */
    public function getInput()
    {
        return $this->input;
    }

    /**
     * @param OutputInterface $output
     * @return $this
     */
    public function setOutput($output)
    {
        $this->output = $output;
        return $this;
    }

    /**
     * @return OutputInterface
     */
    public function getOutput()
    {
        return $this->output;
    }

    public function getClient()
    {
        $client = new Client();
        $token = exec('composer config -g github-oauth.github.com');
        if ($token) {
            $client->authenticate($token, Client::AUTH_HTTP_TOKEN);
        }
        return $client;
    }
}