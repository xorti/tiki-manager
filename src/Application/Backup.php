<?php
// Copyright (c) 2016, Avan.Tech, et. al.
// Copyright (c) 2008, Luis Argerich, Garland Foster, Eduardo Polidor, et. al.
// All Rights Reserved. See copyright.txt for details and a complete list of authors.
// Licensed under the GNU LESSER GENERAL PUBLIC LICENSE. See license.txt for details.

namespace TikiManager\Application;

use TikiManager\Application\Exception\BackupCopyException;

class Backup
{
    protected $access;
    protected $app;
    protected $archiveDir;
    protected $archiveRoot;
    protected $backupDir;
    protected $backupDirname;
    protected $backupRoot;
    protected $errors;
    protected $fileGroup;
    protected $filePerm;
    protected $fileUser;
    protected $instance;
    protected $workpath;

    public function __construct($instance)
    {
        $this->instance = $instance;
        $this->access = $this->getAccess($instance);
        $this->app = $instance->getApplication();
        $this->workpath = $instance->createWorkPath($this->access);
        $this->archiveRoot = rtrim(ARCHIVE_FOLDER, '/');
        $this->backupRoot = rtrim(BACKUP_FOLDER, '/');
        $this->backupDirname = "{$instance->id}-{$instance->name}";
        $this->backupDir = "{$this->backupRoot}/{$this->backupDirname}";
        $this->archiveDir = "{$this->archiveRoot}/{$this->backupDirname}";
        $this->filePerm = intval($instance->getProp('backup_perm')) ?: 0770;
        $this->fileUser = $instance->getProp('backup_user');
        $this->fileGroup = $instance->getProp('backup_group');
        $this->errors = [];

        $this->createBackupDir();
        $this->createArchiveDir();
    }

    public function copyDirectories($targets, $backupDir)
    {
        $access = $this->getAccess();
        $backupDir = $backupDir ?: $this->backupDir;
        $result = [];

        foreach ($targets as $target) {
            list($type, $dir) = $target;
            $hash = md5($dir);
            $destDir = "{$backupDir}/{$hash}";
            $error_code = $access->localizeFolder($dir, $destDir);

            if ($error_code) {
                if (array_key_exists($this->errors, $error_code)) {
                    $this->errors[$error_code][] = $dir;
                } else {
                    $this->errors[$error_code] = [$error_code => $dir];
                }
            }

            $result[] = [$hash, $type, $dir];
        }

        if (!empty($this->errors)) {
            throw new BackupCopyException(
                $this->errors,
                BackupCopyException::RSYNC_ERROR
            );
        }

        return $result;
    }

    public function create($skipArchive = false, $backupDir = null)
    {
        $access = $this->getAccess();
        $backupDir = $backupDir ?: $this->backupDir;

        $this->app->removeTemporaryFiles();
        $targets = $this->getTargetDirectories();

        info('Downloading files locally');
        $copyResult = $this->copyDirectories($targets, $backupDir);

        info('Creating manifest');
        $this->createManifest($copyResult, $backupDir);

        info('Creating database dump');
        $this->createDatabaseDump($this->app, $backupDir);

        if (!$skipArchive) {
            info('Creating archive');
            $result = $this->createArchive($this->archiveDir, $backupDir);
        }

        return $result;
    }

    public function createArchive($archiveDir = null)
    {
        $archiveDir = $archiveDir ?: $this->archiveDir;
        $tarDate = date('Y-m-d_H-i-s');
        $tarPath = "{$archiveDir}/{$this->backupDirname}_{$tarDate}.tar.bz2";

        $command = 'nice -n 19 tar -cjp'
            . ' -C ' . escapeshellarg($this->backupRoot)
            . ' -f ' . escapeshellarg($tarPath)
            . ' '    . escapeshellarg($this->backupDirname);

        exec($command, $output, $return_var);

        if ($return_var != 0) {
            error("TAR exit code: $return_var");
        }

        $success = $return_var === 0
            && file_exists($tarPath)
            && filesize($tarPath) > 0;

        return $success ? $tarPath : false;
    }

