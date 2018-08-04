<?php

namespace Dhensby\GitHubSync\Console\Command\Repository;

use Dhensby\GitHubSync\Console\Command\Repository;
use Github\Exception\RuntimeException;
use Github\ResultPager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateCommand extends Repository
{
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('repository:update')
            ->setDescription('Update your forked repository from a parent repo')
            ->addOption('add-missing', 'm', InputOption::VALUE_NONE, 'Add branches that are in the parent but missing from the origin')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force updates of branches')
            ->addOption('from-source', 's', InputOption::VALUE_NONE, 'Update from source repo instead of parent');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);
        $client = $this->getClient();
        $pager = new ResultPager($client);
        foreach ($this->getRepos() as $repo) {
            $output->writeln('Analysing repo: ' . $repo['full_name']);
            $repoData = $client->api('repos')->showById($repo['id']);
            $parentKey = $input->getOption('from-source') ? 'source' : 'parent';
            $parentRepo = $repoData[$parentKey];
            $rawParentBranches = $pager->fetchAll($client->api('repos'), 'branches', [$parentRepo['owner']['login'], $parentRepo['name']]);
            $rawRepoBranches = $pager->fetchAll($client->api('repos'), 'branches', [$repoData['owner']['login'], $repoData['name']]);
            $parentBranches = $repoBranches = [];
            foreach ($rawParentBranches as $branch) {
                $parentBranches[$branch['name']] = $branch;
            }
            foreach ($rawRepoBranches as $branch) {
                $repoBranches[$branch['name']] = $branch;
            }
            unset($rawRepoBranches, $rawParentBranches, $branch);
            foreach ($parentBranches as $parentBranch) {
                if (($update = array_key_exists($parentBranch['name'], $repoBranches)) || $input->getOption('add-missing')) {
                    if (!$update) {
                        $output->writeln('  - Adding missing branch ' . $parentBranch['name']);
                        $client->api('git')->references()->create($this->getOrganisation(), $repo['name'], [
                            'ref' => 'refs/heads/' . $parentBranch['name'],
                            'sha' => $parentBranch['commit']['sha'],
                        ]);
                        continue;
                    }
                    $repoBranch = $repoBranches[$parentBranch['name']];
                    if ($parentBranch['commit']['sha'] !== $repoBranch['commit']['sha']) {
                        $output->writeln(sprintf(
                            '  - Updating branch %s (%s...%s)',
                            $repoBranch['name'],
                            substr($repoBranch['commit']['sha'], 0, 6),
                            substr($parentBranch['commit']['sha'], 0, 6)
                        ));
                        try {
                            $client->api('git')->references()->update($this->getOrganisation(), $repo['name'], 'heads/' . $repoBranch['name'], [
                                'sha' => $parentBranch['commit']['sha'],
                                'force' => $input->getOption('force'),
                            ]);
                        } catch (RuntimeException $e) {
                            // not a fast forward
                            $output->writeln('    <error>' . $e->getMessage() . '</error>');
                        }
                    }
                }
            }
        }
    }

    public function getOnlyForks()
    {
        return true;
    }
}