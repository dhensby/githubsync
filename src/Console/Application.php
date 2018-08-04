<?php

namespace Dhensby\GitHubSync\Console;

use Dhensby\GitHubSync\Console\Command\Repository\ListCommand;
use Dhensby\GitHubSync\Console\Command\Repository\UpdateCommand;
use Symfony\Component\Console\Application as BaseApplication;

class Application extends BaseApplication
{

    protected function getDefaultCommands()
    {
        $commands = parent::getDefaultCommands();

        $commands[] = new ListCommand();
        $commands[] = new UpdateCommand();

        return $commands;
    }

}