<?php
/* Port runner bootstrap (loaded via auto_prepend_file before Codeception):
 * upstream bootstrap, PHPUnit-era compat aliases, and an SPL autoloader
 * replacing the composer psr-4 mappings from the upstream composer.json. */
require dirname(__DIR__) . '/tests/_bootstrap.php';

if (!class_exists('PHPUnit_Framework_Exception', false) && class_exists('PHPUnit\\Framework\\Exception')) {
    class_alias('PHPUnit\\Framework\\Exception', 'PHPUnit_Framework_Exception');
}

$root = dirname(__DIR__);

/* Patched copies of codeception/specify (PHPUnit 9+ compat), taking
 * precedence over the vendored originals. */
spl_autoload_register(function ($class) use ($root) {
    static $map = array(
        'Codeception\\Lib\\Connector\\Phalcon\\' => '/tests-port/fixed-classes/Codeception/Lib/Connector/Phalcon/',
        'Codeception\\Verify\\'                 => '/tests-port/fixed-classes/verify-src-Codeception/Verify/',
        'Codeception\\Specify\\' => '/tests-port/specify-fixed/Codeception/Specify/',
        'Codeception\\Specify'    => '/tests-port/specify-fixed/Codeception/Specify.php',
    );
    foreach ($map as $prefix => $target) {
        if ($prefix[-1] === '\\' ? strpos($class, $prefix) === 0 : $class === $prefix) {
            $file = $prefix[-1] === '\\'
                ? $root . $target . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'
                : $root . $target;
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    }
}, true, true);

spl_autoload_register(function ($class) use ($root) {
    static $map = array(
        'Phalcon\\Test\\Unit\\'      => '/tests/unit/',
        'Phalcon\\Test\\Module\\'    => '/tests/_support/Module/',
        'Phalcon\\Test\\Listener\\'  => '/tests/_data/listener/',
        'Phalcon\\Test\\Models\\'   => '/tests/_data/models/',
        'Phalcon\\Test\\Acl\\'      => '/tests/_data/acl/',
        'Phalcon\\Test\\'            => '/tests/_data/',
        'Phalcon\\Test\\Db\\'        => '/tests/_data/db/',
    );
    foreach ($map as $prefix => $dir) {
        if (strpos($class, $prefix) === 0) {
            $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = $root . $dir . $rel . '.php';
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    }
});

/* verify() / expect() helpers (the verify package is vendored locally
 * so the string-aware contains/notContains shim always wins) */
require_once $root . '/tests-port/fixed-classes/verify-src-Codeception/function.php';

/* Helper module used by the suite config */
require_once $root . '/tests/_support/Helper/Unit.php';
