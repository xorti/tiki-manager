<?php
// Copyright (c) 2016, Avan.Tech, et. al.
// Copyright (c) 2008, Luis Argerich, Garland Foster, Eduardo Polidor, et. al.
// All Rights Reserved. See copyright.txt for details and a complete list of authors.
// Licensed under the GNU LESSER GENERAL PUBLIC LICENSE. See license.txt for details.

namespace TikiManager\Application;

use TikiManager\Access\ShellPrompt;
use TikiManager\Libs\Database\Database;
use TikiManager\Repository\SVN;

define('SVN_TIKIWIKI_URI', getenv('SVN_TIKIWIKI_URI') ?: 'https://svn.code.sf.net/p/tikiwiki/code');

class Tiki extends Application
{
    private $installType = null;
    private $branch = null;
    private $installed = null;

    public function backupDatabase($target)
    {
        $access = $this->instance->getBestAccess('scripting');
        if ($access instanceof ShellPrompt) {
            $randomName = md5(time() . 'trimbackup') . '.sql.gz';
            $remoteFile = $this->instance->getWorkPath($randomName);
            $access->runPHP(
                dirname(__FILE__) . '/../../scripts/tiki/backup_database.php',
                [$this->instance->webroot, $remoteFile]
            );
            $localName = $access->downloadFile($remoteFile);
            $access->deleteFile($remoteFile);

            `zcat $localName > '$target'`;
            unlink($localName);
        } else {
            $data = $access->runPHP(
                dirname(__FILE__) . '/../../scripts/tiki/mysqldump.php'
            );
            file_put_contents($target, $data);
        }
    }

    public function beforeChecksumCollect()
    {
        $this->removeTemporaryFiles();
    }

    public function extractTo(Version $version, $folder)
    {
        if (file_exists($folder)) {
            `svn revert --recursive  $folder`;
            `svn cleanup $folder`;
            `svn up --non-interactive $folder`;
        } else {
            $command = $this->getExtractCommand($version, $folder);
            `$command`;
        }
    }

    /**
     * Get SVN revision information
     *
     * @param string|null $folder If valid folder or null it will collect the svn revision from the folder|instance webroot.
     * @return int
     */
    public function getRevision($folder = null)
    {

        $svnInfo = '';

        if (file_exists($folder)) {
            $svnInfo = `svn info $folder`;
        }

        if (is_null($folder)) {
            $access = $this->instance->getBestAccess('scripting');
            if ($access instanceof ShellPrompt && $access->hasExecutable('svn')) {
                $host = $access->getHost();
                $svnInterpreter = $access->getSVNPath();
                $svnInfo = $host->runCommands("$svnInterpreter info {$this->instance->webroot}");
            }
        }

        if (! empty($svnInfo)) {
            preg_match('/(.*Rev:\s+)(.*)/', $svnInfo, $matches);
            return $matches[2];
        }

        return 0;
    }

    public function fixPermissions()
    {
        $access = $this->instance->getBestAccess('scripting');

        if ($access instanceof ShellPrompt) {
            $webroot = $this->instance->webroot;
            $access->chdir($this->instance->webroot);

            if ($this->instance->hasConsole()) {
                $ret = $access->shellExec("cd $webroot && bash setup.sh -n fix");    // does composer as well
            } else {
                warning('Old Tiki detected, running bundled Tiki Manager setup.sh script.');
                $filename = $this->instance->getWorkPath('setup.sh');
                $access->uploadFile(dirname(__FILE__) . '/../../scripts/setup.sh', $filename);
                $ret = $access->shellExec("cd $webroot && bash " . escapeshellarg($filename));
            }
        }
    }

    private function formatBranch($version)
    {
        if (substr($version, 0, 4) == '1.9.') {
            return 'REL-' . str_replace('.', '-', $version);
        } elseif ($this->getInstallType() == 'svn') {
            return "tags/$version";
        } elseif ($this->getInstallType() == 'tarball') {
            return "tags/$version";
        }
    }

    public function getAcceptableExtensions()
    {
        return ['mysqli', 'mysql'];
    }

