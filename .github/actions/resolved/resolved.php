#!/usr/bin/env php
<?php
/**
 * Prints the toolchain versions a CI job resolved, so a red run says what it ran against.
 *
 * @copyright Copyright (c) the Kingletas modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */
declare(strict_types=1);

/*
 * Why this exists. Nothing here fixes a Magento version. Each job resolves from
 * scratch with no lock, because this module declares a range and pinning one
 * version would test less than it claims.
 *
 * The cost of the range is that the same commit can pass today and fail tomorrow
 * with no code change. This does not remove that. It removes the worst part of
 * it, which is not knowing what a failing run was run against.
 *
 * Why it compares rather than lists. A version number is not a signal until it
 * sits beside the range it violates, so the declared constraint is shown next to
 * the resolved version and a line naming what is outside prints only when
 * something is. Nothing here refuses to run.
 *
 * This is the monorepo's `bin/resolved` reading one module instead of the whole
 * tree, because a published module cannot reach that script. The two read the
 * same package list, and `make resolved-actions` fails when they stop agreeing.
 *
 * Usage:
 *   resolved.php [VENDOR_DIR]   the versions in VENDOR_DIR, default `vendor`
 *
 * Exit status is 0 when it could read the tree and 1 when it could not. The
 * action that calls it discards a failure: a missing version line is a report
 * that did not arrive, never a reason to turn a run red.
 */

/** Packages whose version can change a verdict. Anything else is noise here. */
const DECIDING = [
    'magento/product-community-edition' => 'Magento',
    'magento/framework'                 => 'Magento framework',
    'mage-os/product-community-edition' => 'Mage-OS',
    'mage-os/framework'                 => 'Mage-OS framework',
    'magento/magento-coding-standard'   => 'Coding standard',
    'phpunit/phpunit'                   => 'PHPUnit',
    'phpstan/phpstan'                   => 'PHPStan',
    'phpmd/phpmd'                       => 'PHPMD',
];

exit(main($argv));

function main(array $argv): int
{
    $vendor = rtrim($argv[1] ?? 'vendor', '/');
    $installed = $vendor . '/composer/installed.json';

    if (!is_readable($installed)) {
        fwrite(STDERR, "resolved: no composer/installed.json under {$vendor}\n");
        return 1;
    }

    $versions = readVersions($installed);
    if ($versions === null) {
        fwrite(STDERR, "resolved: {$installed} is not readable as JSON\n");
        return 1;
    }

    // The semver library lives in the tree being read, not beside this script,
    // so a tree without one makes every row read `not judged` rather than blank.
    $autoload = $vendor . '/autoload.php';
    if (is_readable($autoload)) {
        require_once $autoload;
    }

    $declared = declaredConstraints('composer.json');
    $verdicts = [];
    foreach (DECIDING as $name => $label) {
        if (isset($versions[$label])) {
            $verdicts[$label] = verdictFor($versions[$label], $declared[$name] ?? []);
        }
    }

    echo renderMarkdown($versions, $declared, $verdicts);

    return 0;
}

/** The manifest as committed, or null when git cannot answer for it. */
function manifestFromGit(string $manifest): ?string
{
    $command = 'git show HEAD:' . escapeshellarg($manifest) . ' 2>/dev/null';
    $raw = @shell_exec($command);
    return is_string($raw) && trim($raw) !== '' ? $raw : null;
}

/**
 * Every constraint this manifest declares for the deciding packages.
 *
 * @return array<string, list<string>> Package name to the constraints asking for it.
 */
function declaredConstraints(string $manifest): array
{
    // Read the committed manifest, not the working copy. A job that installs
    // something runs `composer require`, which writes its own constraints into
    // this file before anything reports on it.
    $raw = manifestFromGit($manifest);
    if ($raw === null) {
        if (!is_readable($manifest)) {
            return [];
        }
        $raw = file_get_contents($manifest);
    }
    if ($raw === false) {
        return [];
    }

    $document = json_decode($raw, true);
    if (!is_array($document)) {
        return [];
    }

    $declared = [];
    foreach (['require', 'require-dev'] as $section) {
        foreach ($document[$section] ?? [] as $name => $constraint) {
            if (!isset(DECIDING[$name]) || !is_string($constraint)) {
                continue;
            }
            if (!in_array($constraint, $declared[$name] ?? [], true)) {
                $declared[$name][] = $constraint;
            }
        }
    }
    return $declared;
}

