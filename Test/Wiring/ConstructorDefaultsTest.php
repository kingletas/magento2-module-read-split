<?php
/**
 * @package   Kingletas_ReadSplit
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Kingletas\ReadSplit\Test\Wiring;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use UnitEnum;

/**
 * What setup:di:compile can copy: it writes every constructor default into the store's generated metadata with
 * var_export, and an object written that way is a call to a __set_state() no class here has, so the store stops.
 * It runs on the command line, so a default taken from the runtime is frozen as the command line's value.
 */
class ConstructorDefaultsTest extends TestCase
{
    private const string MODULE = __DIR__ . '/../..';

    private const array NOT_SHIPPED_CODE = ['Test', 'vendor', 'packaging', '.github', 'docs', 'local.d'];

    #[DataProvider('classes')]
    public function testNoConstructorDefaultIsAnObject(string $class): void
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        $objects = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if (!$parameter->isDefaultValueAvailable()) {
                continue;
            }

            // Wrapped in an array so an object inside an array default is found as well as one on its own.
            $values = [$parameter->getDefaultValue()];

            array_walk_recursive($values, static function (mixed $value) use (&$objects, $parameter): void {
                // An enum case is written as its name, which a store can read back.
                if (is_object($value) && !$value instanceof UnitEnum) {
                    $objects[] = '$' . $parameter->getName();
                }
            });
        }

        self::assertSame([], $objects, $class . ' gives these arguments an object as their default');
    }

    #[DataProvider('classes')]
    public function testNoConstructorDefaultIsAConstantOfTheRuntime(string $class): void
    {
        $constructor = (new ReflectionClass($class))->getConstructor();
        $constants = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            // A class constant is the code's own and the same wherever it is read; PHP_SAPI and its like are not.
            if ($parameter->isDefaultValueAvailable() && $parameter->isDefaultValueConstant()
                && !str_contains((string) $parameter->getDefaultValueConstantName(), '::')
            ) {
                $constants[] = '$' . $parameter->getName() . ' = ' . $parameter->getDefaultValueConstantName();
            }
        }

        self::assertSame([], $constants, $class . ' takes these defaults from where it is compiled');
    }

    public function testTheSearchFindsTheModulesClasses(): void
    {
        $classes = array_column(self::classes(), 0);

        self::assertContains(\Kingletas\ReadSplit\Model\Settings::class, $classes);
        self::assertContains(\Kingletas\ReadSplit\Model\Adapter\ReadSplitMysql::class, $classes);
        self::assertContains(\Kingletas\ReadSplit\Model\Request\RequestScope::class, $classes);
    }

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function classes(): array
    {
        $root = (string) realpath(self::MODULE);
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $path = substr($file->getPathname(), strlen($root) + 1);

            // A class file is named for its class; registration.php is a script, and loading it twice is refused.
            if ($file->getExtension() !== 'php' || !ctype_upper($file->getBasename()[0])
                || in_array(strtok($path, '/'), self::NOT_SHIPPED_CODE, true)
            ) {
                continue;
            }

            $class = 'Kingletas\\ReadSplit\\' . str_replace('/', '\\', substr($path, 0, -4));

            if (class_exists($class)) {
                $classes[$class] = [$class];
            }
        }

        return $classes;
    }
}
