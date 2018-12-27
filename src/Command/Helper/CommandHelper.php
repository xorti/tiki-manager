<?php

namespace TikiManager\Command\Helper;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use TikiManager\Application\Instance;

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
}
