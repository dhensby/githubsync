<?php

namespace Dhensby\GitHubSync\Console\Command\Repository;

use Carbon\Carbon;
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
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Don\'t actually update the repository')
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
                        $output->writeln(sprintf(
                            '  - Adding missing branch %s (%s)',
                            $parentBranch['name'],
                            substr($parentBranch['commit']['sha'], 0, 9)
                        ));
                        if (!$this->getDryRun()) {
                            try {
                                $client->api('git')->references()->create($this->getOrganisation(), $repo['name'], [
                                    'ref' => 'refs/heads/' . $parentBranch['name'],
                                    'sha' => $parentBranch['commit']['sha'],
                                ]);
                            } catch (RuntimeException $e) {
                                $output->writeln('    <error>Failed to add branch: ' . $e->getMessage() . '</error>');
                            }
                        }
                        continue;
                    }
                    $force = false;
                    $attemptUpdate = false;
                    $repoBranch = $repoBranches[$parentBranch['name']];
                    if ($parentBranch['commit']['sha'] !== $repoBranch['commit']['sha']) {
                        $output->writeln(sprintf(
                            '  - Updating branch %s (%s...%s)',
                            $repoBranch['name'],
                            substr($repoBranch['commit']['sha'], 0, 9),
                            substr($parentBranch['commit']['sha'], 0, 9)
                        ));

                        try {
                            $comparedCommits = $client->api('repos')->commits()->compare(
                                $this->getOrganisation(),
                                $repo['name'],
                                $parentBranch['commit']['sha'],
                                $repoBranch['commit']['sha']
                            );
                        } catch (RuntimeException $e) {
                            $output->writeln('    <error>Skipping due to error: ' . $e->getMessage() . '</error>');
                            continue;
                        }
                        if (
                            $comparedCommits['status'] == 'behind' ||
                            ($force = $comparedCommits['status'] == 'diverged' && $this->getForce()) ||
                            ($force = $comparedCommits['status'] == 'ahead' && $this->getRewind()) ||
                            $input->isInteractive()
                        ) {
                            if (!$force && $input->isInteractive() && ($comparedCommits['status'] == 'diverged' || $comparedCommits['status'] == 'ahead')) {
                                $helper = $this->getHelper('question');
                                $question = new ConfirmationQuestion(sprintf(
                                    '    <question>Branch %s %s; would you like to discard your changes?</question> [y, N] ',
                                    $comparedCommits['status'] == 'diverged' ? 'has' : 'is',
                                    $comparedCommits['status']
                                ), false);
                                if ($helper->ask($input, $output, $question)) {
                                    $force = true;
                                    $attemptUpdate = true;
                                }
                            } else {
                                $attemptUpdate = true;
                            }
                        }
                        if ($attemptUpdate) {
                            if ($force) {
                                $output->writeln(sprintf(
                                    '    %s branch',
                                    $comparedCommits['status'] == 'ahead' ? 'Rewinding' : 'Force updating'
                                ));
                            }
                            try {
                                if (!$this->getDryRun()) {
                                    $client->api('git')->references()->update($this->getOrganisation(), $repo['name'], 'heads/' . $repoBranch['name'], [
                                        'sha' => $parentBranch['commit']['sha'],
                                        'force' => $force,
                                    ]);
                                }
                            } catch (RuntimeException $e) {
                                // not a fast forward
                                $output->writeln('    <error>Skipping due to error: ' . $e->getMessage() . '</error>');

                                // not found can be a generic "permission denied" error
                                if ($e->getMessage() === 'Not Found') {
                                    $output->writeln('<info>Make sure you are correctly authenticated with an access token with the public_repo permission</info>');
                                }
                            }
                        } else {
                            $output->writeln(sprintf(
                                '    <error>Skipping branch because branch %s %s</error>',
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
        if ($output->isDebug()) {
            $limits = $client->api('rate_limit')->getRateLimits();
            $output->writeln(sprintf(
                '<info>%s requests remaining for the next %s</info>',
                $limits['resources']['core']['remaining'],
                Carbon::now()->diffForHumans(
                    Carbon::createFromTimestamp($limits['resources']['core']['reset']),
                    true,
                    false,
                    2
                )
            ));
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

    public function getDryRun()
    {
        return $this->getInput()->getOption('dry-run');
    }
}