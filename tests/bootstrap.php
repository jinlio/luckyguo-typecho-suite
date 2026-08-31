<?php
declare(strict_types=1);

// The public package intentionally does not vendor Typecho core.  Unit tests
// load pure helpers and integration tests are skipped unless a disposable
// Typecho root is supplied by TYPECHO_ROOT.
$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
}

require_once __DIR__ . '/../plugins/SuiteSearch/CircuitBreaker.php';

if (class_exists('Typecho\\Loader')) {
    if (method_exists('Typecho\\Loader', 'registerAutoload')) {
        \Typecho\Loader::registerAutoload();
    } elseif (method_exists('Typecho\\Loader', 'register')) {
        \Typecho\Loader::register();
    }
}
