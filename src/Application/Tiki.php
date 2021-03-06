<?php
// Copyright (c) 2016, Avan.Tech, et. al.
// Copyright (c) 2008, Luis Argerich, Garland Foster, Eduardo Polidor, et. al.
// All Rights Reserved. See copyright.txt for details and a complete list of authors.
// Licensed under the GNU LESSER GENERAL PUBLIC LICENSE. See license.txt for details.

namespace TikiManager\Application;

use TikiManager\Access\Mountable;
use TikiManager\Access\ShellPrompt;
use TikiManager\Command\Helper\CommandHelper;
use TikiManager\Libs\Database\Database;
use TikiManager\Libs\VersionControl\Git;
use TikiManager\Libs\VersionControl\Src;
use TikiManager\Libs\VersionControl\Svn;
use TikiManager\Libs\VersionControl\VersionControlSystem;
use TikiManager\Libs\Helpers\ApplicationHelper;

class Tiki extends Application
{
    private $installType = null;
    private $branch = null;
    private $installed = null;
    /** @var Svn|Git|Src  */
    private $vcs_instance = null;

    public function __construct(Instance $instance)
    {
        parent::__construct($instance);
        $this->vcs_instance = VersionControlSystem::getVersionControlSystem($this->instance);
    }

    public function backupDatabase($target)
    {
        $access = $this->instance->getBestAccess('scripting');
        if ($access instanceof ShellPrompt && !(ApplicationHelper::isWindows() && $this->instance->type == 'local')) {
            $randomName = md5(time() . 'trimbackup') . '.sql.gz';
            $remoteFile = $this->instance->getWorkPath($randomName);
            $access->runPHP(
                dirname(__FILE__) . '/../../scripts/tiki/backup_database.php',
                [$this->instance->webroot, $remoteFile]
            );
            $localName = $access->downloadFile($remoteFile);
            $access->deleteFile($remoteFile);

            `cat $localName | gzip -d > '$target'`;
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
        $this->vcs_instance->setRunLocally(true);

        if (file_exists($folder)) {
            $this->vcs_instance->revert($folder);
            $this->vcs_instance->pull($folder);
        } else {
            $this->vcs_instance->clone($version->branch, $folder);
        }

        $this->vcs_instance->setRunLocally(false);
    }

    /**
     * Get repository revision information
     *
     * @param string|null $folder If valid folder or null it will collect the svn revision from the folder|instance webroot.
     * @return int
     */
    public function getRevision($folder = null)
    {
        $revision = '';
        $access = $this->instance->getBestAccess('scripting');
        $can_svn = $access->hasExecutable('svn') && $this->vcs_instance->getIdentifier() == 'SVN';
        $can_git = $access->hasExecutable('git') && $this->vcs_instance->getIdentifier() == 'GIT';

        if ($access instanceof ShellPrompt && ($can_git || $can_svn)) {
            $revision = $this->vcs_instance->getRevision($folder);
        }

        return $revision;
    }

    /**
     * Fixing files read/write permissions and run composer install
     * @throws \Exception
     */
    public function fixPermissions()
    {
        $instance = $this->instance;
        $access = $instance->getBestAccess('scripting');

        if ($instance->type === 'local' && !getenv('COMPOSER_HOME') && PHP_SAPI !== 'cli') {
            $access->setenv('COMPOSER_HOME', $_ENV['CACHE_FOLDER'] . DIRECTORY_SEPARATOR . '.composer');
        }

        if ($access instanceof ShellPrompt) {
            $access->chdir($instance->webroot);

            if ($instance->hasConsole()) {
                if ($instance->type == 'local' && ApplicationHelper::isWindows()) {
                    // TODO Requires implementation
                    // There is no console command nor php script to fix permissions
                    return;
                } else {
                    $command = $access->createCommand('bash', ['setup.sh', '-n', 'fix']); // does composer as well
                }
            } else {
                warning('Old Tiki detected, running bundled Tiki Manager setup.sh script.');
                $filename = $instance->getWorkPath('setup.sh');
                $access->uploadFile(dirname(__FILE__) . '/../../scripts/setup.sh', $filename);
                $command = $access->createCommand('bash', ['$filename']); // does composer as well
            }

            $command->run();
            if ($command->getReturn() !== 0) {
                // Because temp/composer.phar does not exist, the script will return 1.
                // We do not throw error if instance type is src (composer install is not required)
                $output = $command->getStdoutContent();

                if ((!$this->vcs_instance instanceof Src) ||
                    (strpos($output, 'We have failed to obtain the composer executable') === false)) {
                    throw new \Exception('Command failed');
                }
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

    public function getBranch($refresh = false)
    {
        if ($this->branch && !$refresh) {
            return $this->branch;
        }

        if (! is_null($this->vcs_instance)) {
            $branch = $this->vcs_instance->getRepositoryBranch($this->instance->webroot);

            if ($branch) {
                info("Detected ". $this->vcs_instance->getIdentifier(true) ." : $branch");
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

    public function getFileLocations()
    {
        $access = $this->instance->getBestAccess('scripting');
        $webroot = rtrim($this->instance->webroot, '/');
        $out = $access->runPHP(
            dirname(__FILE__) . '/../../scripts/tiki/get_directory_list.php',
            [$webroot]
        );

        $folders['app'] = [$webroot];

        foreach (explode("\n", $out) as $line) {
            $line = trim($line);
            if (empty($line) || $this->checkFileLocationOutput($line)) {
                continue;
            }

            $line = rtrim($line, '/');

            if (! empty($line)) {
                $folders['data'][] = $line;
            }
        }

        return $folders;
    }

    /**
     * Check output line for unwanted messages
     * so they are not treated as locations
     *
     * @param $line
     * @return bool
     */
    public function checkFileLocationOutput($line)
    {
        $result = false;

        if (strpos($line, '[Warning]') !== false) {
            $result = true;
        }

        return $result;
    }

    /**
     * @return mixed
     */
    public function getSystemIniConfigFilePath()
    {
        $access = $this->instance->getBestAccess('scripting');
        $out = $access->runPHP(
            $_ENV['TRIM_ROOT'] . '/scripts/tiki/get_system_config_ini_file.php',
            [$this->instance->webroot]
        );

        return $out;
    }

    public function getInstallType($refresh = false)
    {
        if (! is_null($this->installType) && !$refresh) {
            return $this->installType;
        }

        $access = $this->instance->getBestAccess('filetransfer');

        $checkpaths = [
            $this->instance->getWebPath('.svn/entries') => 'svn',
            $this->instance->getWebPath('.svn/wc.db')   => 'svn',
            $this->instance->getWebPath('.git/HEAD')    => 'git',
        ];

        $installType = 'src';
        foreach ($checkpaths as $path => $type) {
            if ($access->fileExists($path)) {
                $installType = $type;
                break;
            }
        }

        $this->instance->vcs_type = $installType;
        $this->vcs_instance = VersionControlSystem::getVersionControlSystem($this->instance);

        return $this->installType = $installType;
    }

    public function getName()
    {
        return 'tiki';
    }

    public function getSourceFile(Version $version, $filename)
    {
        $dot = strrpos($filename, '.');
        $ext = substr($filename, $dot);

        $local = tempnam($_ENV['TEMP_FOLDER'], 'trim');
        rename($local, $local . $ext);
        $local .= $ext;

        $sourcefile = $_ENV['SVN_TIKIWIKI_URI'] . "/{$version->branch}/$filename";
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
        return $this->vcs_instance->getAvailableBranches();
    }

    /**
     * Install new instance.
     *
     * @param Version $version
     * @param bool $checksumCheck
     * @return null
     */
    public function install(Version $version, $checksumCheck = false)
    {
        $access = $this->instance->getBestAccess('scripting');
        $host = $access->getHost();

        $folder = cache_folder($this, $version);
        $this->extractTo($version, $folder);

        if ($access instanceof ShellPrompt) {
            if (ApplicationHelper::isWindows() && $this->instance->type == 'local') {
                $host->windowsSync(
                    $folder,
                    $this->instance->webroot,
                    null,
                    ['.svn/tmp']
                );
            } else {
                $host->rsync([
                    'src' => rtrim($folder, '/') . '/',
                    'dest' => rtrim($this->instance->webroot, '/') . '/',
                    'exclude' => ['.phpenv']
                ]);
            }
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

        $this->setDbLock();

        if ($checksumCheck) {
            $version->collectChecksumFromInstance($this->instance);
        }
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

    public function performActualUpdate(Version $version, $options = [])
    {
        $access = $this->instance->getBestAccess('scripting');
        $vcsType = $this->vcs_instance->getIdentifier();
        $can_svn = $access->hasExecutable('svn') && $vcsType == 'SVN';
        $can_git = $access->hasExecutable('git') && $vcsType == 'GIT';

        if ($access instanceof ShellPrompt && ($can_git || $can_svn || $vcsType === 'SRC')) {
            info("Updating " . $this->vcs_instance->getIdentifier(true) . "...");
            $webroot = $this->instance->webroot;
            $escaped_root_path = escapeshellarg(rtrim($this->instance->webroot, '/\\'));
            $escaped_temp_path = escapeshellarg(rtrim($this->instance->getWebPath('temp'), '/\\'));
            $escaped_cache_path = escapeshellarg(rtrim($this->instance->getWebPath('temp/cache'), '/\\'));

            $access->shellExec("{$this->instance->phpexec} -q -d memory_limit=256M console.php cache:clear --all");

            $this->vcs_instance->revert($webroot);
            $this->vcs_instance->cleanup($webroot);

            if ($vcsType === 'SRC') {
                $version->branch = $this->vcs_instance->getBranchToUpdate($version->branch);
            }

            $this->vcs_instance->update($this->instance->webroot, $version->branch);
            foreach ([$escaped_temp_path, $escaped_cache_path] as $path) {
                $script = sprintf('chmod(%s, 0777);', $path);
                $access->createCommand($this->instance->phpexec, ["-r {$script}"])->run();
            }

            if ($this->vcs_instance->getIdentifier() != 'SRC') {
                info('Updating composer');
                $this->runComposer();
            }
            $this->clearCache(true);
        } elseif ($access instanceof Mountable) {
            $folder = cache_folder($this, $version);
            $this->extractTo($version, $folder);
            $access->copyLocalFolder($folder);
        }

        info('Updating database schema...');
        $this->runDatabaseUpdate();

        $this->postInstall($options);

        return;
    }

    public function performActualUpgrade(Version $version, $options = [])
    {
        $access = $this->instance->getBestAccess('scripting');
        $can_svn = $access->hasExecutable('svn') && $this->vcs_instance->getIdentifier() == 'SVN';
        $can_git = $access->hasExecutable('git') && $this->vcs_instance->getIdentifier() == 'GIT';

        $access->getHost(); // trigger the config of the location change (to catch phpenv)

        if ($access instanceof ShellPrompt && ($can_svn || $can_git || $this->vcs_instance->getIdentifier() == 'SRC')) {
            info("Updating " . $this->vcs_instance->getIdentifier(true) . "...");
            $this->clearCache();

            $this->vcs_instance->update($this->instance->webroot, $version->branch);
            foreach (['temp', 'temp/cache'] as $path) {
                $script = sprintf('chmod(%s, 0777);', $path);
                $access->createCommand($this->instance->phpexec, ["-r {$script}"])->run();
            }

            if ($this->vcs_instance->getIdentifier() != 'SRC') {
                info('Updating composer...');
                $this->runComposer();
            }

            $this->clearCache(true);

            info('Updating database schema...');
            $this->runDatabaseUpdate();

            $this->postInstall($options);

            return;
        }
    }

    public function removeTemporaryFiles()
    {
        $access = $this->instance->getBestAccess('scripting');

        // FIXME: Not FTP compatible
        if ($access instanceof ShellPrompt) {
            $this->clearCache(true);
            $this->vcs_instance->cleanup($this->instance->webroot);
        }
    }

    public function requiresDatabase()
    {
        return true;
    }

    public function deleteAllTables()
    {
        $access = $this->instance->getBestAccess('scripting');
        $root = $this->instance->webroot;
        $access->runPHP(
            dirname(__FILE__) . '/../../scripts/tiki/run_delete_tables.php',
            [$root]
        );
    }

    public function restoreDatabase(Database $database, $remoteFile)
    {
        $tmp = tempnam($_ENV['TEMP_FOLDER'], 'dblocal');

        if (! empty($database->dbLocalContent)) {
            file_put_contents($tmp, $database->dbLocalContent);
        } else {
            $systemConfigFilePath = '';

            if (isset($this->instance->system_config_file)) {
                $srcSystemConfigFilePath = $this->instance->system_config_file;
                if (! empty($srcSystemConfigFilePath)) {
                    $systemConfigFilePath = "\$system_configuration_file='{$srcSystemConfigFilePath}';" ."\n";
                }
            }

            file_put_contents($tmp, "<?php"          . "\n"
                ."\$db_tiki='{$database->type}';"    . "\n"
                ."\$host_tiki='{$database->host}';"  . "\n"
                ."\$user_tiki='{$database->user}';"  . "\n"
                ."\$pass_tiki='{$database->pass}';"  . "\n"
                ."\$dbs_tiki='{$database->dbname}';" . "\n"
                . $systemConfigFilePath
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
        $tmp = tempnam($_ENV['TEMP_FOLDER'], 'dblocal');
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

        info('Setting db config file...');
        if ($access instanceof ShellPrompt) {
            $script = sprintf("chmod('%s', 0664);", "{$this->instance->webroot}/db/local.php");
            $access->createCommand($this->instance->phpexec, ["-r {$script}"])->run();

            // TODO: Hard-coding: 'apache:apache'
            // TODO: File ownership under the webroot should be configurable per instance.
            $script = sprintf("chown('%s', 'apache');", "{$this->instance->webroot}/db/local.php");
            $access->createCommand($this->instance->phpexec, ["-r {$script}"])->run();

            $script = sprintf("chgrp('%s', 'apache');", "{$this->instance->webroot}/db/local.php");
            $access->createCommand($this->instance->phpexec, ["-r {$script}"])->run();
        }

        info('Installing database...');
        if ($access->fileExists('console.php') && $access instanceof ShellPrompt) {
            $access = $this->instance->getBestAccess('scripting');
            $access->chdir($this->instance->webroot);
            $access->shellExec("{$this->instance->phpexec} -q -d memory_limit=256M console.php database:install");
            $this->setDbLock();
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

    /**
     * Set db/lock in instance
     *
     * @return bool
     */
    public function setDbLock()
    {
        $access = $this->instance->getBestAccess('scripting');
        if ($access instanceof ShellPrompt) {
            $script = "echo touch('db/lock');";
            $command = $access->createCommand($access->getInterpreterPath(), ["-r {$script}"])->run();

            return $command->getStdoutContent() == 1;
        }

        return false;
    }

    /**
     * Return a list of the compatible versions of Tiki in instance
     *
     * @param bool $withBlank
     * @return array
     */
    public function getCompatibleVersions($withBlank = true)
    {
        $versions = $this->getVersions();

        $compatible = [];
        foreach ($versions as $key => $version) {
            preg_match('/(\d+\.|trunk|master)/', $version->branch, $matches);
            if (!array_key_exists(0, $matches)) {
                continue;
            }

            if ((($matches[0] >= 13) || ($matches[0] == 'trunk') || ($matches[0] == 'master')) && ($this->instance->phpversion < 50500)) {
                // Nothing to do, this match is incompatible...
                continue;
            }

            $compatible[$key] = $version;
        }

        if ($withBlank) {
            $compatible['-1'] = 'blank : none';
        }

        return $compatible;
    }

    /**
     * Run composer install
     *
     * @return null
     * @throws \Exception
     */
    public function runComposer()
    {
        $instance = $this->instance;
        $access = $instance->getBestAccess('scripting');

        if ($instance->type === 'local' && !getenv('COMPOSER_HOME') && PHP_SAPI !== 'cli') {
            $access->setenv('COMPOSER_HOME', $_ENV['CACHE_FOLDER'] . DIRECTORY_SEPARATOR . '.composer');
        }

        $access->setenv('COMPOSER_DISCARD_CHANGES', 'true');
        $access->setenv('COMPOSER_NO_INTERACTION', '1');

        if ($access instanceof ShellPrompt) {
            $access->chdir($instance->webroot);

            if ($instance->hasConsole()) {
                if ($instance->type == 'local' && ApplicationHelper::isWindows()) {
                    $command = $access->createCommand('composer', ['install', '-d vendor_bundled', '--no-interaction', '--prefer-source']);
                } else {
                    $command = $access->createCommand('bash', ['setup.sh', 'composer']);
                }

                $command->run();
                if ($command->getReturn() !== 0) {
                    throw new \Exception('Composer install failed');
                }
            }
        }
    }

    /**
     * @param array $options
     * @throws \Exception
     */
    public function postInstall($options = [])
    {
        $access = $this->instance->getBestAccess('scripting');
        $access->getHost(); // trigger the config of the location change (to catch phpenv)

        if ($this->vcs_instance->getIdentifier() != 'SRC') {
            info('Running composer...');
            $this->runComposer();
        }

        $this->setDbLock();

        $hasConsole = $this->instance->hasConsole();

        if ($hasConsole) {
            info('Cleaning Cache...');
            $this->clearCache();

            if (empty($options['skip-cache-warmup'])) {
                info('Generating Caches...');
                $access->shellExec("{$this->instance->phpexec} -q -d memory_limit=256M console.php cache:generate");
            }

            if (!empty($options['live-reindex'])) {
                $options['skip-reindex'] = false;
                $this->instance->unlock();
            }
        }

        info('Fixing permissions...');
        $this->fixPermissions();

        if (empty($options['skip-reindex']) && $hasConsole) {
            info('Rebuilding Index...');
            $access->shellExec("{$this->instance->phpexec} -q -d memory_limit=256M console.php index:rebuild --log");
        }
    }

    public function clearCache($all = false)
    {
        if ($this->instance->hasConsole()) {
            $access = $this->instance->getBestAccess('scripting');
            $flag = $all ? ' --all' : '';
            $access->shellExec("{$this->instance->phpexec} -q -d memory_limit=256M console.php cache:clear" . $flag);

            return true;
        }

        return false;
    }

    public function runDatabaseUpdate()
    {
        $access = $this->instance->getBestAccess('scripting');
        if ($this->instance->hasConsole()) {
            $access->shellExec([
                "{$this->instance->phpexec} -q -d memory_limit=256M console.php database:update"
            ]);
        } else {
            $access->runPHP(
                dirname(__FILE__) . '/../../scripts/tiki/sqlupgrade.php',
                [$this->instance->webroot]
            );
        }
    }
}

// vi: expandtab shiftwidth=4 softtabstop=4 tabstop=4
