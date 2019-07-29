<?php
/**
 * @copyright (c) Copyright by authors of the Tiki Manager Project. All Rights Reserved.
 *     See copyright.txt for details and a complete list of authors.
 * @copyright (c) 2016, Avan.Tech, et. al.
 * @copyright (c) 2008, Luis Argerich, Garland Foster, Eduardo Polidor, et. al.
 * @licence Licensed under the GNU LESSER GENERAL PUBLIC LICENSE. See LICENSE for details.
 */

use TikiManager\Application\Tiki;
use TikiManager\Command\Helper\CommandHelper;

ini_set('zlib.output_compression', 0);
header('Content-Encoding: none'); //Disable apache compression

ob_start();
require dirname(__FILE__) . "/../config.php";
require dirname(__FILE__) . "/../include/layout/web.php";

require TRIMPATH . '/src/env_setup.php';
ob_end_clean();

ob_implicit_flush(true);
ob_end_flush();

$sourceInstanceId = (int) $_POST['source'] ?? 0;
$targetInstanceId = (int) $_POST['id'] ?? 0;
$sourceInstance = TikiManager\Application\Instance::getInstance($sourceInstanceId);
$targetInstance = TikiManager\Application\Instance::getInstance($targetInstanceId);
$branch = $_POST['branch'] ?? '';

if (!empty($sourceInstanceId) && !empty($targetInstance)) {
    $tikiApplication = new Tiki($targetInstance);
    $versions_raw = $tikiApplication->getVersions();
    foreach ($versions_raw as $version) {
        if ($version->branch == $branch) {
            $versionSel = $version;
            break;
        }
    }
    if (empty($versionSel)) {
        error('Unknown branch');
        return;
    }
    try {
        warning("Initiating backup of {$sourceInstance->name}");
        $archive = $sourceInstance->backup();

        warning("Initiating clone of {$sourceInstance->name} to {$targetInstance->name}");
        $targetInstance->lock();
        $targetInstance->restore($sourceInstance->app, $archive, true);

        $app = $targetInstance->getApplication();
        $app->performUpgrade($targetInstance, $versionSel);

        $targetInstance->unlock();

        info("Deleting archive...");
        $access = $sourceInstance->getBestAccess('scripting');
        $access->shellExec("rm -f " . $archive);
    } catch (Exception $ex) {
        error($ex->getMessage());
    }
} else {
    error('Unknown instance');
}
