<?php
// Copyright (c) 2008, Luis Argerich, Garland Foster, Eduardo Polidor, et. al.
// All Rights Reserved. See copyright.txt for details and a complete list of authors.
// Licensed under the GNU LESSER GENERAL PUBLIC LICENSE. See license.txt for details.

include_once dirname(__FILE__) . "/../src/env_setup.php";
include_once dirname(__FILE__) . "/../src/dbsetup.php";

define( 'ARG_SWITCH', $_SERVER['argc'] == 2 && $_SERVER['argv'][1] == 'switch' );

$raw = Instance::getInstances();
$instances = array();
$all = array();
foreach( $raw as $instance )
{
	$all[$instance->id] = $instance;

	if( ! $instance->getApplication() )
		$instances[$instance->id] = $instance;
}

echo color("\nNote: It is only possible to restore a backup on a blank install.\n\n", 'yellow');
$selection = selectInstances( $instances, "Which instance do you want to restore to?\n" );

$restorable = Instance::getRestorableInstances();

foreach( $selection as $instance )
{
	info( $instance->name );

	echo "Which instance do you want to restore from?\n";

	printInstances( $restorable );

	$single = readline( ">>> " );

	if( ! $single = reset( getEntries( $restorable, $single ) ) )
	{
		warning("No instance selected.");
		continue;
	} 

	$files = $single->getArchives();
	echo "Which backup do you want to restore?\n";
	foreach( $files as $key => $path )
		echo "[$key] ".basename($path)."\n";

	$file = readline( ">>> " );
	if( ! $file = reset( getEntries( $files, $file ) ) )
	{
		warning("Skip: No archive file selected.");
		continue;
	}

	$instance->restore( $single->app, $file );

	info ("It is now time to test your site {$instance->name}");
	info ("If there are issues, connect with make access to troubleshoot directly on the server");
	info ("You'll need to login to this restored instance and update the file paths with the new values.");
	info ("WARNING: If you are restoring on the same server, this can lead to data corruption as both the original and restored Tiki are using the same folder for storage.");
}
