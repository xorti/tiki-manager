<?php

namespace TikiManager\Command\Helper;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use TikiManager\Application\Application;
use TikiManager\Application\Tiki;
use TikiManager\Application\Instance;
use TikiManager\Application\Version;
use TikiManager\Libs\Database\Database;
use TikiManager\Libs\Database\Exception\DatabaseErrorException;

class CommandHelper
{
    /**
     * Get information from Instance Object
     *
     * @param $instances array of Instance objects
     * @return array|null
     */
    public static function getInstancesInfo($instances)
    {
        $instancesInfo = null;

        if (! empty($instances)) {
            foreach ($instances as $key => $instance) {
                $instancesInfo[] = [
                    $instance->id,
                    $instance->type,
                    $instance->name,
                    $instance->weburl,
                    $instance->contact,
                    $instance->branch
                ];
            }
        }

        return $instancesInfo;
    }

    /**
     * Render a table with all Instances
     *
     * @param $output
     * @param $rows
     * @return bool
     */
    public static function renderInstancesTable($output, $rows)
    {
        if (empty($rows)) {
            return false;
        }

        $instanceTableHeaders = [
            'ID',
            'Type',
            'Name',
            'Web URL',
            'Contact',
            'Branch'
        ];

        $table = new Table($output);
        $table
            ->setHeaders($instanceTableHeaders)
            ->setRows($rows);
        $table->render();

        return true;
    }

    /**
     * Render a table with Options and Actions from "check" functionality
     *
     * @param $output
     */
    public static function renderCheckOptionsAndActions($output)
    {
        $headers = [
            'Option',
            'Action'
        ];

        $options = [
            [
                'current',
                'Use the files currently online for checksum'
            ],
            [
                'source',
                'Get checksums from repository (best option)'
            ],
            [
                'skip',
                'Do nothing'
            ]
        ];

        $table = new Table($output);
        $table
            ->setHeaders($headers)
            ->setRows($options);
        $table->render();
    }

    /**
     * Render a table with Report options
     *
     * @param $output
     */
    public static function renderReportOptions($output)
    {
        $headers = [
            'Option',
            'Description'
        ];

        $options = [
            [
                'add',
                'Add a report receiver'
            ],
            [
                'modify',
                'Modify a report receiver'
            ],
            [
                'remove',
                'Remove a report receiver'
            ],
            [
                'send',
                'Send updated reports'
            ]
        ];

        $table = new Table($output);
        $table
            ->setHeaders($headers)
            ->setRows($options);
        $table->render();
    }

    /**
     * Wrapper for standard console question
     *
     * @param $question
     * @param null $default
     * @param string $character
     * @return Question
     */
    public static function getQuestion($question, $default = null, $character = ':')
    {

        if ($default !== null) {
            $question = sprintf($question . " [%s]: ", $default);
        } else {
            $question = $question . $character . ' ';
        }

        return new Question($question, $default);
    }

    /**
     * Get Instances based on type
     *
     * @param string $type
     * @param bool $excludeBlank
     * @return array
     */
    public static function getInstances($type = 'all', $excludeBlank = false)
    {
        $result = [];

        switch ($type) {
            case 'tiki':
                $result = Instance::getTikiInstances();
                break;
            case 'no-tiki':
                $result = Instance::getNoTikiInstances();
                break;
            case 'update':
                $result = Instance::getUpdatableInstances();
                break;
            case 'restore':
                $result = Instance::getRestorableInstances();
                break;
            case 'all':
                $result = Instance::getInstances($excludeBlank);
        }

        return $result;
    }

    /**
     * Validate Instances Selection
     *
     * @param $answer
     * @param $instances
     * @return array
     */
    public static function validateInstanceSelection($answer, $instances)
    {
        if (empty($answer)) {
            throw new \RuntimeException(
                'You must select an #ID'
            );
        } else {
            $instancesId = array_filter(array_map('trim', explode(',', $answer)));
            $invalidInstancesId = array_diff($instancesId, array_keys($instances));

            if ($invalidInstancesId) {
                throw new \RuntimeException(
                    'Invalid instance(s) ID(s) #' . implode(',', $invalidInstancesId)
                );
            }

            $selectedInstances = [];
            foreach ($instancesId as $index) {
                if (array_key_exists($index, $instances)) {
                    $selectedInstances[] = $instances[$index];
                }
            }
        }
        return $selectedInstances;
    }

