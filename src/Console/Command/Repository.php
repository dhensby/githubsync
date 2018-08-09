<?php

namespace Dhensby\GitHubSync\Console\Command;

use Dhensby\GitHubSync\Console\Command;
use Github\ResultPager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class Repository extends Command
{

    protected function configure()
    {
        parent::configure();

        $this
            ->addArgument('organisation', InputArgument::REQUIRED, 'The organisation to list the repos of')
            ->addArgument('repository', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Whitelist of repositories (if none provided assume all)')
            ->addOption('only-forks', 'o', InputOption::VALUE_NONE, 'Only return forks');
    }

    protected function getRepos()
    {
        $client = $this->getClient();
        $pager = new ResultPager($client);
        $repos = $pager->fetchAll($client->api('user'), 'repositories', [$this->getOrganisation()]);
        $onlyForks = $this->getOnlyForks();
        return array_filter($repos, function ($repo) use ($onlyForks) {
            $whitelistRepos = $this->getInput()->getArgument('repository');
            return (empty($whitelistRepos) || in_array($repo['name'], $whitelistRepos)) && (!$onlyForks || !empty($repo['fork']));
        });
    }

    public function getOrganisation()
    {
        return $this->getInput()->getArgument('organisation');
    }

    public function getOnlyForks()
    {
        return $this->getInput()->getOption('only-forks');
    }

}