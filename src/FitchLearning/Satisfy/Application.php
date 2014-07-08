<?php
/**
 * Created by PhpStorm.
 * User: rgeorge
 * Date: 31/10/13
 * Time: 10:24
 */

namespace FitchLearning\Satisfy;


class Application
{

    protected $packages;
    protected $repoDefinition;
    protected $outputFile = false; // false => stdout

    /**
     * Load a file specifying the git repositories to be scanned for packages
     *
     * @param $filename
     * @throws \Exception
     */
    public function loadPackagesFromFile($filename)
    {
        $raw = file_get_contents($filename);

        if ($raw === false) {
            throw new \Exception("Cannot open $filename");
        }
        $packages = json_decode($raw, true);
        if (is_null($packages)) {
            throw new \Exception("Cannot parse package list in $filename");
        }
        $this->packages = $packages;
    }

    /*
      Correct JSON format for packages file is:
      {
    "frontend/fontawesome": {
        "url": "git://github.com/FortAwesome/Font-Awesome.git",
        "minversion": "2.0",
        "defaults": {
            "homepage": "http://fontawesome.io/"
        }
    },
    "frontend/bootstrap": {
        "url": "git://github.com/twbs/bootstrap.git",
        "minversion": "2.0",
        "defaults": [

        ]
    },
    "brightcove/brightcove-phpapi": {
        "url": "https://github.com/BrightcoveOS/PHP-MAPI-Wrapper.git",
        "defaults": {
            "autoload": {
                "classmap": [
                    "."
                ]
            }
        }
    }
    }

    */


    /**
     * Load a base satis.json file to which found packages will be added
     *
     * @param $filename
     * @throws \Exception
     */
    public function loadRepoDefinitionFromFile($filename)
    {
        $raw = file_get_contents($filename);

        if ($raw === false) {
            throw new \Exception("Cannot open $filename");
        }
        $repo = json_decode($raw, true);
        if (is_null($repo)) {
            throw new \Exception("Cannot parse repo definition in $filename");
        }

        if (!isset($repo['repositories'])) {
            throw new \Exception("Repo file must contain repositories member, even if empty");
        }
        $this->repoDefinition = $repo;
    }

    public function run()
    {
        $repoDefinition = $this->getRepoDefinition();

        $packages = $this->getRequiredPackageList();
        $repo = $this->addPackagesToRepo($packages, $repoDefinition);
        $repo = $this->removeProcessedRepos($packages, $repo);

        $output = json_encode($repo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($this->outputFile) {
            file_put_contents($this->outputFile, $output);
        } else {
            print $output;
        }
    }

    protected function getRemoteUrlTags($vcsUrl)
    {
        $tags = [];
        $gitPath = 'git';
        $cmd = "$gitPath ls-remote --tags " . escapeshellarg($vcsUrl);
        $returnValue = null;
        $output = [];

        exec($cmd, $output, $returnValue);

        if (!$returnValue) {
            foreach ($output as $reference) {
                $parts = preg_split('/\s+/', $reference, -1, PREG_SPLIT_NO_EMPTY);
                if (count($parts) == 2) {
                    if (preg_match('#refs/tags/(.*[^}])$#', $parts[1], $matches)) {
                        $tags[] = $matches[1];
                    }
                }
            }
        }

        return $tags;
    }

    protected function getVersionFromString($string)
    {
        $version = false;
        if (preg_match('/^v?(\d+\.\d+\.\d+)(-((rc|alpha|beta)\.?(\d+)))?$/', $string, $matches)) {
            $version = $matches[1];
            if (count($matches) >= 5) {
                $version .= '-' . $matches[4];
            }
            if (count($matches) >= 6) {
                $version .= $matches[5];
            }
        }

        return $version;
    }

    /**
     * @param $vcsUrl
     * @return array
     */
    protected function getValidTagsAtUri($vcsUrl)
    {
        $tags = $this->getRemoteUrlTags($vcsUrl);
        $tagsSorted = [];
        foreach ($tags as $tag) {
            if ($version = $this->getVersionFromString($tag)) {
                $tagsSorted[$version] = $tag;
            }
        }

        uksort(
            $tagsSorted,
            function ($a, $b) {
                return version_compare($a, $b);
            }
        );
        return $tagsSorted;
    }

    /**
     * @return array
     */
    protected function getRequiredPackageList()
    {
        return $this->packages;
    }

    /**
     * Scan the VCS URIs in $packages and merge results into $repoDefinition
     *
     * TODO this is probably silly encapsulation
     *
     * @param $packages
     * @param $repoDefinition
     * @throws \Exception
     * @return
     */
    protected function addPackagesToRepo($packages, $repoDefinition)
    {
        $foundPackages = [];

        //        $tagsByPackage = [];
        foreach ($packages as $name => $definition) {
            $vcsUrl = $definition['url'];
            $tagsSorted = $this->getValidTagsAtUri($vcsUrl);

            if (!$tagsSorted) {
                throw new \Exception("No tags found for $name");
            }

            foreach ($tagsSorted as $version => $tag) {

                $keepVersion = true;
                if (isset($definition['minversion'])) {
                    if (version_compare($definition['minversion'], $version, '>')) {
                        $keepVersion = false;
                    }
                }

                if ($keepVersion) {
                    if (isset($definition['defaults'])) {
                        $packageDefinition = $definition['defaults'];
                    } else {
                        $packageDefinition = [];
                    }

                    if (isset($packageDefinition['description'])) {
                        $packageDefinition['description'] .= '; Autogenerated by satisfy';
                    } else {
                        $packageDefinition['description'] = 'Autogenerated by satisfy';
                    }

                    $packageDefinition['name'] = $name;
                    $packageDefinition['version'] = $version;
                    $packageDefinition["source"] = [
                        "url" => $vcsUrl,
                        "type" => "git",
                        "reference" => $tag
                    ];

                    $foundPackages[] = [
                        "type" => "package",
                        "package" => $packageDefinition
                    ];
                }
            }
        }
        $repoDefinition['repositories'] = array_merge($repoDefinition['repositories'], $foundPackages);

        return $repoDefinition;
    }

    /**
     * Remove git repositories defined in packagelist from results.
     * If they could be processed by satis, then no sense to run satisfy.
     *
     * @param array $packages
     * @param array $repo
     * @return array
     */
    protected function removeProcessedRepos($packages, $repo) {
        $processed_repos = [];
        foreach($packages as $package) {
            $processed_repos[] = $package['url'];
        }

        $new_repo = $repo;
        $new_repo['repositories'] = [];
        foreach ($repo['repositories'] as $repository) {
            if ($repository['type'] == 'git' && in_array($repository['url'], $processed_repos)) {
                continue;
            }
            else {
                $new_repo['repositories'][] = $repository;
            }
        }

        return $new_repo;
    }

    /**
     * @return array
     */
    protected function getRepoDefinition()
    {
        return $this->repoDefinition;
    }

    /**
     * @param boolean $outputFile
     */
    public function setOutputFile($outputFile)
    {
        $this->outputFile = $outputFile;
    }
}