    /**
     * Gets a CLI option given the option name eg: "--<option>="
     *
     * @param $option
     * @param null $default
     * @return bool|null|string
     */
    public static function getCliOption($option, $default = null)
    {
        global $argv;

        foreach ($argv as $argument) {
            if (strpos($argument, "--{$option}=") === 0) {
                return substr($argument, strlen($option) + 3);
            }
        }

        return $default;
    }

    /**
     * Remove folder contents
     *
     * @param array|string $dirs
     * @param LoggerInterface $logger
     * @return bool
     */
    public static function clearFolderContents($dirs, LoggerInterface $logger)
    {
        if (!is_array($dirs)) {
            $dirs = [$dirs];
        }

        try {
            $fileSystem = new Filesystem();
            foreach ($dirs as $dir) {
                if (!$fileSystem->exists($dir)) {
                    continue;
                }

                $iterator = new \FilesystemIterator($dir);
                foreach ($iterator as $file) {
                    $fileSystem->remove($file->getPathName());
                }
            }
        } catch (IOException $e) {
            $message = sprintf("An error occurred while removing folder contents:\n%s", $e->getMessage());
            $logger->error($message);
            return false;
        }

        return true;
    }

    /**
     * Remove one or more files from filesystem
     *
     * @param string|array $files A string or array with files full path to remove
     * @param LoggerInterface $logger
     * @return bool
     */
    public static function removeFiles($files, LoggerInterface $logger)
    {
        if (!is_array($files)) {
            $files = [$files];
        }

        try {
            $fileSystem = new Filesystem();
            foreach ($files as $file) {
                if (!$fileSystem->exists($file)) {
                    continue;
                }

                $fileSystem->remove($file);
            }
        } catch (IOException $e) {
            $message = sprintf("An error occurred while removing file:\n%s", $e->getMessage());
            $logger->error($message);
            return false;
        }

        return true;
    }

    /**
     * Handle application install for a new instance.
     *
     * @param Instance $instance
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return bool
     */
    public static function performInstall(Instance $instance, InputInterface $input, OutputInterface $output)
    {

        $io = new SymfonyStyle($input, $output);

        if ($instance->findApplication()) {
            $io->error('Unable to install. An application was detected in this instance.');
            return false;
        }

        $apps = Application::getApplications($instance);
        $selection = getEntries($apps, 0);
        /** @var Application $app */
        $app = reset($selection);

        $io->writeln('Fetching compatible versions. Please wait...');
        $io->note([
            "If some versions are not offered, it's likely because the host",
            "server doesn't meet the requirements for that version (ex: PHP version is too old)"
        ]);

        $versions = $app->getCompatibleVersions();
        $selection = $io->choice('Which version do you want to install?', $versions);

        $details = array_map('trim', explode(':', $selection));

        if ($details[0] == 'blank') {
            $io->success('No version to install. This is a blank instance.');
            return true;
        }

        $version = Version::buildFake($details[0], $details[1]);

        $io->writeln('Installing application...');
        $io->note([
            'If for any reason the installation fails (ex: wrong setup.sh parameters for tiki),',
            'you can use \'tiki-manager instance:access\' to complete the installation manually.'
        ]);

        $app->install($version);

        if ($app->requiresDatabase()) {
            $dbConn = self::setupDatabaseConnection($instance, $input, $output);
            $app->setupDatabase($dbConn);
        }

        $io->success('Please test your site at ' . $instance->weburl);
        return true;
    }

