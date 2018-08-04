<?php

namespace Dhensby\GitHubSync;

use Github\Client;
use Github\Exception\ApiLimitExceedException;
use Psr\Http\Message\ResponseInterface;

class Api
{
    private $commandStack = [];

    /**
     * Store the set of API calls to be made so they can be called later
     *
     * @param string $name
     * @param array $arguments
     * @return $this
     */
    public function __call($name, $arguments)
    {
        $this->commandStack[] = [
            'name' => $name,
            'arguments' => $arguments,
        ];
        return $this;
    }

    /**
     * Executes the API call and ensures all pages are loaded
     * @return array
     */
    public function execute()
    {
        if (false !== $this->hasCache()) {
            return $this->getCache();
        }
        $client = new Client();
        if ($token = $this->getAuthentication()) {
            $client->authenticate($token, Client::AUTH_HTTP_TOKEN);
        }
        $result = $client;
        $return = [];
        $lastCommand = array_pop($this->commandStack);
        $page = 1;
        if (!empty($this->commandStack)) {
            foreach ($this->commandStack as $command) {
                $result = call_user_func_array([$result, $command['name']], $command['arguments']);
            }
        }
        $this->commandStack[] = $lastCommand;
        $result->setPerPage(100);
        do {
            $result->setPage($page);
            try {
                $lastResult = call_user_func_array([$result, $lastCommand['name']], $lastCommand['arguments']);
                $return = array_merge($return, $lastResult);
                ++$page;
            } catch (ApiLimitExceedException $e) {
                // try to reauth?
                throw $e;
            }
        } while (!empty($lastResult) && $this->isNextPage($client->getLastResponse()));
//        $this->saveCache($return);
        $this->commandStack = [];
        return $return;
    }

    /**
     * @param ResponseInterface $lastResponse
     * @return bool
     */
    protected function isNextPage($lastResponse)
    {
        $linkHeaders = $lastResponse->getHeader('Link');
        foreach ($linkHeaders as $linkHeader) {
            $headerParts = explode(',', $linkHeader);
            foreach ($headerParts as $part) {
                list($link, $rel) = explode(';', trim($part), 2);
                if (trim($rel) === 'rel="next"') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @return string
     */
    protected function getAuthentication()
    {
        return exec('composer config -g github-oauth.github.com');
    }

    /**
     * @param mixed $data
     * @return bool|int
     */
    protected function saveCache($data)
    {
        if (!file_exists('~/.githubsync/cache/')) {
            mkdir('~/.githubsync/cache/', 0700, true);
        }
        return file_put_contents('~/.githubsync/cache/' . $this->getCacheKey() . '.json', json_encode($data));
    }

    /**
     * @return bool|mixed
     */
    protected function getCache()
    {
        if (false !== ($cacheFile = $this->hasCache())) {
            return json_decode(file_get_contents($cacheFile));
        }
        return false;
    }

    protected function getCacheKey()
    {
        return md5(var_export($this->commandStack, true));
    }

    protected function hasCache()
    {
        if (file_exists($cacheFile = '~/.githubsync/cache/' . $this->getCacheKey() . '.json')) {
            return $cacheFile;
        }
        return false;
    }
}

