<?php

namespace Dhensby\GitHubSync\Console\Command\Repository;

use Dhensby\GitHubSync\Console\Command\Repository;
use Github\ResultPager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ListCommand extends Repository
{

    protected function configure()
    {
        parent::configure();
        $this
            ->setName('repository:list')
            ->setDescription('List the repositories of the provided organisation');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        $repos = $this->getRepos();
        $forksOnly = $this->getOnlyForks();
        $forkCount = 0;
        $client = $this->getClient();
        $pager = new ResultPager($client);
        foreach ($repos as $repo) {
            $message = $repo['full_name'];
            if (!$forksOnly && !empty($repo['fork'])) {
                ++$forkCount;
                $message .= ' (fork)';
            }
            if ($output->isVeryVerbose()) {
                $message .= ' - ' . $repo['html_url'];
            }
            $output->writeln($message);
            if ($output->isVeryVerbose()) {
                $branches = $pager->fetchAll($client->api('repos'), 'branches', [$repo['owner']['login'], $repo['name']]);
                foreach ($branches as $branch) {
                    $output->writeln('  - ' . $branch['name'], OutputInterface::VERBOSITY_VERY_VERBOSE);
                }
            }
        }
        if (!$forksOnly && $output->isVerbose()) {
            $output->writeln(sprintf('%d forks of %d repos', $forkCount, count($repos)), OutputInterface::VERBOSITY_VERBOSE);
        }
    }

}