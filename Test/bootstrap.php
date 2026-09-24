<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

$vendorDir = getenv('M2_VENDOR') ?: '';

if ($vendorDir === '' || !is_dir($vendorDir . '/composer')) {
    fwrite(
        STDERR,
        "Set M2_VENDOR to a Magento installation's vendor directory, e.g.\n"
        . "  M2_VENDOR=/path/to/magento/vendor vendor/bin/phpunit\n"
    );

    exit(1);
}

require_once $vendorDir . '/composer/ClassLoader.php';

$loader = new \Composer\Autoload\ClassLoader($vendorDir);

/** @var array<string, string[]> $psr4 */
$psr4 = require $vendorDir . '/composer/autoload_psr4.php';

foreach ($psr4 as $namespace => $paths) {
    $loader->setPsr4($namespace, $paths);
}

/** @var array<string, string[]> $psr0 */
$psr0 = require $vendorDir . '/composer/autoload_namespaces.php';

foreach ($psr0 as $namespace => $paths) {
    $loader->set($namespace, $paths);
}

$loader->addClassMap(require $vendorDir . '/composer/autoload_classmap.php');

/**
 * The module under test and, when they are checked out beside it, the sibling
 * modules it depends on.
 */
$moduleDir = dirname(__DIR__);

foreach (array_merge([$moduleDir], glob(dirname($moduleDir) . '/module-*', GLOB_ONLYDIR) ?: []) as $dir) {
    if (!is_file($dir . '/composer.json')) {
        continue;
    }

    $manifest = json_decode((string) file_get_contents($dir . '/composer.json'), true);

    foreach ($manifest['autoload']['psr-4'] ?? [] as $namespace => $relative) {
        $loader->setPsr4($namespace, [rtrim($dir . '/' . $relative, '/')]);
    }
}

$loader->register(true);

// Magento's translation helper lives in a plain include rather than a class.
foreach (['/magento/framework/Phrase/__.php'] as $file) {
    if (is_file($vendorDir . $file)) {
        require_once $vendorDir . $file;
    }
}

if (!function_exists('__')) {
    /**
     * Stand-in for when the framework's helper file is not present.
     */
    function __(...$args): \Magento\Framework\Phrase
    {
        $text = array_shift($args);

        return new \Magento\Framework\Phrase((string) $text, $args);
    }
}

if (!defined('BP')) {
    define('BP', dirname($vendorDir));
}

// Factory and Proxy classes Magento would have generated at build time.
require_once __DIR__ . '/generated-classes.php';
