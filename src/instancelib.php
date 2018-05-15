<?php
// Copyright (c) 2016, Avan.Tech, et. al.
// Copyright (c) 2008, Luis Argerich, Garland Foster, Eduardo Polidor, et. al.
// All Rights Reserved. See copyright.txt for details and a complete list of authors.
// Licensed under the GNU LESSER GENERAL PUBLIC LICENSE. See license.txt for details.

define('SQL_SELECT_INSTANCE', "
SELECT
    instance_id id, name, contact, webroot, weburl, tempdir, phpexec, app
FROM
    instance
;");

define('SQL_SELECT_INSTANCE_BY_ID', "
SELECT
    instance_id id, name, contact, webroot, weburl, tempdir, phpexec, app
FROM
    instance
WHERE
    instance_id = :id
;");

define('SQL_SELECT_UPDATABLE_INSTANCE', "
SELECT
    instance.instance_id id, name, contact, webroot, weburl, tempdir, phpexec, app 
FROM 
    instance
INNER JOIN
    version ON instance.instance_id = version.instance_id
INNER JOIN (
    SELECT
        MAX(version_id) version
    FROM
        version
    GROUP BY
        instance_id
    ) t ON t.version = version.version_id
WHERE
    type = 'svn' OR type = 'tarball'
;");

define('SQL_SELECT_LATEST_VERSION', "
SELECT
    version_id id, instance_id, type, branch, date
FROM
    version
WHERE
    instance_id = :id
ORDER BY
    version_id DESC
LIMIT 1;");

define('SQL_SELECT_BACKUP_LOCATION', "
SELECT
    location
FROM
    backup
WHERE
    instance_id = :id
");

define('SQL_SELECT_FILE_MAP', "
SELECT
    path, hash
FROM
    file
WHERE
    version_id = :v
    ;");

define('SQL_SELECT_FILE_COUNT_BY_VERSION', "
SELECT
    COUNT(*)
FROM
    file
WHERE
    version_id = :id
;");

define('SQL_INSERT_INSTANCE', "
INSERT OR REPLACE INTO
    instance
    (instance_id, name, contact, webroot, weburl, tempdir, phpexec, app)
VALUES
    (:id, :name, :contact, :web, :url, :temp, :phpexec, :app)
;");

define('SQL_UPDATE_INSTANCE', "
UPDATE instance
SET
    name = :name,
    contact = :contact,
    webroot = :web,
    weburl = :url,
    tempdir = :temp
WHERE
    instance_id = :id
;");

define('SQL_INSERT_BACKUP', "
INSERT INTO
    backup
    (instance_id, location)
VALUES
    (:id, :loc)
;");

define('SQL_INSERT_VERSION', "
INSERT OR REPLACE INTO
    version
    (version_id, instance_id, type, branch, date)
VALUES
    (:id, :instance, :type, :branch, :date)
;");

define('SQL_INSERT_FILE', "
INSERT INTO
    file
    (version_id, path, hash)
VALUES
    (:version, :path, :hash)
;");

define('SQL_INSERT_FILE_REPLICATE', "
INSERT INTO
    file
    (version_id, path, hash)
    SELECT
        :new, path, hash
    FROM
        file
    WHERE
        version_id = :old
;");

define('SQL_DELETE_ACCESS', "
DELETE FROM
    access
WHERE
    instance_id = :id
;");

define('SQL_DELETE_BACKUP', "
DELETE FROM
    backup
WHERE
    instance_id = :id
;");

define('SQL_DELETE_FILE', "
DELETE FROM
    file
WHERE
    path = :p and version_id = :v
;");

define('SQL_DELETE_FILE_BY_SELECT', "
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
);");

define('SQL_DELETE_INSTANCE', "
DELETE FROM
    instance
WHERE
    instance_id = :id
;");

define('SQL_DELETE_REPORT_CONTENT', "
DELETE FROM
    report_content
WHERE
    instance_id = :id OR receiver_id = :id
;");

define('SQL_DELETE_REPORT_RECEIVER', "
DELETE FROM
    report_receiver
WHERE
    instance_id = :id
;");

define('SQL_DELETE_VERSION', "
DELETE FROM
    version
WHERE
    instance_id = :id
;");

define('SQL_GET_INSTANCE_PROPERTY', "
SELECT value FROM
    property
WHERE
    instance_id = :id AND key = :key
;");

define('SQL_SET_INSTANCE_PROPERTY', "
REPLACE INTO
    property
VALUES
    (:id, :key, :value)
;");

define('SQL_DELETE_ALL_INSTANCE_PROPERTIES', "
DELETE FROM
    property
WHERE
    instance_id = :id;
;");


class Instance
{
    private $id;
    public $name;
    public $contact;
    public $webroot;
    public $weburl;
    public $tempdir;
    public $phpexec;
    public $phpversion;
    public $app;

    private $access = array();

    function getId() // {{{
    {
        return $this->id;
    } // }}}

    static function getInstances() // {{{
    {
        $result = query(SQL_SELECT_INSTANCE);

        $instances = array();
        while ($instance = $result->fetchObject('Instance'))
            $instances[$instance->id] = $instance;

        return $instances;
    } // }}}

    function getInstance($id) // {{{
    {
        $result = query(SQL_SELECT_INSTANCE_BY_ID, array(':id' => $id));

        $instance = $result->fetchObject('Instance');

        return $instance;
    } // }}}

    static function getUpdatableInstances() // {{{
    {
        $result = query(SQL_SELECT_UPDATABLE_INSTANCE);

        $instances = array();
        while ($instance = $result->fetchObject('Instance'))
            $instances[$instance->id] = $instance;

        return $instances;
    } // }}}

    function getRestorableInstances() // {{{
    {
        $dp = opendir(BACKUP_FOLDER);

        $backups = array();
        $matches = array();
        while (false !== $file = readdir($dp)) {
            if (! preg_match('/^\d+/', $file, $matches))
                continue;

            if ($instance = self::getInstance($matches[0]))
                $backups[$matches[0]] = $instance;
        }

        closedir($dp);
        return $backups;
    } // }}}

    function save() // {{{
    {
        $params = array(
            ':id' => $this->id,
            ':name' => $this->name,
            ':contact' => $this->contact,
            ':web' => $this->webroot,
            ':url' => $this->weburl,
            ':temp' => $this->tempdir,
            ':phpexec' => $this->phpexec,
            ':app' => $this->app,
        );

        query(SQL_INSERT_INSTANCE, $params);

        $rowid = rowid();
        if (! $this->id && $rowid) $this->id = $rowid;

        if(!empty($this->backup_user)) {
            $this->setProp('backup_user', $this->backup_user);
        }
        if(!empty($this->backup_group)) {
            $this->setProp('backup_group', $this->backup_group);
        }
        if(!empty($this->backup_perm)) {
            $this->setProp('backup_perm', $this->backup_perm);
        }
    } // }}}

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

        query(SQL_UPDATE_INSTANCE, $params);

        if(!empty($this->backup_user)) {
            $this->setProp('backup_user', $this->backup_user);
        }
        if(!empty($this->backup_group)) {
            $this->setProp('backup_group', $this->backup_group);
        }
        if(!empty($this->backup_perm)) {
            $this->setProp('backup_perm', $this->backup_perm);
        }
    }

    function delete() // {{{
    {
        query(SQL_DELETE_ACCESS, array(':id' => $this->id));
        query(SQL_DELETE_BACKUP, array(':id' => $this->id));
        query(SQL_DELETE_FILE_BY_SELECT, array(':id' => $this->id));
        query(SQL_DELETE_INSTANCE, array(':id' => $this->id));
        query(SQL_DELETE_REPORT_CONTENT, array(':id' => $this->id));
        query(SQL_DELETE_REPORT_RECEIVER, array( ':id' => $this->id));
        query(SQL_DELETE_VERSION, array(':id' => $this->id));
        query(SQL_DELETE_ALL_INSTANCE_PROPERTIES, array(':id' => $this->id));
    } // }}}

    function registerAccessMethod($type, $host, $user, $password = null, $port = null) // {{{
    {
        if (! $class = Access::getClassFor($type)) return;

        $access = new $class($this);
        $access->host = $host;
        $access->user = $user;
        $access->password = $password;

        if ($port) $access->port = $port;

        if ($access->firstConnect()) {
            $access->save();

            $this->access[] = $access;
            return $access;
        }
    } // }}}

    function getBestAccess($type) // {{{
    {
        if (empty($this->access))
            $this->access = Access::getAccessFor($this);
        
        // TODO: Add intelligence as more access types get added
        // types:
        //      scripting
        //      filetransfer
        return reset($this->access);
    } // }}}

    function getWebUrl($relativePath) // {{{
    {
        $weburl = rtrim($this->weburl, '/');
        
        $path = "$weburl/$relativePath";
        $path = str_replace('/./', '/', $path);

        return $path;
    } // }}}

    function getWebPath($relativePath) // {{{
    {
        $path = "{$this->webroot}/$relativePath";
        $path = str_replace('/./', '/', $path);

        return $path;
    } // }}}

    function getWorkPath($relativePath) // {{{
    {
        return "{$this->tempdir}/$relativePath";
    } // }}}

    function getProp($key) { // {{{
        $result = query(SQL_GET_INSTANCE_PROPERTY, array(':id' => $this->id, ':key' => $key));
        $result = $result->fetchObject();
        if ( $result && $result->value ) {
            return $result->value;
        }
    } // }}}

    function setProp($key, $value) { // {{{
        $result = query(SQL_SET_INSTANCE_PROPERTY, array(
            ':id' => $this->id,
            ':key' => $key,
            ':value' => $value
        ));
    } // }}}

    function createWorkPath($access = null) // {{{
    {
        if (is_null($access))
            $access = $this->getBestAccess('scripting');

        echo $access->shellExec(
            "mkdir -p {$this->tempdir}"
        );
    } // }}}

    function detectPHP() // {{{
    {
        $access = $this->getBestAccess('scripting');
        $path = $access->getInterpreterPath($this);

        $path_env = getenv('PATH');

        if (strlen($path) > 0) {
            $version = $access->getInterpreterVersion($path);
            $this->phpversion = $version;
            if ($version <  50300) return false;

            $this->phpexec = $path;
            $this->save();

            // even passing full path to php binary, we need to fix PATH
            // so scripts like setup.sh can use correct php version
            $bin_folder = dirname($path);
            if(strpos($path_env, $bin_folder) === false) {
                $access->setenv('PATH', "${bin_folder}:${path_env}");
            }

            return $version;
        }

        return false;
    } // }}}

    function detectSVN() // {{{
    {
        $access = $this->getBestAccess('scripting');
        $path = $access->getSVNPath();

        if (strlen($path) > 0)
            return $path;

        return false;
    } // }}}

    function detectDistribution() // {{{
    {
        $access = $this->getBestAccess('scripting');
        $path = $access->getInterpreterPath();
        return $access->getDistributionName($path);
    } // }}}

    function getExtensions() // {{{
    {
        $access = $this->getBestAccess('scripting');
        $content = $access->runPHP(dirname(__FILE__) . '/../scripts/get_extensions.php');
        $modules = explode("\n", $content);

        return $modules;
    } // }}}

    function findApplication() // {{{
    {
        foreach (Application::getApplications($this) as $app) {
            if ($app->isInstalled()) {
                $app->registerCurrentInstallation();
                return $app;
            }
        }

        return null;
    } // }}}

    function createVersion() // {{{
    {
        return new Version( $this->id );
    } // }}}

    function getLatestVersion() // {{{
    {
        $result = query(SQL_SELECT_LATEST_VERSION, array(':id' => $this->id));

        $object = $result->fetchObject('Version');

        return $object;
    } // }}}

    /**
     * Modern in this context means it uses composer and has console.php for shell access which arrived in Tiki 11,
     * although this may need to be changed to 12 if 11 is proved to be unreliable in these respects
     *
     * @return bool
     */
    function isModernTiki() {
        $current = $this->getLatestVersion();
        preg_match('/(\d+)\.?/', $current->branch, $matches);
        if ($matches)
            return ((float)$matches[1] >= 11);

        return false;
    }

    function getApplication() // {{{
    {
        if (empty($this->app))
            return false;

        $class = 'Application_' . ucfirst($this->app);

        $dir = dirname(__FILE__) . '/appinfo';
        if (! class_exists($class))
            require_once "$dir/{$this->app}.php";

        return new $class($this);
    } // }}}

    function backup() // {{{
    {
        $error_flag = 0;

        $access = $this->getBestAccess('scripting');

        if ($this->getApplication() == null)
            die(error('No application installed for this instance.'));

        $this->createWorkPath($access);

        $locations = $this->getApplication()->getFileLocations();
        if (!isset($locations['data'])){
            $locations['data'] = array();
        }
        $locations['data'] = array_merge($locations['data'], $this->getExtraBackups());

        $backup_directory = "{$this->id}-{$this->name}";
        $approot = BACKUP_FOLDER . "/$backup_directory";
        if (! file_exists($approot))
            mkdir($approot, 0755, true);

        if (file_exists("{$approot}/manifest.txt"))
            `rm $approot/manifest.txt`;

        $app = $this->getApplication();
        $app->removeTemporaryFiles();

        // Bring all remote files locally
        info('Downloading files locally...');
        $errorList = [];
        foreach ($locations as $type => $remotes) {
            if (!is_array($remotes)){
                continue;
            }
            foreach ($remotes as $remote) {
                $hash = md5($remote);
                $locmirror = "{$approot}/{$hash}";
                $error = $access->localizeFolder($remote, $locmirror);
                $error_flag += $error;
                if ($error) {
                    $errorList[] = ['remote' => $remote, 'code' => $error];
                }
                `echo "$hash    $type    $remote" >> $approot/manifest.txt`;
            }
        }

        if ($error_flag) {
            // Do something
            $message = "Backup has failed while downloading files into TRIM.\r\n";
            $message .= "{$this->name}\r\n";
            $message .= "\r\nList of failures:\r\n\r\n";
            foreach ($errorList as $errorInstance) {
                $message .= "* " . $errorInstance['remote']. ": code " . $errorInstance['code'] . "\r\n";
            }
            if ($access instanceof Access_SSH || $access instanceof Access_Local) {
                $message .= "\r\n";
                $message .= '<a href="https://lxadm.com/Rsync_exit_codes">Rsync Exit Codes</a>';
                $message .= "\r\n";
            }

            mail($this->contact, 'TRIM backup error', $message);
            return null;
        }

        info('Obtaining database dump...');

        // There is not an easy way to get the return value
        $target = "{$approot}/database_dump.sql";
        $this->getApplication()->backupDatabase($target);

        // Perform archiving
        $current = getcwd();
        chdir(BACKUP_FOLDER);

        $archiveLocation = ARCHIVE_FOLDER . "/{$backup_directory}";

        if ($this->detectDistribution() === 'ClearOS') {
            if(is_link($archiveLocation)) {
                if (!file_exists(readlink($archiveLocation))) {
                    warning('Found broken link on backup folder, removing it ...');
                    $access->shellExec('rm -fv ' . escapeshellarg($archiveLocation), true);
                } else {
                    warning(
                        "Found symlink on backup folder. Moving backups to TRIM folder and"
                        . " and symlinking it on the opposite direction."
                    );

                    $realArchiveLocation = readlink($archiveLocation);
                    $access->shellExec('rm -fv ' . escapeshellarg($archiveLocation), true);
                    $access->shellExec('mv ' . escapeshellarg($realArchiveLocation) . ' ' . escapeshellarg($archiveLocation), true);

                    debug("Symlinking $archiveLocation to $realArchiveLocation");
                    $access->shellExec('ln -nsf ' . escapeshellarg($archiveLocation) . ' ' . escapeshellarg($realArchiveLocation));
                    unset($realArchiveLocation);
                }
            } else {
                $archiveLocationLink = dirname($this->webroot) . '/backup';
                debug("Symlinking $archiveLocation to $archiveLocationLink");
                $access->shellExec('ln -nsf ' . escapeshellarg($archiveLocation) . ' ' . escapeshellarg($archiveLocationLink));
            }
        }

        $backup_user = $this->getProp('backup_user');
        $backup_group = $this->getProp('backup_group');
        $backup_perm = intval($this->getProp('backup_perm') ?: 0770);

        if (!file_exists($archiveLocation)) {
            mkdir($archiveLocation, $backup_perm, true);
        }

        if ($backup_perm) {
            info("Setting permissions on '{$archiveLocation}' to " . decoct($backup_perm) . "");
            chmod($archiveLocation, $backup_perm);
        }

        if ($backup_user) {
            info("Changing '{$archiveLocation}' owner to {$backup_user}");
            chown($archiveLocation, $backup_user);
        }

        if ($backup_group) {
            info("Changing '{$archiveLocation}' group to {$backup_group}");
            chgrp($archiveLocation, $backup_group);
        }

        $output = array();
        $return_val = -1;
        $tar = $archiveLocation . "/{$backup_directory}_" . date( 'Y-m-d_H-i-s' ) . '.tar.bz2';

        info("Creating archive at {$tar}");
        $command = 'nice -n 19 tar -cjp'
            . ' -C ' . BACKUP_FOLDER
            . ' -f ' . escapeshellarg($tar)
            . ' '    . escapeshellarg($backup_directory);

        debug($command);
        exec($command, $output, $return_var);
        debug($output, $prefix="({$return_var})>>", "\n\n");

        $error_flag += $return_var;
        if ($return_var != 0)
            info("TAR exit code: $return_var");

        chdir($current);

        if ($error_flag) {
            $message = "Your TRIM backup has failed packing files into TRIM.\r\n";
            $message .= "{$this->name}\r\n";
            $message .= "TAR exit code: $return_var\r\n";
            mail($this->contact , 'TRIM backup error', $message);
            return null;
        } 

        if ($backup_perm) {
            info("Setting permissions on '{$tar}' to " . decoct($backup_perm) . "");
            chmod($tar, $backup_perm);
        }

        if ($backup_user) {
            info("Changing '{$tar}' owner to {$backup_user}");
            chown($tar, $backup_user);
        }

        if ($backup_group) {
            info("Changing '{$tar}' group to {$backup_group}");
            chgrp($tar, $backup_group);
        }

        return $tar;
    } // }}}

    function restore($src_app, $archive, $clone = false)
    {
        info(sprintf("Uploading : %s", basename($archive)));

        $base = basename($archive);
        list($basetardir, $trash) = explode('_', $base, 2);
        $remote = $this->getWorkPath($base);
        
        $access = $this->getBestAccess('scripting');
        $access->uploadFile($archive, $remote);

        echo $access->shellExec(
            "mkdir -p {$this->tempdir}/restore",
            "tar -jxp -C {$this->tempdir}/restore -f " . escapeshellarg($remote)
        );

        info('Reading manifest...');

        $current = trim(`pwd`);
        chdir(TEMP_FOLDER);
        exec(debug("tar -jxf $archive $basetardir/manifest.txt"));
        $manifest = file_get_contents("{$basetardir}/manifest.txt");
        chdir($current);

        foreach (explode("\n", $manifest) as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            list($hash, $type, $location) = explode('    ', $line, 3);
            $src_path = $location;
            $src_base = basename($location);

            if ($clone) {
                $location = $this->webroot;
            } else {
                echo "Previous host used: $location\n";
                $location = promptUser('New location', $this->webroot);
            }

            $dst_path = ($type == 'app') ? $location : $src_path;
            info(sprintf('Copying %s files (%s -> %s)...', $type, $src_path, $dst_path));

            $syncpaths = (object) array(
                'from' => escapeshellarg($this->getWorkPath("restore/{$basetardir}/$hash/$src_base")),
                'to'   => escapeshellarg(rtrim($dst_path, '/'))
            );

            $out = $access->shellExec(
                sprintf('mkdir -p %s', $syncpaths->to),
                sprintf('rsync -a %s %s --exclude .htaccess --exclude db/local.php --delete',
                    "{$syncpaths->from}/",
                    "{$syncpaths->to}/"
                )
            );

            $access->shellExec(sprintf('rsync -v %s %s%s',
                "{$syncpaths->from}/.htaccess",
                "{$syncpaths->to}/.htaccess",
                $this->isLocked() ? '.bak' : ''
            ));

            trim_output("REMOTE $out");
        }

        $this->app = $src_app;
        $oldVersion = $this->getLatestVersion();
        $version = $this->createVersion();
        $version->type = is_object($oldVersion) ? $oldVersion->type : NULL;
        $version->branch = is_object($oldVersion) ? $oldVersion->branch : NULL;
        $version->date = is_object($oldVersion) ? $oldVersion->date : NULL;
        $version->save();
        $this->save();
        
        perform_database_setup($this,
            "{$this->tempdir}/restore/{$basetardir}/database_dump.sql");

        if ($this->app == 'tiki')
            $this->getApplication()->fixPermissions();

        info('Cleaning up...');

        perform_instance_installation($this);
        info("Fixing permissions for {$this->name}");
        $this->getApplication()->fixPermissions();
        $version->collectChecksumFromInstance($this);

        echo $access->shellExec(
            "rm -Rf {$this->tempdir}/restore"
        );
    } // }}}

    function getExtraBackups() // {{{
    {
        $result = query(SQL_SELECT_BACKUP_LOCATION, array(':id' => $this->id));

        $list = array();
        while ($str = $result->fetchColumn()) $list[] = $str;

        return $list;
    } // }}}

    function setExtraBackups( $paths ) // {{{
    {
        query(SQL_DELETE_BACKUP, array(':id' => $this->id));

        foreach ($paths as $path) {
            if (! empty($path))
                query(SQL_INSERT_BACKUP, array(':id' => $this->id, ':loc' => $path));
        }
    } // }}}

    function getArchives() // {{{
    {
        return array_reverse(glob(ARCHIVE_FOLDER . "/{$this->id}-*/{$this->id}*_*.tar.bz2"));
    } // }}}

    function isLocked() { // {{{
        $access = $this->getBestAccess('scripting');
        $trim_htaccess = file_get_contents(TRIM_ROOT . '/scripts/maintenance.htaccess');
        $dest_htaccess = $access->fileGetContents($this->getWebPath('.htaccess'));
        return trim($trim_htaccess) === trim($dest_htaccess);
    } // }}}

    function lock() // {{{
    {
        if ($this->isLocked()) {
            return true;
        }
        info('Locking website...');

        $access = $this->getBestAccess('scripting');
        $access->uploadFile(TRIM_ROOT . '/scripts/maintenance.php', 'maintenance.php');

        if ($access->fileExists($this->getWebPath('.htaccess')))
            $access->moveFile('.htaccess', '.htaccess.bak');

        $access->uploadFile(TRIM_ROOT . '/scripts/maintenance.htaccess', '.htaccess');
        return $this->isLocked();
    } // }}}

    function unlock() // {{{
    {
        if(!$this->isLocked()) {
            return true;
        }

        info('Unlocking website...');
        $access = $this->getBestAccess('scripting');
        $access->deleteFile('.htaccess');

        if ($access->fileExists('.htaccess.bak'))
            $access->moveFile('.htaccess.bak', '.htaccess');

        return !$this->isLocked();
    } // }}}

    function __get($name) // {{{
    {
        if (isset($this->$name))
            return $this->$name;
    } // }}}
}

class Version
{
    private $id;
    private $instanceId;
    public $type;
    public $branch;
    public $date;

    function __construct($instanceId = null) // {{{
    {
        $this->instanceId = $instanceId;
    } // }}}

    public static function buildFake($type, $branch) // {{{{
    {
        $v = new self;
        $v->type = $type;
        $v->branch = $branch;
        $v->date = date('Y-m-d');

        return $v;
    } // }}}

    function save() // {{{
    {
        $params = array(
            ':id' => $this->id,
            ':instance' => $this->instanceId,
            ':type' => $this->type,
            ':branch' => $this->branch,
            ':date' => $this->date,
        );

        query(SQL_INSERT_VERSION, $params);

        $rowid = rowid();
        if (! $this->id && $rowid) $this->id = $rowid;
    } // }}}

    function hasChecksums() // {{{
    {
        $result = query(SQL_SELECT_FILE_COUNT_BY_VERSION, array(':id' => $this->id));

        return ($result->fetchColumn() > 0);
    } // }}}

    function performCheck(Instance $instance) // {{{
    {
        $app = $instance->getApplication();
        $app->beforeChecksumCollect();

        $access = $instance->getBestAccess('scripting');

        $output = $access->runPHP(
            dirname(__FILE__) . '/../scripts/generate_md5_list.php',
            array($instance->webroot)
        );
        
        $known = $this->getFileMap();

        $newFiles = array();
        $modifiedFiles = array();
        $missingFiles = array();

        foreach (explode("\n", $output) as $line) {
            list($hash, $filename) = explode(':', $line);

            if (! isset($known[$filename]))
                $newFiles[$filename] = $hash;
            else {
                if ($known[$filename] != $hash)
                    $modifiedFiles[$filename] = $hash;

                unset($known[$filename]);
            }
        }

        foreach ($known as $filename => $hash)
            $missingFiles[$filename] = $hash;

        return array(
            'new' => $newFiles,
            'mod' => $modifiedFiles,
            'del' => $missingFiles,
        );
    } // }}}

    function collectChecksumFromSource(Instance $instance) // {{{
    {
        debug("Collect Checksum From Source");
        $app = $instance->getApplication();

        $folder = cache_folder($app, $this);

        $app->extractTo($this, $folder);

        ob_start();
        include dirname(__FILE__) . '/../scripts/generate_md5_list.php';
        $content = ob_get_contents();
        ob_end_clean();

        $this->saveHashDump($content, $app);
    } // }}}

    function collectChecksumFromInstance(Instance $instance) // {{{
    {
        debug("Collect Checksum From Instance");
        $app = $instance->getApplication();
        $app->beforeChecksumCollect();

        $access = $instance->getBestAccess('scripting');
        $output = $access->runPHP(
            dirname(__FILE__) . '/../scripts/generate_md5_list.php',
            array($instance->webroot)
        );
        
        $this->saveHashDump($output, $app);
    } // }}}

    function replicateChecksum(Version $old) // {{{
    {
        query(SQL_INSERT_FILE_REPLICATE,
            array(':new' => $this->id, ':old' => $old->id));
    } // }}}

    function recordFile($hash, $filename, Application $app) // {{{
    {
        query(SQL_INSERT_FILE,
            array(':version' => $this->id, ':path' => $filename, ':hash' => $hash));
    } // }}}

    function removeFile($filename) // {{{
    {
        query(SQL_DELETE_FILE,
            array(':v' => $this->id, ':p' => $filename));
    } // }}}

    function replaceFile($hash, $filename, Application $app) // {{{
    {
        $this->removeFile($filename);
        $this->recordFile($hash, $filename, $app);
    } // }}}

    function getFileMap() // {{{
    {
        $map = array();
        $result = query(SQL_SELECT_FILE_MAP, array(':v' => $this->id));

        while ($row = $result->fetch()) {
            extract($row);
            $map[$path] = $hash;
        }

        return $map;
    } // }}}

    private function saveHashDump($output, Application $app) // {{{
    {
        query('BEGIN TRANSACTION');

        $entries = explode("\n", $output);
        foreach ($entries as $line) {
            $parts = explode(':', $line);
            if (count($parts) != 2) continue;

            list($hash, $file) = $parts;
            $this->recordFile($hash, $file, $app);
        }

        query('COMMIT');
    } // }}}
}

// vi: expandtab shiftwidth=4 softtabstop=4 tabstop=4
