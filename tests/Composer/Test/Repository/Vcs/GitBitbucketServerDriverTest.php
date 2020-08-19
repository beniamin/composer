<?php

namespace Composer\Test\Repository\Vcs;

use Composer\Repository\Vcs\BitbucketServerDriver;
use Composer\Test\TestCase;
use Composer\Util\Filesystem;

class GitBitbucketServerDriverTest extends TestCase
{

    public function setUp(): void
    {
        $this->home = $this->getUniqueTmpDirectory();
        $this->config = $this->getConfig([
            'home' => $this->home,
            'bitbucket-server-domains' => array(
                'mycompany.com/bitbucket',
                'bitbucket.mycompany.com',
                'stash.mycompany.com',
                'othercompany.com/nested/bitbucket',
                'bitbucket.mycompany.local',
                'stash.mycompany.local',
            ),
        ]);

        $this->io = $this->getMockBuilder('Composer\IO\IOInterface')->disableOriginalConstructor()->getMock();
        $this->process = $this->getMockBuilder('Composer\Util\ProcessExecutor')->getMock();
        $this->httpDownloader = $this->getHttpDownloaderMock();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $fs = new Filesystem();
        $fs->removeDirectory($this->home);
    }


    public function provideInitializeUrls(): array
    {
        return array(
            array('ssh://user@bitbucket.mycompany.com/~my.user/repo.git'),
            array('https://bitbucket.mycompany.com/~my.user/repo.git'),
            array('https://bitbucket.mycompany.com/scm/project/repo.git'),
            array('ssh://user@bitbucket.mycompany.com/project/repo.git'),
        );
    }

    /**
     * @dataProvider provideInitializeUrls
     *
     * @param string $url
     * @param string $apiUrl
     */
    public function testInitialize(string $url): BitbucketServerDriver
    {
        $projectData = <<<JSON
{
    "id": 17,
    "default_branch": "mymaster",
    "visibility": "private",
    "issues_enabled": true,
    "archived": false,
    "http_url_to_repo": "https://bitbucket.mycompany.com/project/repo.git",
    "ssh_url_to_repo": "ssh@user:bitbucket.mycompany.com/project/repo.git",
    "last_activity_at": "2014-12-01T09:17:51.000+01:00",
    "name": "My Project",
    "name_with_namespace": "My Project / My Repository",
    "path": "myproject",
    "path_with_namespace": "project/repository",
    "web_url": "https://bitbucket.mycompany.com/vcs/project/PROJ/repos/repo"
}
JSON;

        $this->httpDownloader->expects(
            [['url' => $url, 'body' => $projectData]],
            true
        );

        $driver = new BitbucketServerDriver(array('url' => $url), $this->io, $this->config, $this->httpDownloader, $this->process);
        $driver->initialize();
//
//        $this->assertEquals($apiUrl, $driver->getApiUrl(), 'API URL is derived from the repository URL');
//        $this->assertEquals('mymaster', $driver->getRootIdentifier(), 'Root identifier is the default branch in GitLab');
//        $this->assertEquals('git@gitlab.com:mygroup/myproject.git', $driver->getRepositoryUrl(), 'The repository URL is the SSH one by default');
//        $this->assertEquals('https://gitlab.com/mygroup/myproject', $driver->getUrl());


//        var_dump($driver);

        return $driver;
    }

}
