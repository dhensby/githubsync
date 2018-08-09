# GitHub Sync

Sync your forked repositories with their upstream parents.

## Installation

`composer global require dhensby/githubsync`

## Usage

`githubsync repository:update [org/username]`

### Options

| Option | Behaviour |
| --- | --- |
| `--add-missing` | Add any branches present in the upstream repo that aren't present in the fork |
| `--force` | Force any updates to branches when the branches have diverged |
| `--rewind` | Force any updates to branches when the change is a rewind |
| `--from-source` | Update from the root source repo rather than the parent; this only applies if your repo forked a fork of an upstream and will cause the root repo to be used as the source of commits |