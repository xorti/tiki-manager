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

echo "Note: It is only possible to restore a backup on a blank install.\n\n";
$selection = selectInstances( $instances, "Which instance do you want to restore on?\n" );

$restorable = Instance::getRestorableInstances();

foreach( $selection as $instance )
{
	info( $instance->name );

	echo "Which instance do you want to restore?\n";

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

	info( "Uploading $file" );
	$base = basename( $file );
	list( $basetardir, $trash ) = explode( "_", $base, 2 );
	$remote = $instance->getWorkPath( $base );

	$access = $instance->getBestAccess('scripting');

	$access->uploadFile( $file, $remote );
	echo $access->shellExec(
		"mkdir -p {$instance->tempdir}/restore",
		"tar -jx -C {$instance->tempdir}/restore -f " . escapeshellarg( $remote )
	);

	info( "Reading manifest" );
	$current = trim(`pwd`);
	chdir( TEMP_FOLDER );
	`tar -jxvf $file $basetardir/manifest.txt `;
	$manifest = file_get_contents( "{$basetardir}/manifest.txt" );
	chdir( $current );

	foreach( explode( "\n", $manifest ) as $line )
	{
		$line = trim($line);
		if( empty($line) ) continue;

		list( $hash, $location ) = explode( "    ", $line, 2 );
		$base = basename( $location );

		echo "Previous host used $location\n";
		$location = readline( "New location: [{$instance->webroot}] " );
		if( empty( $location ) ) $location = $instance->webroot;
		$location = escapeshellarg( rtrim( $location, '/' ) );

		$normal = escapeshellarg( $instance->getWorkPath( "restore/{$basetardir}/$hash/$base/" ) ) . '*';
		$hidden = escapeshellarg( $instance->getWorkPath( "restore/{$basetardir}/$hash/$base/" ) ) . '.* 2>> /tmp/trim.output' ;
		info("Copying files");

		$access->shellExec(
			"mkdir -p $location",
			"mv $normal $location/",
			"mv $hidden $location/"
		);
	}

	$oldVersion = $instance->getLatestVersion();
	$instance->app = $single->app;
	$version = $instance->createVersion();
	$version->type = $oldVersion->type;
	$version->branch = $oldVersion->branch;
	$version->date = $oldVersion->date;
	$version->save();
	$instance->save();
	
	perform_database_setup( $instance, "{$instance->tempdir}/restore/{$basetardir}/database_dump.sql" );

	$version->collectChecksumFromInstance( $instance );

	if( $instance->app == 'tiki' )
		$instance->getApplication()->fixPermissions();

	info("Cleaning up");
	echo $access->shellExec(
		"rm -Rf {$instance->tempdir}/restore"
	);

	info ("It is now time to test your site {$instance->name}");
	info ("If there are issues, try make fix");
	info ("If there are still issues, connect with make access to troubleshoot directly on the server");
}
