<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Repository\Vcs;

use Composer\Cache;
use Composer\Config;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Pcre\Preg;

/**
 * @author Oleg Andreyev <oleg@andreyev.lv>
 */
class BitbucketServerDriver extends VcsDriver
{
    /** @var Cache */
    protected $cache;
    protected $owner;
    protected $repository;
    protected $hasIssues;
    protected $rootIdentifier;
    protected $tags;
    protected $branches;
    protected $infoCache = array();
    protected $branchesUrl = '';
    protected $scheme;
    protected $tagsUrl = '';
    protected $homeUrl = '';
    protected $website = '';
    protected $cloneHttpsUrl = '';

    /**
     * @var VcsDriver
     */
    protected $fallbackDriver;

    /** @var string|null if set either git or hg */
    protected $vcsType;

    const URL_REGEX = "#^(?:(?P<scheme>https|ssh?))://(?:(?P<login>.+?)@)?(?P<domain>.+?)(?::(?P<port>[0-9]+))?(?:(?P<parts>/.+)?)/~?(?P<owner>.+)/(?P<repo>[^/]+?)(?:\.git)?$#";

    /**
     * {@inheritDoc}
     */
    public function initialize(): void
    {
        if (!Preg::isMatch(self::URL_REGEX, $this->url, $match)) {
            throw new \InvalidArgumentException(sprintf('The Bitbucket Server repository URL %s is invalid. It must be the HTTP(s) URL of a Bitbucket server project.', $this->url));
        }

        $guessedDomain = $match['domain'];
        $configuredDomains = $this->config->get('bitbucket-server-domains') ?? [];
        $urlParts = explode("/", $match['parts']);
        $this->scheme = !empty($match['scheme'])
            ? $match['scheme']
            : (isset($this->repoConfig['secure-http']) && $this->repoConfig['secure-http'] === false ? 'http' : 'https')
        ;

        $origin = self::determineOrigin($configuredDomains, $guessedDomain, $urlParts, $match['port']);

        if (false !== strpos($this->originUrl, ':') || false !== strpos($this->originUrl, '/')) {
            $this->hasNonstandardOrigin = true;
        }

        $this->owner = $match['owner'];
        $this->repository = $match['repo'];
        $this->originUrl = $match['domain'];
        $this->cache = new Cache(
            $this->io,
            implode('/', array(
                $this->config->get('cache-repo-dir'),
                $this->originUrl,
                $this->owner,
                $this->repository,
            ))
        );
        $this->cache->setReadOnly($this->config->get('cache-read-only'));
    }

    /**
     * @see https://docs.atlassian.com/bitbucket-server/rest/6.4.0/bitbucket-rest.html#idp73
     */
    public static function supports(IOInterface $io, Config $config, $url, $deep = false): bool
    {
        if (!Preg::isMatch(self::URL_REGEX, $url, $match)) {
            return false;
        }

        $scheme = $match['scheme'];
        $guessedDomain = !empty($match['domain']) ? $match['domain'] : $match['domain2'];
        $urlParts = explode('/', $match['parts']);

        if (false === self::determineOrigin((array) $config->get('bitbucket-server-domains'), $guessedDomain, $urlParts, $match['port'])) {
            return false;
        }

        if ('https' === $scheme && !extension_loaded('openssl')) {
            $io->writeError('Skipping BitbucketServer driver for '.$url.' because the OpenSSL PHP extension is missing.', true, IOInterface::VERBOSE);

            return false;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getUrl(): string
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getUrl();
        }

        return $this->cloneHttpsUrl;
    }

    /**
     * Attempts to fetch the repository data via the BitBucket API and
     * sets some parameters which are used in other methods
     *
     * @see https://docs.atlassian.com/bitbucket-server/rest/6.4.0/bitbucket-rest.html#idp158
     *
     * @return bool
     */
    protected function getRepoData()
    {
        $resource = sprintf(
            'https://%s/rest/api/1.0/projects/%s/repos/%s',
            $this->originUrl,
            $this->owner,
            $this->repository
        );

        $repoData = $this->getContents($resource)->decodeJson();
        if ($this->fallbackDriver) {
            return false;
        }
        $this->parseCloneUrls($repoData['links']['clone']);

        $this->hasIssues = false;
        $this->vcsType = $repoData['scmId'];

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function getComposerInformation($identifier): ?array
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getComposerInformation($identifier);
        }

