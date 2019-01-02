<?php
// Copyright (c) 2016, Avan.Tech, et. al.
// Copyright (c) 2008, Luis Argerich, Garland Foster, Eduardo Polidor, et. al.
// All Rights Reserved. See copyright.txt for details and a complete list of authors.
// Licensed under the GNU LESSER GENERAL PUBLIC LICENSE. See license.txt for details.

namespace TikiManager\Application;

use TikiManager\Access\Access;

class Instance
{
    const TYPES = 'local,ftp,ssh';

    const SQL_SELECT_INSTANCE = <<<SQL
SELECT
    i.instance_id id, i.name, i.contact, i.webroot, i.weburl, i.tempdir, i.phpexec, i.app, a.type, v.branch, v.revision
FROM
    instance i
INNER JOIN access a
    ON i.instance_id=a.instance_id
LEFT JOIN
    version v ON i.instance_id = v.instance_id
;
SQL;

    const SQL_SELECT_INSTANCE_BY_ID = <<<SQL
SELECT
    i.instance_id id, i.name, i.contact, i.webroot, i.weburl, i.tempdir, i.phpexec, i.app, a.type, v.branch, v.revision
FROM
    instance i
INNER JOIN access a
    ON i.instance_id=a.instance_id
LEFT JOIN
    version v ON i.instance_id = v.instance_id
WHERE
    i.instance_id = :id
;
SQL;

    const SQL_SELECT_UPDATABLE_INSTANCE = <<<SQL
SELECT
    i.instance_id id, i.name, i.contact, i.webroot, i.weburl, i.tempdir, i.phpexec, i.app, v.branch, a.type
FROM
    instance i
INNER JOIN access a
    ON i.instance_id=a.instance_id
INNER JOIN
    version v ON i.instance_id = v.instance_id
INNER JOIN (
    SELECT
        MAX(version_id) version
    FROM
        version
    GROUP BY
        instance_id
    ) t ON t.version = v.version_id
WHERE
    v.type = 'svn' OR v.type = 'tarball'
;
SQL;

    const SQL_SELECT_LATEST_VERSION = <<<SQL
SELECT
    version_id id, instance_id, type, branch, date, revision
FROM
    version
WHERE
    instance_id = :id
ORDER BY
    version_id DESC
LIMIT 1
;
SQL;

    const SQL_SELECT_BACKUP_LOCATION = <<<SQL
SELECT
    location
FROM
    backup
WHERE
    instance_id = :id
;
SQL;

    const SQL_INSERT_INSTANCE = <<<SQL
INSERT OR REPLACE INTO
    instance
    (instance_id, name, contact, webroot, weburl, tempdir, phpexec, app)
VALUES
    (:id, :name, :contact, :web, :url, :temp, :phpexec, :app)
;
SQL;

    const SQL_UPDATE_INSTANCE = <<<SQL
UPDATE instance
SET
    name = :name,
    contact = :contact,
    webroot = :web,
    weburl = :url,
    tempdir = :temp
WHERE
    instance_id = :id
;
SQL;

    const SQL_INSERT_BACKUP = <<<SQL
INSERT INTO
    backup
    (instance_id, location)
VALUES
    (:id, :loc)
;
SQL;

    const SQL_DELETE_ACCESS = <<<SQL
DELETE FROM
    access
WHERE
    instance_id = :id
;
SQL;

    const SQL_DELETE_BACKUP = <<<SQL
DELETE FROM
    backup
WHERE
    instance_id = :id
;
SQL;

    const SQL_DELETE_INSTANCE = <<<SQL
DELETE FROM
    instance
WHERE
    instance_id = :id
;
SQL;

    const SQL_DELETE_REPORT_CONTENT = <<<SQL
DELETE FROM
    report_content
WHERE
    instance_id = :id OR receiver_id = :id
;
SQL;

    const SQL_DELETE_REPORT_RECEIVER = <<<SQL
DELETE FROM
    report_receiver
WHERE
    instance_id = :id
;
SQL;

    const SQL_DELETE_VERSION = <<<SQL
DELETE FROM
    version
WHERE
    instance_id = :id
;
SQL;

    const SQL_GET_INSTANCE_PROPERTY = <<<SQL
SELECT value FROM
    property
WHERE
    instance_id = :id AND key = :key
;
SQL;

    const SQL_SET_INSTANCE_PROPERTY = <<<SQL
REPLACE INTO
    property
VALUES
    (:id, :key, :value)
;
SQL;

    const SQL_DELETE_ALL_INSTANCE_PROPERTIES = <<<SQL
DELETE FROM
    property
WHERE
    instance_id = :id
;
SQL;

