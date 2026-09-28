<?php

declare(strict_types=1);

$pestCandidates = [
    getcwd() . '/server_vendor/bin/pest',
    getcwd() . '/vendor/bin/pest',
    getcwd() . '/server_vendor/pestphp/pest/bin/pest',
    getcwd() . '/vendor/pestphp/pest/bin/pest',
];

$pest = null;
foreach ($pestCandidates as $candidate) {
    if (is_file($candidate)) {
        $pest = $candidate;
        break;
    }
}

if ($pest === null) {
    fwrite(STDERR, "Unable to find Pest. Run composer install first.\n");
    exit(1);
}

$serverVendor = getcwd() . '/server_vendor';
$vendor       = getcwd() . '/vendor';
// Pest 1 resolves PHPUnit through vendor even when Composer uses server_vendor.
// Keep this compatibility link local to the test run so it cannot enter releases.
$createdVendorSymlink = false;
if (!file_exists($vendor) && !is_link($vendor) && is_dir($serverVendor)) {
    $createdVendorSymlink = symlink('server_vendor', $vendor);
}

if ($createdVendorSymlink) {
    register_shutdown_function(static function () use ($vendor): void {
        if (is_link($vendor) && readlink($vendor) === 'server_vendor') {
            unlink($vendor);
        }
    });
}

$bootstrap = getcwd() . '/scripts/pest-bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "Unable to find Pest bootstrap at scripts/pest-bootstrap.php.\n");
    exit(1);
}

$args = array_slice($argv, 1);
$hasConfiguration = false;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--configuration')) {
        $hasConfiguration = true;
        break;
    }
}
$configuration = getcwd() . '/phpunit.xml.dist';

if (!$hasConfiguration && is_file($configuration)) {
    array_unshift($args, '--configuration=' . $configuration);
}

$command = array_merge([
    PHP_BINARY,
    '-d',
    'display_errors=1',
    '-d',
    'error_reporting=8191',
    '-d',
    'auto_prepend_file=' . $bootstrap,
    $pest,
], $args);

passthru(implode(' ', array_map('escapeshellarg', $command)), $exitCode);

exit($exitCode);
