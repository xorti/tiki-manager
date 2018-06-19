<?php
// Copyright (c) 2016, Avan.Tech, et. al.
// Copyright (c) 2008, Luis Argerich, Garland Foster, Eduardo Polidor, et. al.
// All Rights Reserved. See copyright.txt for details and a complete list of authors.
// Licensed under the GNU LESSER GENERAL PUBLIC LICENSE. See license.txt for details.

require_once __DIR__ . '/SSHHostSeclibAdapter.php';
require_once __DIR__ . '/SSHHostWrapperAdapter.php';

class SSH_Host
{
    private $adapter;
    private $location;
    private $env = array();

    private $host;
    private $user;
    private $port;

    private $copy_id_port_in_host;

    function __construct($host, $user, $port, $adapter_class=null)
    {
        $this->host = $host ?: '';
        $this->user = $user ?: '';
        $this->port = $port ?: 22;
        $this->checkCopyId();
        $this->selectAdapter($adapter_class);
    }

    function chdir($location)
    {
        $this->location = $location;
    }

    function checkCopyId()
    {
        $this->copy_id_port_in_host = true;
        $ph = popen('ssh-copy-id -h 2>&1', 'r');
        if (! is_resource($ph))
            error('Required command (ssh-copy_id) not found.');
        else {
            if (preg_match('/p port/', stream_get_contents($ph)))
                $this->copy_id_port_in_host = false;
            pclose($ph);
        }
    }

    function setenv($var, $value)
    {
        $this->env[$var] = $value;
        $this->adapter->setEnv($this->env);
    }

    function setupKey($publicKeyFile)
    {
        $this->adapter->unsetHandle();
        $file = escapeshellarg($publicKeyFile);

        if ($this->copy_id_port_in_host) {
            $host = escapeshellarg("-p {$this->port} {$this->user}@{$this->host}");
            `ssh-copy-id -i $file $host`;
        }
        else {
            $port = escapeshellarg($this->port);
            $host = escapeshellarg("{$this->user}@{$this->host}");
            `ssh-copy-id -i $file -p $port $host`;
        }
    }

    public function runCommand($command, $options=array())
    {
        return $this->adapter->runCommand($command, $options);
    }

    public function runCommands($commands, $output = false)
    {
        if (!is_array($commands)) {
            $commands = func_get_args();
            $output = end($commands) === true;
            $commands = array_filter($commands, 'is_string');
        }
        return $this->adapter->runCommands($commands, $output);
    }

    public function sendFile($localFile, $remoteFile)
    {
        return $this->adapter->sendFile($localFile, $remoteFile);
    }

    public function receiveFile($remoteFile, $localFile)
    {
        return $this->adapter->receiveFile($remoteFile, $localFile);
    }

    public function openShell($workingDir = '')
    {
        $key = SSH_KEY;
        $port = null;
        if ($this->port != 22) $port = " -p {$this->port} ";
        if (strlen($workingDir) > 0) {
            $command = "ssh $port -i $key {$this->user}@{$this->host} " .
                "-t 'cd {$workingDir}; pwd; bash --login'";
        }
        else
            $command = "ssh $port -i $key {$this->user}@{$this->host}";
        
        passthru($command);
    }

    public function rsync($args=array())
    {
        $return_val = -1;
        if(empty($args['src']) || empty($args['dest'])) {
            return $return_val;
        }

        $key = SSH_KEY;
        $user = $this->user;
        $host = $this->host;
        $src = $args['src'];
        $dest = $args['dest'];
        $port = $this->port ;

        $localHost = new Local_Host();
        $command = new Host_Command('rsync', array(
            '-a', '-L', '--delete',
            '-e', "ssh -p {$port} -i $key",
            $args['src'],
            "{$user}@{$host}:{$args['dest']}"
        ));
        $localHost->runCommand($command);
        $return_var = $command->getReturn();

        if ($return_var != 0)
            info("RSYNC exit code: $return_var");

        return $return_var;
    }

    private function selectAdapter($classname)
    {
        $classname = $classname ?: 'SSH_Host_Seclib_Adapter';

        try {
            $this->adapter = new $classname(
                $this->host,
                $this->user,
                $this->port
            );
        } catch (Exception $e) {
            $this->adapter = new SSH_Host_Wrapper_Adapter(
                $this->host,
                $this->user,
                $this->port
            );
            debug("Unable to use $classname, falling back to SSH_Host_Wrapper_Adapter");
        }
        return $this->adapter;
    }
}

// vi: expandtab shiftwidth=4 softtabstop=4 tabstop=4