    const SQL_DELETE_FILE_BY_SELECT = <<<SQL
DELETE FROM
    file
WHERE
    version_id
IN (
    SELECT
        version_id
    FROM
        version
    WHERE
        instance_id = :id
);
SQL;

    private $id;
    public $name;
    public $contact;
    public $webroot;
    public $weburl;
    public $tempdir;
    public $phpexec;
    public $phpversion;
    public $app;
    public $type;

    private $access = [];

    public function getId()
    {
        return $this->id;
    }

    public static function getInstances($exclude_blank = false)
    {
        $result = query(self::SQL_SELECT_INSTANCE);

        $instances = [];
        while ($instance = $result->fetchObject('TikiManager\Application\Instance')) {
            if ($exclude_blank) {
                if ($instance->getApplication()) {
                    $instances[$instance->getId()] = $instance;
                }
            } else {
                $instances[$instance->getId()] = $instance;
            }
        }

        return $instances;
    }

    public static function getTikiInstances()
    {
        $allInstances = self::getInstances();

        $tikiInstances = [];
        foreach ($allInstances as $instance) {
            if ($instance->getApplication() instanceof Tiki) {
                $tikiInstances[$instance->id] = $instance;
            }
        }

        return $tikiInstances;
    }

    public static function getNoTikiInstances()
    {
        $allInstances = self::getInstances();

        $noTikiInstances = [];
        foreach ($allInstances as $instance) {
            if (! $instance->getApplication()) {
                $noTikiInstances[$instance->id] = $instance;
            }
        }

        return $noTikiInstances;
    }

    public static function getInstance($id)
    {
        $result = query(self::SQL_SELECT_INSTANCE_BY_ID, [':id' => $id]);
        $instance = $result->fetchObject('TikiManager\Application\Instance');
        return $instance;
    }

    public static function getUpdatableInstances()
    {
        $result = query(self::SQL_SELECT_UPDATABLE_INSTANCE);

        $instances = [];
        while ($instance = $result->fetchObject('TikiManager\Application\Instance')) {
            $instances[$instance->id] = $instance;
        }

        return $instances;
    }

    public static function getRestorableInstances()
    {
        $dp = opendir(BACKUP_FOLDER);

        $backups = [];
        $matches = [];
        while (false !== $file = readdir($dp)) {
            if (! preg_match('/^\d+/', $file, $matches)) {
                continue;
            }

            if ($instance = self::getInstance($matches[0])) {
                $backups[$matches[0]] = $instance;
            }
        }

        closedir($dp);
        return $backups;
    }

    public function save()
    {
        $params = [
            ':id' => $this->id,
            ':name' => $this->name,
            ':contact' => $this->contact,
            ':web' => $this->webroot,
            ':url' => $this->weburl,
            ':temp' => $this->tempdir,
            ':phpexec' => $this->phpexec,
            ':app' => $this->app,
        ];

        query(self::SQL_INSERT_INSTANCE, $params);

        $rowid = rowid();
        if (! $this->id && $rowid) {
            $this->id = $rowid;
        }

        if (!empty($this->backup_user)) {
            $this->setProp('backup_user', $this->backup_user);
        }
        if (!empty($this->backup_group)) {
            $this->setProp('backup_group', $this->backup_group);
        }
        if (!empty($this->backup_perm)) {
            $this->setProp('backup_perm', $this->backup_perm);
        }
    }

    /**
     * Update the instance information
     */
    public function update()
    {
        $params = [
            ':id'      => $this->id,
            ':name'    => $this->name,
            ':contact' => $this->contact,
            ':web'     => $this->webroot,
            ':url'     => $this->weburl,
            ':temp'    => $this->tempdir
        ];

        query(self::SQL_UPDATE_INSTANCE, $params);

        if (!empty($this->backup_user)) {
            $this->setProp('backup_user', $this->backup_user);
        }
        if (!empty($this->backup_group)) {
            $this->setProp('backup_group', $this->backup_group);
        }
        if (!empty($this->backup_perm)) {
            $this->setProp('backup_perm', $this->backup_perm);
        }
    }

    public function delete()
    {
        query(self::SQL_DELETE_ACCESS, [':id' => $this->id]);
        query(self::SQL_DELETE_BACKUP, [':id' => $this->id]);
        query(self::SQL_DELETE_FILE_BY_SELECT, [':id' => $this->id]);
        query(self::SQL_DELETE_INSTANCE, [':id' => $this->id]);
        query(self::SQL_DELETE_REPORT_CONTENT, [':id' => $this->id]);
        query(self::SQL_DELETE_REPORT_RECEIVER, [ ':id' => $this->id]);
        query(self::SQL_DELETE_VERSION, [':id' => $this->id]);
        query(self::SQL_DELETE_ALL_INSTANCE_PROPERTIES, [':id' => $this->id]);
    }