    public function getBranch()
    {
        if ($this->branch) {
            return $this->branch;
        }

        if ($this->getInstallType() == 'svn') {
            $access = $this->instance->getBestAccess('scripting');
            $svn = new SVN(SVN_TIKIWIKI_URI, $access);
            $webroot = $this->instance->webroot;
            if ($branch = $svn->getRepositoryBranch($webroot)) {
                info("Detected SVN : $branch");
                return $this->branch = $branch;
            }
        }

        $access = $this->instance->getBestAccess('filetransfer');
        $content = $access->fileGetContents(
            $this->instance->getWebPath('tiki-setup.php')
        );

        if (preg_match(
            "/tiki_version\s*=\s*[\"'](\d+\.\d+\.\d+(\.\d+)?)/",
            $content,
            $matches
        )) {
            $version = $matches[1];
            $branch = $this->formatBranch($version);

            echo 'The branch provided may not be correct. ' .
                "Until 1.10 is tagged, use branches/1.10.\n";
            $entry = readline("If this is not correct, enter the one to use: [$branch]");
            if (! empty($entry)) {
                return $this->branch = $entry;
            } else {
                return $this->branch = $branch;
            }
        }

        $content = $access->fileGetContents(
            $this->instance->getWebPath('lib/setup/twversion.class.php')
        );
        if (empty($content)) {
            $content = $access->fileGetContents(
                $this->instance->getWebPath('lib/twversion.class.php')
            );
        }

        if (preg_match(
            "/this-\>version\s*=\s*[\"'](\d+\.\d+(\.\d+)?(\.\d+)?(\w+)?)/",
            $content,
            $matches
        )) {
            $version = $matches[1];
            $branch = $this->formatBranch($version);

            if (strpos($branch, 'branches/1.10') === 0) {
                $branch = 'branches/2.0';
            }

            $entry = readline("If this is not correct, enter the one to use: [$branch]");
            if (! empty($entry)) {
                return $this->branch = $entry;
            }
        } else {
            $branch = '';
            while (empty($branch)) {
                $branch = readline("No version found. Which tag should be used? (Ex.: (Subversion) branches/1.10) ");
            }
        }

        return $this->branch = $branch;
    }

    private function getExtractCommand($version, $folder)
    {
        if ($version->type == 'svn' || $version->type == 'tarball') {
            $branch = SVN_TIKIWIKI_URI . "/{$version->branch}";
            $branch = str_replace('/./', '/', $branch);
            $branch = escapeshellarg($branch);
            return "svn co $branch $folder";
        }
    }

    public function getFileLocations()
    {
        $access = $this->instance->getBestAccess('scripting');
        $out = $access->runPHP(
            dirname(__FILE__) . '/../../scripts/tiki/get_directory_list.php',
            [$this->instance->webroot]
        );

        $folders['app'] = [$this->instance->webroot];

        foreach (explode("\n", $out) as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $line = rtrim($line, '/');

            if (! empty($line)) {
                $folders['data'][] = $line;
            }
        }

        return $folders;
    }

    public function getInstallType()
    {
        if (! is_null($this->installType)) {
            return $this->installType;
        }

        $access = $this->instance->getBestAccess('filetransfer');
        $checkpaths = [
            $this->instance->getWebPath('.svn/entries'),
            $this->instance->getWebPath('.svn/wc.db')
        ];

        foreach ($checkpaths as $path) {
            if ($access->fileExists($path)) {
                return $this->installType = 'svn';
            }
        }
        return $this->installType = 'tarball';
    }

    public function getName()
    {
        return 'tiki';
    }

    public function getSourceFile(Version $version, $filename)
    {
        $dot = strrpos($filename, '.');
        $ext = substr($filename, $dot);

        $local = tempnam(TEMP_FOLDER, 'trim');
        rename($local, $local . $ext);
        $local .= $ext;

        $sourcefile = SVN_TIKIWIKI_URI . "/{$version->branch}/$filename";
        $sourcefile = str_replace('/./', '/', $sourcefile);

        $content = file_get_contents($sourcefile);
        file_put_contents($local, $content);

        return $local;
    }