    public function createArchiveDir($archiveDir = null)
    {
        $archiveDir = $archiveDir ?: $this->archiveDir;
        if (is_dir($archiveDir) || mkdir($archiveDir, $this->filePerm, true)) {
            $this->fixPermissions($archiveDir);
            return $archiveDir;
        }
        return false;
    }

    public function createBackupDir($backupDir = null)
    {
        $backupDir = $backupDir ?: $this->backupDir;

        if (is_dir($backupDir) || mkdir($backupDir, $this->filePerm, true)) {
            $this->fixPermissions($backupDir);
            return $backupDir;
        }
    }

    public function createDatabaseDump($app, $backupDir = null)
    {
        $app = $app ?: $this->app;
        $backupDir = $backupDir ?: $this->backupDir;
        $sqlpath = "{$backupDir}/database_dump.sql";

        file_exists($sqlpath) && unlink($sqlpath);
        $app->backupDatabase($sqlpath);

        if (file_exists($sqlpath)) {
            $this->fixPermissions($sqlpath);
            return $sqlpath;
        }

        return false;
    }

    public function createManifest($data, $backupDir = null)
    {
        $backupDir = $backupDir ?: $this->backupDir;
        $manifestFile = "{$backupDir}/manifest.txt";
        $file = fopen($manifestFile, 'w');

        foreach ($data as $location) {
            $line = vsprintf("%s    %s    %s\n", $location);
            fwrite($file, $line);
        }

        fclose($file);
        $this->fixPermissions($manifestFile);
        return $manifestFile;
    }

    public function fixPermissions($path)
    {
        $perm = $this->filePerm;

        if (is_dir($path)) {       // avoid rw-rw-rw- for dirs
            $perm = (($perm & 0b100100100) >> 2) | $perm;
        } elseif (is_file($path)) { // avoid --x--x--x for files
            $perm = ($perm & 0b001001001) ^ $perm;
        }

        $success = 1;
        if ($perm) {
            $success &= chmod($path, $perm);
        }
        if (getmyuid() === 0) {
            if ($this->fileUser) {
                $success &= chown($path, $this->fileUser);
            }
            if ($this->fileGroup) {
                $success &= chgrp($path, $this->fileGroup);
            }
        }
        return $success > 0;
    }

    public function getAccess($instance = null)
    {
        $instance = $instance ?: $this->instance;
        $access = $instance->getBestAccess('scripting');
        return $access;
    }

    public function getArchives($archiveRoot = null, $instance = null)
    {
        $archiveRoot = $archiveRoot ?: $this->archiveRoot;
        $instance = $instance ?: $this->instance;
        $globPattern = "{$archiveRoot}/{$instance->id}-*/{$instance->id}*_*.tar.bz2";
        return array_reverse(glob($globPattern));
    }

    public function getBackupDir()
    {
        return $this->backupDir;
    }

    public function getTargetDirectories()
    {
        $targets = [];
        $extraBackups = $this->instance->getExtraBackups() ?: [];
        $locations = $this->app->getFileLocations();

        foreach ($locations as $type => $directories) {
            foreach ($directories as $dir) {
                $targets[] = [$type, $dir];
            }
        }

        foreach ($extraBackups as $dir) {
            $targets[] = ['data', $dir];
        }

        return $targets;
    }

    public function setArchiveSymlink($symlinkPath = null, $archiveDir = null, $instance = null)
    {
        $archiveDir = $archiveDir ?: $this->archiveDir;
        $instance = $instance ?: $this->instance;
        $symlinkPath = $symlinkPath ?: dirname($instance->webroot) . '/backup';

        // If Tiki Manager archive dir is a link, swap link and target
        if (is_link($archiveDir)) {
            $realArchiveDir = readlink($archiveDir);
            unlink($archiveDir);
            if (file_exists($realArchiveDir)) {
                rename($realArchiveDir, $archiveDir);
            } else {
                mkdir($archiveDir, $this->filePerm, true);
            }
        }

        symlink($archiveDir, $symlinkPath);
        $success = is_dir($archiveDir)
            && is_link($symlinkPath)
            && readlink($symlinkPath) === $archiveDir;

        $this->fixPermissions($archiveDir);
        return $success;
    }
}