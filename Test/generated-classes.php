<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

/**
 * Magento writes its Factory and Proxy classes during `setup:di:compile`, so a
 * checkout that has never been compiled has none of them and a test that mocks
 * one cannot load it. The framework's own generator writes them on demand.
 */
$generatedDir = getenv('M2_GENERATED') ?: dirname(__DIR__) . '/var/generated/';

if (!is_dir($generatedDir) && !mkdir($generatedDir, 0775, true) && !is_dir($generatedDir)) {
    fwrite(STDERR, "Cannot create the code-generation directory at {$generatedDir}.\n");

    exit(1);
}

$codeGenerator = new \Magento\Framework\Code\Generator(
    new \Magento\Framework\Code\Generator\Io(
        new \Magento\Framework\Filesystem\Driver\File(),
        $generatedDir
    ),
    [
        \Magento\Framework\ObjectManager\Code\Generator\Factory::ENTITY_TYPE
            => \Magento\Framework\ObjectManager\Code\Generator\Factory::class,
        \Magento\Framework\ObjectManager\Code\Generator\Proxy::ENTITY_TYPE
            => \Magento\Framework\ObjectManager\Code\Generator\Proxy::class,
    ],
    null,
    new \Psr\Log\NullLogger()
);

/**
 * The generator reads virtual types off an object manager and builds its entity
 * generators through one; anything else it asks for is a mistake, so say so.
 */
$codeGenerator->setObjectManager(
    new class implements \Magento\Framework\ObjectManagerInterface {
        private ?\Magento\Framework\ObjectManager\ConfigInterface $config = null;

        public function create($type, array $arguments = []): object
        {
            return new $type(
                $arguments['sourceClassName'] ?? null,
                $arguments['resultClassName'] ?? null,
                $arguments['ioObject'] ?? null
            );
        }

        public function get($type): object
        {
            if ($type !== \Magento\Framework\ObjectManager\ConfigInterface::class) {
                throw new \LogicException(
                    "The code generator asked for {$type}; the test bootstrap provides no such service."
                );
            }

            return $this->config ??= new \Magento\Framework\ObjectManager\Config\Config();
        }

        public function configure(array $configuration): void
        {
        }
    }
);

// Runs last, so it only sees a class the composer autoloader could not find.
spl_autoload_register(static function (string $class) use ($codeGenerator): void {
    try {
        $codeGenerator->generateClass($class);
    } catch (\Throwable) {
        // An autoloader may not throw: a name this generator cannot build is
        // one for some other autoloader, or genuinely absent.
    }
});