    public function getUpdateDate()
    {
        $access = $this->instance->getBestAccess('filetransfer');
        $date = $access->fileModificationDate($this->instance->getWebPath('tiki-setup.php'));

        return $date;
    }

    public function getVersions()
    {
        $versions = [];

        $base = SVN_TIKIWIKI_URI;
        $versionsTemp = [];
        foreach (explode("\n", `svn ls $base/tags`) as $line) {
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
        foreach (explode("\n", `svn ls $base/branches`) as $line) {
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

        $versions_sorted = [];
        foreach ($versions as $version) {
            list($type, $branch) = explode(':', $version);
            $versions_sorted[] = Version::buildFake($type, $branch);
        }

        return $versions_sorted;
    }

    public function install(Version $version)
    {
        $access = $this->instance->getBestAccess('scripting');
        $host = $access->getHost();

        $folder = cache_folder($this, $version);
        $this->extractTo($version, $folder);

        if ($access instanceof ShellPrompt) {
            $host->rsync([
                'src' =>  rtrim($folder, '/') . '/',
                'dest' => rtrim($this->instance->webroot, '/') . '/'
            ]);
        } else {
            $access->copyLocalFolder($folder);
        }

        $this->branch = $version->branch;
        $this->installType = $version->type;
        $this->installed = true;

        $version = $this->registerCurrentInstallation();
        $this->fixPermissions(); // it also runs composer!

        if (! $access->fileExists($this->instance->getWebPath('.htaccess'))) {
            $access->copyFile(
                $this->instance->getWebPath('_htaccess'),
                $this->instance->getWebPath('.htaccess')
            );
        }

        if ($access instanceof ShellPrompt) {
            $access->shellExec('touch ' .
                escapeshellarg($this->instance->getWebPath('db/lock')));
        }

        $version->collectChecksumFromInstance($this->instance);
    }

    public function installProfile($domain, $profile)
    {
        $access = $this->instance->getBestAccess('scripting');

        echo $access->runPHP(
            dirname(__FILE__) . '/../../scripts/tiki/remote_install_profile.php',
            [$this->instance->webroot, $domain, $profile]
        );
    }

    public function isInstalled()
    {
        if (! is_null($this->installed)) {
            return $this->installed;
        }

        $access = $this->instance->getBestAccess('filetransfer');
        $checkpath = $this->instance->getWebPath('tiki-setup.php');
        $this->installed = $access->fileExists($checkpath);
        return $this->installed;
    }

    public function performActualUpdate(Version $version)
    {
        switch ($this->getInstallType()) {
            case 'svn':
            case 'tarball':
                $access = $this->instance->getBestAccess('scripting');

                if ($access instanceof ShellPrompt && $access->hasExecutable('svn')) {
                    info('Updating svn...');
                    $webroot = $this->instance->webroot;

                    $escaped_root_path = escapeshellarg(rtrim($this->instance->webroot, '/\\'));
                    $escaped_temp_path = escapeshellarg(rtrim($this->instance->getWebPath('temp'), '/\\'));
                    $escaped_cache_path = escapeshellarg(rtrim($this->instance->getWebPath('temp/cache'), '/\\'));

                    $access->shellExec("{$this->instance->phpexec} -q -d memory_limit=256M console.php cache:clear --all");

                    $svn = new SVN(SVN_TIKIWIKI_URI, $access);
                    $svn->revert($webroot, ['--recursive']);
                    $svn->cleanup($webroot);

                    $svn->updateInstanceTo($this->instance->webroot, $version->branch);
                    $access->shellExec("chmod 0777 {$escaped_temp_path} {$escaped_cache_path}");

                    if ($this->instance->hasConsole()) {
                        info('Updating composer');

                        $ret = $access->shellExec([
                          "sh {$escaped_root_path}/setup.sh composer",
                          "{$this->instance->phpexec} -q -d memory_limit=256M console.php cache:clear --all",
                        ]);
                    }
                } elseif ($access instanceof \TikiManager\Access\Mountable) {
                    $folder = cache_folder($this, $version);
                    $this->extractTo($version, $folder);
                    $access->copyLocalFolder($folder);
                }

                info('Updating database schema...');

                if ($this->instance->hasConsole()) {
                    $ret = $access->shellExec([
                      "{$this->instance->phpexec} -q -d memory_limit=256M console.php database:update"
                    ]);
                } else {
                    $access->runPHP(
                        dirname(__FILE__) . '/../../scripts/tiki/sqlupgrade.php',
                        [$this->instance->webroot]
                    );
                }

                info('Fixing permissions...');

                $this->fixPermissions();
                $access->shellExec('touch ' . escapeshellarg($this->instance->getWebPath('db/lock')));

                if ($this->instance->hasConsole()) {
                    info('Rebuilding Index...');
                    $access->shellExec("{$this->instance->phpexec} -q -d memory_limit=256M console.php index:rebuild --log");
                    info('Cleaning Cache...');
                    $access->shellExec("{$this->instance->phpexec} -q -d memory_limit=256M console.php cache:clear");
                    info('Generating Caches...');
                    $access->shellExec("{$this->instance->phpexec} -q -d memory_limit=256M console.php cache:generate");
                }

                return;
        }

        // TODO: Handle fallback
    }

    public function performActualUpgrade(Version $version, $abort_on_conflict)
    {
        switch ($this->getInstallType()) {
            case 'svn':
            case 'tarball':
                $access = $this->instance->getBestAccess('scripting');
                $access->getHost(); // trigger the config of the location change (to catch phpenv)

                if ($access instanceof ShellPrompt && $access->hasExecutable('svn')) {
                    info('Upgrading svn...');
                    $access->shellExec("{$this->instance->phpexec} {$this->instance->webroot}/console.php cache:clear");

                    $svn = new SVN(SVN_TIKIWIKI_URI, $access);
                    $svn->updateInstanceTo($this->instance->webroot, $version->branch);
                    $access->shellExec('chmod 0777 temp temp/cache');

                    if ($this->instance->hasConsole()) {
                        info('Updating composer...');

                        $ret = $access->shellExec([
                        "sh setup.sh composer",
                        "{$this->instance->phpexec} -q -d memory_limit=256M console.php cache:clear --all",
                        ]);
                    }

                    info('Updating database schema...');

                    $access->runPHP(
                        dirname(__FILE__) . '/../../scripts/tiki/sqlupgrade.php',
                        [$this->instance->webroot]
                    );

                    info('Fixing permissions...');

                    $this->fixPermissions();
                    $access->shellExec('touch ' . escapeshellarg($this->instance->getWebPath('db/lock')));

                    if ($this->instance->hasConsole()) {
                        info('Rebuilding Index...');
                        $access->shellExec("{$this->instance->phpexec} -q -d memory_limit=256M console.php index:rebuild --log");
                        info('Cleaning Cache...');
                        $access->shellExec("{$this->instance->phpexec} -q -d memory_limit=256M console.php cache:clear");
                        info('Generating Caches...');
                        $access->shellExec("{$this->instance->phpexec} -q -d memory_limit=256M console.php cache:generate");
                    }

                    return;
                }
        }
    }

    public function removeTemporaryFiles()
    {
        $access = $this->instance->getBestAccess('scripting');
        $escaped_root_path = escapeshellarg(rtrim($this->instance->webroot, '/\\'));

        // FIXME: Not FTP compatible
        if ($access instanceof ShellPrompt) {
            $access->shellExec("{$this->instance->phpexec} {$this->instance->webroot}/console.php cache:clear --all");
            $access->shellExec("svn cleanup --non-interactive {$escaped_root_path}");
        }
    }

    public function requiresDatabase()
    {
        return true;
    }

    public function restoreDatabase(Database $database, $remoteFile)
    {
        $tmp = tempnam(TEMP_FOLDER, 'dblocal');

        if (!empty($database->dbLocalContent)) {
            file_put_contents($tmp, $database->dbLocalContent);
        } else {
            file_put_contents($tmp, "<?php"          . "\n"
                ."\$db_tiki='{$database->type}';"    . "\n"
                ."\$host_tiki='{$database->host}';"  . "\n"
                ."\$user_tiki='{$database->user}';"  . "\n"
                ."\$pass_tiki='{$database->pass}';"  . "\n"
                ."\$dbs_tiki='{$database->dbname}';" . "\n"
                ."// generated by Tiki Manager " . date('Y-m-d H:i:s +Z'));
        }

        $access = $this->instance->getBestAccess('filetransfer');
        $access->uploadFile($tmp, 'db/local.php');

        $access = $this->instance->getBestAccess('scripting');
        $root = $this->instance->webroot;

        // FIXME: Not FTP compatible (arguments)
        info("Loading '$remoteFile' into '{$database->dbname}'");
        $access->runPHP(
            dirname(__FILE__) . '/../../scripts/tiki/run_sql_file.php',
            [$root, $remoteFile]
        );
    }

//----------------------------------------------------------------
    public function setupDatabase(Database $database)
    {
        $tmp = tempnam(TEMP_FOLDER, 'dblocal');
        file_put_contents($tmp, "<?php"          . "\n"
            ."\$db_tiki='{$database->type}';"    . "\n"
            ."\$host_tiki='{$database->host}';"  . "\n"
            ."\$user_tiki='{$database->user}';"  . "\n"
            ."\$pass_tiki='{$database->pass}';"  . "\n"
            ."\$dbs_tiki='{$database->dbname}';" . "\n"
            ."\$client_charset = 'utf8';"        . "\n"
            ."// generated by Tiki Manager " . date('Y-m-d H:i:s +Z'));

        $access = $this->instance->getBestAccess('filetransfer');
        $access->uploadFile($tmp, 'db/local.php');
        $access->shellExec("chmod 0664 {$this->instance->webroot}/db/local.php");
        // TODO: Hard-coding: 'apache:apache'
        // TODO: File ownership under the webroot should be configurable per instance.
        $access->shellExec("chown apache:apache {$this->instance->webroot}/db/local.php");

        if ($access->fileExists('console.php') && $access instanceof ShellPrompt) {
            info("Updating svn, composer, perms & database...");

            $access = $this->instance->getBestAccess('scripting');
            $access->chdir($this->instance->webroot);
            $ret = $access->shellExec([
                "{$this->instance->phpexec} -q -d memory_limit=256M console.php database:install",
                'touch ' . escapeshellarg($this->instance->getWebPath('db/lock')),
            ]);
        } elseif ($access->fileExists('installer/shell.php')) {
            if ($access instanceof ShellPrompt) {
                $access = $this->instance->getBestAccess('scripting');
                $access->chdir($this->instance->webroot);
                $access->shellExec($this->instance->phpexec . ' installer/shell.php install');
            } else {
                $access->runPHP(
                    dirname(__FILE__) . '/../../scripts/tiki/tiki_dbinstall_ftp.php',
                    [$this->instance->webroot]
                );
            }
        } else {
            // FIXME: Not FTP compatible ? prior to 3.0 only
            $access = $this->instance->getBestAccess('scripting');
            $file = $this->instance->getWebPath('db/tiki.sql');
            $root = $this->instance->webroot;
            $access->runPHP(
                dirname(__FILE__) . '/../../scripts/tiki/run_sql_file.php',
                [$root, $file]
            );
            $access->runPHP(
                dirname(__FILE__) . '/../../scripts/tiki/sqlupgrade.php',
                [$this->instance->webroot]
            );
        }

        echo "Verify if you have db/local.php file, if you don't put the following content in it.\n";
        echo "<?php"                             . "\n"
            ."\$db_tiki='{$database->type}';"    . "\n"
            ."\$host_tiki='{$database->host}';"  . "\n"
            ."\$user_tiki='{$database->user}';"  . "\n"
            ."\$pass_tiki='{$database->pass}';"  . "\n"
            ."\$dbs_tiki='{$database->dbname}';" . "\n"
            ."\$client_charset = 'utf8';"        . "\n"
            ."// generated by Tiki Manager " . date('Y-m-d H:i:s +Z')
            . "\n";
    }
}

// vi: expandtab shiftwidth=4 softtabstop=4 tabstop=4