    public function registerAccessMethod($type, $host, $user, $password = null, $port = null)
    {
        if (! $class = Access::getClassFor($type)) {
            return;
        }

        $access = new $class($this);
        $access->host = $host;
        $access->user = $user;
        $access->password = $password;

        if ($port) {
            $access->port = $port;
        }

        if ($access->firstConnect()) {
            $access->save();

            $this->access[] = $access;
            return $access;
        }
    }

    public function getBestAccess($type)
    {
        if (empty($this->access)) {
            $this->access = Access::getAccessFor($this);
        }

        // TODO: Add intelligence as more access types get added
        // types:
        //      scripting
        //      filetransfer
        return reset($this->access);
    }

    public function getWebUrl($relativePath)
    {
        $weburl = rtrim($this->weburl, '/');

        $path = "$weburl/$relativePath";
        $path = str_replace('/./', '/', $path);

        return $path;
    }

    public function getWebPath($relativePath)
    {
        $path = "{$this->webroot}/$relativePath";
        $path = str_replace('/./', '/', $path);

        return $path;
    }

    public function getWorkPath($relativePath)
    {
        return "{$this->tempdir}/$relativePath";
    }

    public function getProp($key)
    {
        $result = query(self::SQL_GET_INSTANCE_PROPERTY, [':id' => $this->id, ':key' => $key]);
        $result = $result->fetchObject();
        if ($result && $result->value) {
            return $result->value;
        }
    }

    public function setProp($key, $value)
    {
        $result = query(self::SQL_SET_INSTANCE_PROPERTY, [
            ':id' => $this->id,
            ':key' => $key,
            ':value' => $value
        ]);
    }

    public function createWorkPath($access = null)
    {
        if (is_null($access)) {
            $access = $this->getBestAccess('scripting');
        }

        echo $access->shellExec(
            "mkdir -p {$this->tempdir}"
        );

        return $this->tempdir;
    }

    public function getPHPVersion()
    {
        $access = $this->getBestAccess('scripting');
        $path = $access->getInterpreterPath($this);
        $version = $access->shellExec("{$path} -r 'echo phpversion();'");
        return $version;
    }

    public function detectPHP()
    {
        $access = $this->getBestAccess('scripting');
        $path = $access->getInterpreterPath($this);

        $path_env = getenv('PATH');

        if (strlen($path) > 0) {
            $version = $access->getInterpreterVersion($path);
            $this->phpversion = intval($version);
            if ($version <  50300) {
                return false;
            }

            $this->phpexec = $path;
            $this->save();

            // even passing full path to php binary, we need to fix PATH
            // so scripts like setup.sh can use correct php version
            $bin_folder = dirname($path);
            if (strpos($path_env, $bin_folder) === false) {
                $access->setenv('PATH', "${bin_folder}:${path_env}");
            }

            return $version;
        }

        error("No suitable php interpreter was found on {$this->name} instance");
        exit(1);
    }

    public function detectSVN()
    {
        $access = $this->getBestAccess('scripting');
        $path = $access->getSVNPath();

        if (strlen($path) > 0) {
            return $path;
        }

        return false;
    }

    public function detectDistribution()
    {
        $access = $this->getBestAccess('scripting');
        $path = $access->getInterpreterPath();
        return $access->getDistributionName($path);
    }

    public function getExtensions()
    {
        $access = $this->getBestAccess('scripting');
        $content = $access->runPHP(TRIM_ROOT . '/scripts/get_extensions.php');
        $modules = explode("\n", $content);

        return $modules;
    }

    public function findApplication()
    {
        foreach (Application::getApplications($this) as $app) {
            if ($app->isInstalled()) {
                $app->registerCurrentInstallation();
                return $app;
            }
        }

        return null;
    }

    public function createVersion()
    {
        return new Version($this->getId());
    }

    public function getLatestVersion()
    {
        $result = query(self::SQL_SELECT_LATEST_VERSION, [':id' => $this->id]);
        $object = $result->fetchObject('TikiManager\Application\Version', [$this]);

        return $object;
    }

