<?php

/*
 * Test bootstrap.
 *
 * The module is exercised inside a real FreeScout installation, whose location
 * is given by the FREESCOUT_PATH environment variable (see devenv/test.sh).
 */

$freescoutPath = getenv('FREESCOUT_PATH');

if (!$freescoutPath || !is_file($freescoutPath.'/vendor/autoload.php')) {
    fwrite(STDERR, "FREESCOUT_PATH must point to a FreeScout installation.\n");
    exit(1);
}

$loader = require $freescoutPath.'/vendor/autoload.php';

// Resolve the module namespace from this checkout, independently of how (or
// whether) it is linked into FreeScout's Modules directory.
$loader->addPsr4('Modules\\McpForFreescout\\', dirname(__DIR__), true);
