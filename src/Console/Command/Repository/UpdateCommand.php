<?php

namespace Dhensby\GitHubSync\Console\Command\Repository;

use Dhensby\GitHubSync\Console\Command\Repository;
use Github\Exception\RuntimeException;
use Github\ResultPager;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class UpdateCommand extends Repository
{
    protected function configure()
    {
        parent::configure();
        $this
            ->setName('repository:update')
            ->setDescription('Update your forked repository from a parent repo')
            ->addOption('add-missing', 'm', InputOption::VALUE_NONE, 'Add branches that are in the parent but missing from the origin')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Force updates of branches when they have diverged (this will remove any custom changes pushed to the branch')
            ->addOption('rewind', 'r', InputOption::VALUE_NONE, 'Allow rewinding a branch')
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
                    $force = false;
                    $attemptMerge = false;
                    $repoBranch = $repoBranches[$parentBranch['name']];
                    if ($parentBranch['commit']['sha'] !== $repoBranch['commit']['sha']) {
                        $comparedCommits = $client->api('repos')->commits()->compare(
                            $this->getOrganisation(),
                            $repo['name'],
                            $parentBranch['commit']['sha'],
                            $repoBranch['commit']['sha']
                        );
                        if (
                            $comparedCommits['status'] == 'behind' ||
                            ($force = $comparedCommits['status'] == 'diverged' && $this->getForce()) ||
                            ($force = $comparedCommits['status'] == 'ahead' && $this->getRewind()) ||
                            $input->isInteractive()
                        ) {
                            if (!$force && $input->isInteractive() && ($comparedCommits['status'] == 'diverged' || $comparedCommits['status'] == 'ahead')) {
                                $helper = $this->getHelper('question');
                                $question = new ConfirmationQuestion(sprintf(
                                    '  Branch %s %s %s; would you like to discard your changes? [y, N] ',
                                    $repoBranch['name'],
                                    $comparedCommits['status'] == 'diverged' ? 'has' : 'is',
                                    $comparedCommits['status']
                                ), false);
                                if ($helper->ask($input, $output, $question)) {
                                    $force = true;
                                    $attemptMerge = true;
                                }
                            } else {
                                $attemptMerge = true;
                            }
                        }
                        if ($attemptMerge) {
                            $message = sprintf(
                                '  - %s branch %s (%s...%s)',
                                $comparedCommits['status'] == 'ahead' ? 'Rewinding' : 'Updating',
                                $repoBranch['name'],
                                substr($repoBranch['commit']['sha'], 0, 9),
                                substr($parentBranch['commit']['sha'], 0, 9)
                            );
                            if ($force) {
                                $message .= ' (forced update)';
                            }
                            $output->writeln($message);
                            try {
                                $client->api('git')->references()->update($this->getOrganisation(), $repo['name'], 'heads/' . $repoBranch['name'], [
                                    'sha' => $parentBranch['commit']['sha'],
                                    'force' => $force,
                                ]);
                            } catch (RuntimeException $e) {
                                $extraMessage = '';
                                // not found can be a generic "permission denied" error
                                if ($e->getMessage() === 'Not Found') {
                                    $extraMessage = 'Make sure you are correcly authenticated with an access token with the public_repo permission';
                                }
                                // not a fast forward
                                $output->writeln('    <error>' . $e->getMessage() . ' ' . $extraMessage . '</error>');
                            }
                        } else {
                            $output->writeln(sprintf(
                                '  - Skipping branch %s because branch %s %s',
                                $repoBranch['name'],
                                $comparedCommits['status'] == 'diverged' ? 'has' : 'is',
                                $comparedCommits['status']
                            ));
                            if ($output->isVerbose()) {
                                $message = '';
                                if ($comparedCommits['ahead_by']) {
                                    $message .= sprintf(
                                        '%d commit%s ahead',
                                        $comparedCommits['ahead_by'],
                                        $comparedCommits['ahead_by'] == 1 ? '' : 's'
                                    );
                                }
                                if ($comparedCommits['behind_by']) {
                                    if ($message) {
                                        $message .= ', ';
                                    }
                                    $message .= sprintf(
                                        '%d commit%s behind',
                                        $comparedCommits['behind_by'],
                                        $comparedCommits['behind_by'] == 1 ? '' : 's'
                                    );
                                }
                                $output->writeln('    ' . $message);
                            }
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

    public function getForce()
    {
        return $this->getInput()->getOption('force');
    }

    public function getRewind()
    {
        return $this->getInput()->getOption('rewind');
    }
}