        if (!isset($this->infoCache[$identifier])) {
            if ($this->shouldCache($identifier) && $res = $this->cache->read($identifier)) {
                $composer = JsonFile::parseJson($res);
            } else {
                $composer = $this->getBaseComposerInformation($identifier);

                if ($this->shouldCache($identifier)) {
                    $this->cache->write($identifier, json_encode($composer));
                }
            }

            if ($composer) {
                // specials for bitbucket
                if (!isset($composer['support']['source'])) {
                    $label = array_search($identifier, $this->getTags(), true) ?: array_search($identifier, $this->getBranches(), true) ?: $identifier;

                    if (array_key_exists($label, $tags = $this->getTags())) {
                        $hash = $tags[$label];
                    } elseif (array_key_exists($label, $branches = $this->getBranches())) {
                        $hash = $branches[$label];
                    }

                    if (! isset($hash)) {
                        $composer['support']['source'] = sprintf(
                            'https://%s/projects/%s/repos/%s/browse',
                            $this->originUrl,
                            $this->owner,
                            $this->repository
                        );
                    } else {
                        $composer['support']['source'] = sprintf(
                            'https://%s/projects/%s/repos/%s/browse?at=%s',
                            $this->originUrl,
                            $this->owner,
                            $this->repository,
                            $identifier
                        );
                    }
                }
                if (!isset($composer['support']['issues']) && $this->hasIssues) {
                    $composer['support']['issues'] = sprintf(
                        'https://%s/%s/%s/issues',
                        $this->originUrl,
                        $this->owner,
                        $this->repository
                    );
                }
                if (!isset($composer['homepage'])) {
                    $composer['homepage'] = empty($this->website) ? $this->homeUrl : $this->website;
                }
            }

            $this->infoCache[$identifier] = $composer;
        }

