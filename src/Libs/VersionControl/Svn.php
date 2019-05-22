<?php
/**
 * @copyright (c) Copyright by authors of the Tiki Manager Project. All Rights Reserved.
 *     See copyright.txt for details and a complete list of authors.
 * @licence Licensed under the GNU LESSER GENERAL PUBLIC LICENSE. See LICENSE for details.
 */

namespace TikiManager\Libs\VersionControl;

use Exception;
use TikiManager\Application\Version;
use TikiManager\Libs\Host\Command;

class Svn extends VersionControlSystem
{
    const SVN_TEMP_FOLDER_PATH = DIRECTORY_SEPARATOR . '.svn' . DIRECTORY_SEPARATOR . 'tmp';

    /**
     * SVN constructor.
     * @param $access
     */
    public function __construct($access)
    {
        parent::__construct($access);
        $this->command = 'svn';
        $this->repositoryUrl = SVN_TIKIWIKI_URI;
    }

    /**
     * Gets the specific repository URL for this class
     * @return string
     */
    protected function getRepositoryUrl()
    {
        return SVN_TIKIWIKI_URI;
    }

    public function getRepositoryBranch($targetFolder)
    {
        $info = $this->info($targetFolder);
        $url = $info['url'];
        $root = $info['repository']['root'];
        $branchIndex = strlen($root);
        $branchName = substr($url, $branchIndex);
        $branchName = trim($branchName, '/');

        return $branchName;
    }

    public function getAvailableBranches()
    {
        $versions = [];
        $versionsTemp = [];

        foreach (explode("\n", `svn ls $this->repositoryUrl/tags`) as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            if (substr($line, -1) == '/' && ctype_digit($line{0})) {
                $versionsTemp[] = 'svn:tags/' . substr($line, 0, -1);
            }
        }
        sort($versionsTemp, SORT_NATURAL);
        $versions = array_merge($versions, $versionsTemp);

        $versionsTemp = [];
        foreach (explode("\n", `svn ls $this->repositoryUrl/branches`) as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            if (substr($line, -1) == '/' && ctype_digit($line{0})) {
                $versionsTemp[] = 'svn:branches/' . substr($line, 0, -1);
            }
        }
        sort($versionsTemp, SORT_NATURAL);
        $versions = array_merge($versions, $versionsTemp);

        // Trunk as last option
        $versions[] = 'svn:trunk';

        $versionsSorted = [];
        foreach ($versions as $version) {
            list($type, $branch) = explode(':', $version);
            $versionsSorted[] = Version::buildFake($type, $branch);
        }

        return $versionsSorted;
    }

    public function exec($targetFolder, $toAppend, $forcePathOnCommand = false)
    {
        $command = new Command($this->command . " " . $toAppend);
        $result = $this->access->runCommand($command);

        if ($result->getReturn() !== 0) {
            throw new Exception($result->getStderrContent());
        }

        return rtrim($result->getStdoutContent(), "\n");
    }

    public function clone($branchName, $targetFolder)
    {
        $branch = $this->repositoryUrl . "/$branchName";
        $branch = str_replace('/./', '/', $branch);
        $branch = escapeshellarg($branch);

        return $this->exec($targetFolder, "co $branch $targetFolder");
    }

    public function revert($targetFolder)
    {
        return $this->exec($targetFolder, "revert $targetFolder --recursive")
            && $this->cleanup($targetFolder);
    }

    public function pull($targetFolder)
    {
        return $this->cleanup($targetFolder) &&
            $this->exec($targetFolder, "up --non-interactive $targetFolder");
    }

    public function cleanup($targetFolder)
    {
        return $this->exec($targetFolder, "cleanup $targetFolder");
    }

    public function merge($targetFolder, $branch)
    {
        $toAppend = '';

        if (preg_match('/^(\w+):(\w+)$/', $branch)) {
            $toAppend .= "--revision {$branch} ";
        } elseif (is_numeric($branch)) {
            $toAppend .= "--change {$branch}";
        } else {
            $branch = $this->getBranchUrl($branch);
        }

        return $this->exec($targetFolder, "merge $toAppend --accept theirs-full --allow-mixed-revisions --dry-run");
    }

    public function info($targetFolder, $raw = false)
    {
        $cmd = "info $targetFolder";
        if (! $raw) {
            $cmd .= ' --xml';
        }

        $xml = $this->exec($targetFolder, $cmd);

        if ($raw) {
            return $xml;
        }

        $xml = simplexml_load_string($xml);

        $curNode = $xml->entry;
        $result = [];
        $stack = [
            [$curNode, &$result]
        ];

        while (! empty($stack)) {
            $stack_item = array_pop($stack);
            $curNode = $stack_item[0];
            $output = &$stack_item[1];

            $nodeName = $curNode->getName();
            $node_children = $curNode->children();

            if (empty($node_children)) {
                $value = sprintf('%s', $curNode);
                $value = is_numeric($value) ? float($value) : $value;
                $output[ $nodeName ] = $value;
                continue;
            } else {
                $output[ $nodeName ] = [];

                foreach ($node_children as $nodeChild) {
                    $stack[] = [$nodeChild, &$output[ $nodeName ]];
                }
            }
        }

        $result = !empty($result['entry']) ? $result['entry'] : [];
        return $result;
    }

    public function getRevision($targetFolder)
    {
        $info = $this->info($targetFolder, true);

        if (! empty($info)) {
            preg_match('/(.*Rev:\s+)(.*)/', $info, $matches);
            return $matches[2];
        }

        return 0;
    }

    public function checkoutBranch($targetFolder, $branch)
    {
        return $this->exec($targetFolder, "--non-interactive switch $branch $target_folder");
    }

    public function upgrade($targetFolder, $branch)
    {
        $this->revert($targetFolder);
        return $this->checkoutBranch($targetFolder, $branch);
    }

    public function update($targetFolder, $branch)
    {
        $info = $this->info($targetFolder);
        $root = $info['repository']['root'];
        $url = $info['url'];
        $branchUrl = $root . "/" . $branch;

        if ($root != $this->repositoryUrl) {
            error("Trying to upgrade '{$this->repositoryUrl}' to different repository: {$root}");
            return false;
        }

        $conflicts = $this->merge($targetFolder, 'BASE:HEAD');

        if (strlen(trim($conflicts)) > 0 &&
            preg_match('/conflicts:/i', $conflicts)) {
            echo "SVN MERGE: $conflicts\n";

            if ('yes' == strtolower(promptUser(
                'It seems there are some conflicts. Type "yes" to exit and solve manually or "no" to discard changes. Exit?',
                INTERACTIVE ? 'yes' : 'no',
                array('yes', 'no')
            ))) {
                exit;
            }
        }

        if (! $this->access->fileExists($target_folder . self::SVN_TEMP_FOLDER_PATH)) {
            $path = $this->access->getInterpreterPath($this);
            $script = sprintf("mkdir('%s', 0777, true);", $target_folder . self::SVN_TEMP_FOLDER_PATH);
            $this->access->createCommand($path, ["-r {$script}"])->run();
        }

        if ($this->isUpgrade($url, $branch)) {
            info("Upgrading to '{$branch}'");
            $this->revert($targetFolder);
            $this->upgrade($targetFolder, $branch);
        } else {
            info("Updating '{$branch}'");
            $this->revert($targetFolder);
            $this->exec($targetFolder, "update $targetFolder --accept theirs-full --force");
        }

        $this->cleanup($targetFolder);
    }
}
