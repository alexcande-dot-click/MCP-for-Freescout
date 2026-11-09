<?php

/*
|--------------------------------------------------------------------------
| Register module routes
|--------------------------------------------------------------------------
|
| FreeScout executes this file when the module starts.
|
*/

if (!app()->routesAreCached()) {
    require __DIR__.'/Http/routes.php';
}