        return $this->infoCache[$identifier];
    }

    /**
     * {@inheritdoc}
     *
     * @see https://docs.atlassian.com/bitbucket-server/rest/6.4.0/bitbucket-rest.html#idp302
     */
    public function getFileContent($file, $identifier): ?string
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getFileContent($file, $identifier);
        }

        if (strpos($identifier, '/') !== false) {
            $branches = $this->getBranches();
            if (isset($branches[$identifier])) {
                $identifier = $branches[$identifier];
            }
        }

        $resource = sprintf(
            'https://%s/rest/api/1.0/projects/%s/repos/%s/raw/%s?at=%s',
            $this->originUrl,
            $this->owner,
            $this->repository,
            $file,
            $identifier
        );

        return $this->getContents($resource)->getBody();
    }

    /**
     * {@inheritdoc}
     *
     * @see https://docs.atlassian.com/bitbucket-server/rest/6.4.0/bitbucket-rest.html#idp193
     */
    public function getChangeDate($identifier): ?\DateTimeImmutable
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getChangeDate($identifier);
        }

        if (strpos($identifier, '/') !== false) {
            $branches = $this->getBranches();
            if (isset($branches[$identifier])) {
                $identifier = $branches[$identifier];
            }
        }

        $resource = sprintf(
            'https://%s/rest/api/1.0/projects/%s/repos/%s/commits/%s',
            $this->originUrl,
            $this->owner,
            $this->repository,
            $identifier
        );
        $commit = $this->getContents($resource)->decodeJson();

        $dateTime = new \DateTime();
        return $dateTime->setTimestamp($commit['authorTimestamp'] / 1000);
    }

    /**
     * {@inheritDoc}
     */
    public function getSource($identifier): array
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getSource($identifier);
        }

        return array('type' => $this->vcsType, 'url' => $this->getUrl(), 'reference' => $identifier);
    }

    /**
     * {@inheritDoc}
     *
     * @see https://docs.atlassian.com/bitbucket-server/rest/6.4.0/bitbucket-rest.html#idp178
     */
    public function getDist($identifier): ?array
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getDist($identifier);
        }

        $url = sprintf(
            'https://%s/rest/api/1.0/projects/%s/repos/%s/archive?at=%s&format=zip',
            $this->originUrl,
            $this->owner,
            $this->repository,
            $identifier
        );

        return array('type' => 'zip', 'url' => $url, 'reference' => $identifier, 'shasum' => '');
    }

    /**
     * {@inheritDoc}
     *
     * @see https://docs.atlassian.com/bitbucket-server/rest/6.4.0/bitbucket-rest.html#idp321
     */
    public function getTags(): array
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getTags();
        }

        if (null === $this->tags) {
            $this->tags = array();
            $resource = sprintf(
                'https://%s/rest/api/1.0/projects/%s/repos/%s/tags?%s',
                $this->originUrl,
                $this->owner,
                $this->repository,
                http_build_query(
                    array(
                        'start' => 0,
                        'limit' => 100,
                        'orderBy' => 'MODIFICATION',
                    ),
                    null,
                    '&'
                )
            );
            $hasNext = true;
            while ($hasNext) {
                $tagsData = $this->getContents($resource)->decodeJson();
                foreach ($tagsData['values'] as $data) {
                    $this->tags[$data['displayId']] = $data['latestCommit'];
                }

                if (isset($tagsData['isLastPage']) && $tagsData['isLastPage'] === true) {
                    $hasNext = false;
                } else {
                    $resource = sprintf(
                        'https://%s/rest/api/1.0/projects/%s/repos/%s/tags?%s',
                        $this->originUrl,
                        $this->owner,
                        $this->repository,
                        http_build_query(
                            array(
                                'start' => $tagsData['nextPageStart'],
                                'limit' => 100,
                                'orderBy' => 'MODIFICATION',
                            ),
                            null,
                            '&'
                        )
                    );
                }
            }
        }

        return $this->tags;
    }

    /**
     * {@inheritDoc}
     *
     * @see https://docs.atlassian.com/bitbucket-server/rest/6.4.0/bitbucket-rest.html#idp180
     */
    public function getBranches(): array
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getBranches();
        }

        if (null === $this->branches) {
            $this->branches = array();
            $resource = sprintf(
                'https://%s/rest/api/1.0/projects/%s/repos/%s/branches?%s',
                $this->originUrl,
                $this->owner,
                $this->repository,
                http_build_query(
                    array(
                        'start' => 0,
                        'limit' => 100,
                        'orderBy' => 'MODIFICATION',
                    ),
                    null,
                    '&'
                )
            );
            $hasNext = true;
            while ($hasNext) {
                $branchData = $this->getContents($resource)->decodeJson();
                foreach ($branchData['values'] as $data) {
                    $this->branches[$data['displayId']] = $data['latestCommit'];
                }
                if (isset($branchData['isLastPage']) && $branchData['isLastPage'] === true) {
                    $hasNext = false;
                } else {
                    $resource = sprintf(
                        'https://%s/rest/api/1.0/projects/%s/repos/%s/branches?%s',
                        $this->originUrl,
                        $this->owner,
                        $this->repository,
                        http_build_query(
                            array(
                                'start' => $branchData['nextPageStart'],
                                'limit' => 100,
                                'orderBy' => 'MODIFICATION',
                            ),
                            null,
                            '&'
                        )
                    );
                }
            }
        }

        return $this->branches;
    }

    protected function attemptCloneFallback()
    {
        try {
            $this->setupFallbackDriver($this->generateSshUrl());

            return true;
        } catch (\RuntimeException $e) {
            $this->fallbackDriver = null;

            $this->io->writeError(
                '<error>Failed to clone the ' . $this->generateSshUrl() . ' repository, try running in interactive mode'
                . ' so that you can enter your Bitbucket Server OAuth consumer credentials</error>'
            );
            throw $e;
        }
    }

    /**
     * @param  array $cloneLinks
     * @return void
     */
    protected function parseCloneUrls(array $cloneLinks)
    {
        foreach ($cloneLinks as $cloneLink) {
            if ($cloneLink['name'] === 'http') {
                $this->cloneHttpsUrl = preg_replace('/https:\/\/([^@]+@)?/', 'https://', $cloneLink['href']);
            }
        }
    }

    /**
     * @return array|null
     *
     * @see https://docs.atlassian.com/bitbucket-server/rest/6.4.0/bitbucket-rest.html#idp183
     */
    protected function getMainBranchData()
    {
        $resource = sprintf(
            'https://%s/rest/api/1.0/projects/%s/repos/%s/branches/default',
            $this->originUrl,
            $this->owner,
            $this->repository
        );

        $data = $this->getContents($resource)->decodeJson();
        if (isset($data['displayId'])) {
            return array('name' => $data['displayId']);
        }

        return null;
    }

    public function getRootIdentifier(): string
    {
        if ($this->fallbackDriver) {
            return $this->fallbackDriver->getRootIdentifier();
        }

        if (null === $this->rootIdentifier) {
            if (! $this->getRepoData()) {
                return $this->fallbackDriver->getRootIdentifier();
            }

            if ($this->vcsType !== 'git') {
                throw new \RuntimeException(
                    $this->url.' does not appear to be a git repository, use '. $this->cloneHttpsUrl
                );
            }

            $mainBranchData = $this->getMainBranchData();
            $this->rootIdentifier = !empty($mainBranchData['name']) ? $mainBranchData['name'] : 'master';
        }

        return $this->rootIdentifier;
    }

    /**
     * {@inheritdoc}
     */
    protected function generateSshUrl()
    {
        return 'git@' . $this->originUrl . '/' . $this->owner.'/'.$this->repository.'.git';
    }

    /**
     * {@inheritdoc}
     */
    protected function setupFallbackDriver($url)
    {
        $this->fallbackDriver = new GitDriver(
            array('url' => $url),
            $this->io,
            $this->config,
            $this->httpDownloader,
            $this->process
        );
        $this->fallbackDriver->initialize();
    }

    /**
     * @param  array<string> $configuredDomains
     * @param  string        $guessedDomain
     * @param  array<string> $urlParts
     * @param string         $portNumber
     *
     * @return string|false
     */
    private static function determineOrigin(array $configuredDomains, string $guessedDomain, array &$urlParts, ?string $portNumber)
    {
        $guessedDomain = strtolower($guessedDomain);

        if (in_array($guessedDomain, $configuredDomains) || (null !== $portNumber && in_array($guessedDomain.':'.$portNumber, $configuredDomains))) {
            if (null !== $portNumber) {
                return $guessedDomain.':'.$portNumber;
            }

            return $guessedDomain;
        }

        if (null !== $portNumber) {
            $guessedDomain .= ':'.$portNumber;
        }

        while (null !== ($part = array_shift($urlParts))) {
            $guessedDomain .= '/' . $part;

            if (in_array($guessedDomain, $configuredDomains) || (null !== $portNumber && in_array(Preg::replace('{:\d+}', '', $guessedDomain), $configuredDomains))) {
                return $guessedDomain;
            }
        }

        return false;
    }
}