/**
 * Whether a resolved version satisfies any constraint the manifest declares,
 * or null when nothing declares it and when no semver library can decide it.
 */
function verdictFor(string $version, array $constraints): ?bool
{
    if ($constraints === [] || !class_exists(\Composer\Semver\Semver::class)) {
        return null;
    }
    foreach ($constraints as $constraint) {
        try {
            if (\Composer\Semver\Semver::satisfies($version, $constraint)) {
                return true;
            }
        } catch (\Throwable) {
            return null;
        }
    }
    return false;
}

/** The constraints for a label, joined for display, or an empty string. */
function declaredFor(string $label, array $declared): string
{
    foreach (DECIDING as $name => $candidate) {
        if ($candidate === $label) {
            return implode(', ', $declared[$name] ?? []);
        }
    }
    return '';
}

/** The names whose resolved version satisfies nothing this manifest declares. */
function outside(array $verdicts): array
{
    return array_keys(array_filter($verdicts, static fn(?bool $v): bool => $v === false));
}

/**
 * The marker for one row: `outside` when the version violates the declared range,
 * `not judged` when a range is declared and nothing could decide it, empty otherwise.
 */
function markFor(?bool $verdict, string $range): string
{
    if ($range === '' || $verdict === true) {
        return '';
    }
    return $verdict === false ? 'outside' : 'not judged';
}

/** Printed only when something is outside: a line that always prints is not read. */
function warning(array $out): string
{
    $names = implode(', ', $out);
    return "\nOutside what this repository declares: {$names}.\n"
        . "A verdict from a tool outside its declared range is not evidence in either\n"
        . "direction: a pass is a weaker claim wearing the same word, and a failure may\n"
        . "be a rule the declared major does not have. Nothing here refuses to run.\n";
}

/**
 * @return array<string, string>|null Label to version, in DECIDING's order, PHP first.
 */
function readVersions(string $installed): ?array
{
    $raw = file_get_contents($installed);
    if ($raw === false) {
        return null;
    }
    $document = json_decode($raw, true);
    if (!is_array($document)) {
        return null;
    }
    // Composer 2 nests the list under `packages`; Composer 1 was the bare array.
    $packages = $document['packages'] ?? $document;

    $found = [];
    foreach ($packages as $package) {
        $name = $package['name'] ?? null;
        if (is_string($name) && isset(DECIDING[$name])) {
            $found[$name] = (string)($package['version'] ?? 'unknown');
        }
    }

    // The interpreter running this script is the one the job runs its tools with,
    // so here it needs no qualifying label the way the monorepo's reader does.
    $versions = ['PHP' => PHP_VERSION];
    foreach (DECIDING as $name => $label) {
        if (isset($found[$name])) {
            $versions[$label] = $found[$name];
        }
    }
    return $versions;
}

function renderMarkdown(array $versions, array $declared, array $verdicts): string
{
    $out = "### Resolved for this run\n\n| | Version | Declared | |\n|---|---|---|---|\n";
    foreach ($versions as $label => $version) {
        $range = declaredFor($label, $declared);
        $shown = $range === '' ? '' : '`' . str_replace('|', '\\|', $range) . '`';
        $mark = markFor($verdicts[$label] ?? null, $range);
        $mark = $mark === '' ? '' : "**{$mark}**";
        $out .= "| {$label} | `{$version}` | {$shown} | {$mark} |\n";
    }
    $out .= "\nNothing here pins a version: this module declares a range and the job "
        . "resolves inside it. This is what this run used.\n";
    $failing = outside($verdicts);
    return $failing === [] ? $out : $out . warning($failing);
}