    /**
     * Check, configure and  test database connection for a given instance
     *
     * @param Instance $instance
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return Database|null
     */
    public static function setupDatabaseConnection(Instance $instance, InputInterface $input, OutputInterface $output)
    {

        $dbUser = null;
        $io = new SymfonyStyle($input, $output);

        $access = $instance->getBestAccess('scripting');
        $remoteFile = "{$instance->webroot}/db/local.php";

        if ($access->fileExists($remoteFile)) {
            $localFile = $access->downloadFile($remoteFile);
            $dbUser = Database::createFromConfig($instance, $localFile);
            unlink($localFile);

            if ($dbUser instanceof Database) {
                return $dbUser;
            }
        }

        $io->section(sprintf('Setup database connection in %s', $instance->name));
        $io->note('Creating databases and users requires root privileges on MySQL.');

        $dbRoot = new Database($instance);

        $valid = false;
        while (!$valid) {
            $dbRoot->host = $io->ask('Database host', $dbRoot->host ?: 'localhost');
            $dbRoot->user = $io->ask('Database user', $dbRoot->user ?: 'root');
            $dbRoot->pass = $io->askHidden('Database password');

            $valid = $dbRoot->testConnection();
        }

        $logger = new ConsoleLogger($output);
        $logger->debug('Connected to MySQL with administrative privileges');

        $create = $io->confirm('Should a new database and user be created now (both)?');

        if (!$create) {
            $dbUser = $dbRoot;
            $dbUser->dbname = $io->ask('Database name', 'tiki_db');
        } else {
            $maxPrefixLength = $dbRoot->getMaxUsernameLength() - 5;
            $io->note("Prefix is a string with maximum of {$maxPrefixLength} chars");

            $prefix = 'tiki';
            while (!is_object($dbUser)) {
                $prefix = $io->ask('Prefix to use for username and database', $prefix);

                if (strlen($prefix) > $maxPrefixLength) {
                    $io->error("Prefix is a string with maximum of {$maxPrefixLength} chars");
                    $prefix = substr($prefix, 0, $maxPrefixLength);
                    continue;
                }

                $username = "{$prefix}_user";
                if ($dbRoot->userExists($username)) {
                    $io->error("User '$username' already exists, can't proceed.");
                    continue;
                }

                $dbname = "{$prefix}_db";
                if ($dbRoot->databaseExists($dbname)) {
                    $io->warning("Database '$dbname' already exists.");
                    if (!$io->confirm('Continue?')) {
                        continue;
                    }
                }

                try {
                    $dbUser = $dbRoot->createAccess($username, $dbname);
                } catch (DatabaseErrorException $e) {
                    $io->error("Can't setup database!");
                    $io->error($e->getMessage());

                    $option = $io->choice('What do you want to do?', ['a' => 'Abort', 'r' => 'Retry'], 'a');

                    if ($option === 'a') {
                        $io->comment('Aborting');
                        return;
                    }
                }
            }

            $types = $dbUser->getUsableExtensions();
            $type = getenv('MYSQL_DRIVER');
            $dbUser->type = $type;

            if (count($types) == 1) {
                $dbUser->type = reset($types);
            } elseif (empty($type)) {
                $options = [];
                foreach ($types as $key => $name) {
                    $options[$key] = $name;
                }

                $dbUser->type = $io->choice('Which extension should be used?', $options);
            }
        }

        return $dbUser;
    }

    /**
     * Get VCS Versions (SVN || GIT)
     *
     * @param string $vcsType
     * @return array
     */
    public static function getVersions($vcsType = '')
    {
        $instance = new Instance();
        if (! empty($vcsType)) {
            $instance->vcs_type = $vcsType;
        }
        $instance->phpversion = 50500;
        $tikiApplication = new Tiki($instance);
        $versions = $tikiApplication->getCompatibleVersions();

        return $versions;
    }

    /**
     * Get information from Version Object
     *
     * @param $versions
     * @return array|null
     */
    public static function getVersionsInfo($versions)
    {
        $versionsInfo = null;

        if (! empty($versions)) {
            foreach ($versions as $version) {
                $versionsInfo[] = [
                    $version->type,
                    $version->branch
                ];
            }
        }

        return $versionsInfo;
    }

    /**
     * Render a table with all Versions (SVN || GIT)
     *
     * @param $output
     * @param $rows
     */
    public static function renderVersionsTable($output, $rows)
    {
        if (empty($rows)) {
            return;
        }

        $versionsTableHeaders = [
            'Type',
            'Name'
        ];

        $table = new Table($output);
        $table
            ->setHeaders($versionsTableHeaders)
            ->setRows($rows);
        $table->render();
    }
}