    /**
     * Modern in this context means it uses composer and has console.php for shell access which arrived in Tiki 11,
     * although this may need to be changed to 12 if 11 is proved to be unreliable in these respects
     *
     * @return bool
     */
    public function hasConsole()
    {
        $current = $this->getLatestVersion();
        $hasConsole = $current->branch === 'trunk'
            || (
                preg_match('/(\d+)\.?/', $current->branch, $matches)
                && floatval($matches[1]) >= 11
            );
        return $hasConsole;
    }

    public function getApplication()
    {
        if (empty($this->app)) {
            return false;
        }

        $class = ucfirst($this->app);
        if (! class_exists($class) && is_subclass_of('TikiManager\Application\\'.$class, 'TikiManager\Application\Application')) {
            $class = 'TikiManager\Application\\'.$class;
            return new $class($this);
        }
    }

    public function backup()
    {
        $backup = new Backup($this);

        if ($this->detectDistribution() === 'ClearOS') {
            $backup->setArchiveSymlink(dirname($this->webroot) . '/backup');
        }

        $tar = $backup->create();
        return $tar;
    }

    public function restore($src_app, $archive, $clone = false)
    {
        $access = $this->getBestAccess('scripting');

        info("Restoring files from '{$archive}' into {$this->name}");
        $restore = new Restore($this);
        $restore->restoreFiles($archive);

        $this->app = $src_app;
        $this->save();
        $database_dump = $restore->getRestoreFolder() . "/database_dump.sql";

        $version = null;
        $oldVersion = $this->getLatestVersion();

        perform_database_setup($this, $database_dump);
        perform_instance_installation($this); // a version is created in this call

        if (!$oldVersion) {
            $version = $this->getLatestVersion();
        }

        if (!$version) {
            $version = $this->createVersion();
            $version->type = is_object($oldVersion) ? $oldVersion->type : null;
            $version->branch = is_object($oldVersion) ? $oldVersion->branch : null;
            $version->date = is_object($oldVersion) ? $oldVersion->date : null;
            $version->save();
        }

        if ($this->app == 'tiki') {
            info("Fixing permissions for {$this->name}");
            $this->getApplication()->fixPermissions();
        }

        $version->collectChecksumFromInstance($this);
        echo $access->shellExec(
            "rm -Rf {$this->tempdir}/restore"
        );
    }

    public function getExtraBackups()
    {
        $result = query(self::SQL_SELECT_BACKUP_LOCATION, [':id' => $this->id]);

        $list = [];
        while ($str = $result->fetchColumn()) {
            $list[] = $str;
        }

        return $list;
    }

    public function setExtraBackups($paths)
    {
        query(self::SQL_DELETE_BACKUP, [':id' => $this->id]);

        foreach ($paths as $path) {
            if (! empty($path)) {
                query(self::SQL_INSERT_BACKUP, [':id' => $this->id, ':loc' => $path]);
            }
        }
    }

    public function getArchives()
    {
        $backup = new Backup($this);
        return $backup->getArchives();
    }

    public function isLocked()
    {
        $access = $this->getBestAccess('scripting');
        $base_htaccess = TRIM_ROOT . '/scripts/maintenance.htaccess';
        $curr_htaccess = $this->getWebPath('.htaccess');

        return $access->fileExists($curr_htaccess)
            && file_get_contents($base_htaccess) === $access->fileGetContents($curr_htaccess);
    }

    public function lock()
    {
        if ($this->isLocked()) {
            return true;
        }
        info('Locking website...');

        $access = $this->getBestAccess('scripting');
        $access->uploadFile(TRIM_ROOT . '/scripts/maintenance.php', 'maintenance.php');
        $access->shellExec('touch maintenance.php');

        if ($access->fileExists($this->getWebPath('.htaccess'))) {
            $access->moveFile('.htaccess', '.htaccess.bak');
        }

        $access->uploadFile(TRIM_ROOT . '/scripts/maintenance.htaccess', '.htaccess');
        return $this->isLocked();
    }

    public function unlock()
    {
        if (!$this->isLocked()) {
            return true;
        }

        info('Unlocking website...');
        $access = $this->getBestAccess('scripting');
        $access->deleteFile('.htaccess');
        $access->deleteFile('maintenance.php');

        if ($access->fileExists('.htaccess.bak')) {
            $access->moveFile('.htaccess.bak', '.htaccess');
        }

        return !$this->isLocked();
    }

    public function __get($name)
    {
        if (isset($this->$name)) {
            return $this->$name;
        }
    }

    /**
     * Get instance application revision
     *
     * @return mixed
     */
    public function getRevision()
    {

        if ($this->app == 'tiki') {
            return $this->getApplication()->getRevision();
        }

        return null;
    }
}