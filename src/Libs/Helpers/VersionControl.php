<?php
/**
 * @copyright (c) Copyright by authors of the Tiki Manager Project. All Rights Reserved.
 *     See copyright.txt for details and a complete list of authors.
 * @licence Licensed under the GNU LESSER GENERAL PUBLIC LICENSE. See LICENSE for details.
 */

namespace TikiManager\Libs\Helpers;

class VersionControl
{

    public static function formatBranch($branch)
    {
        if (strtolower($_ENV['DEFAULT_VCS']) == 'svn') {
            return static::formatSvnBranch($branch);
        }

        if (strtolower($_ENV['DEFAULT_VCS']) == 'git') {
            return static::formatGitBranch($branch);
        }

        return $branch;
    }

    protected static function formatGitBranch($branch)
    {
        if ($branch == 'trunk') {
            return 'master';
        }

        if (preg_match('/^branches\/\d+\.(\d+|x)$/', $branch)) {
            return str_replace('branches/', '', $branch);
        }

        return $branch;
    }

    protected static function formatSvnBranch($branch)
    {
        if ($branch == 'master') {
            return 'trunk';
        }

        if (preg_match('/^\d+\.(\d+|x)$/', $branch)) {
            return 'branches/' . $branch;
        }

        return $branch;
    }
}
