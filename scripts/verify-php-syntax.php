<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    $root . DIRECTORY_SEPARATOR . 'safe-migrate.php',
    $root . DIRECTORY_SEPARATOR . 'uninstall.php',
    $root . DIRECTORY_SEPARATOR . 'src',
    $root . DIRECTORY_SEPARATOR . 'tests',
];

$files = [];

foreach ($paths as $path) {
    if (is_file($path)) {
        $files[] = $path;
        continue;
    }

    if (! is_dir($path)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if (! $item->isFile() || strtolower($item->getExtension()) !== 'php') {
            continue;
        }

        $files[] = $item->getPathname();
    }
}

sort($files);

$failed = false;

foreach ($files as $file) {
    $output = [];
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file);
    exec($command, $output, $exitCode);

    if ($exitCode === 0) {
        continue;
    }

    $failed = true;
    fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
    fwrite(STDERR, 'Syntax check failed for ' . $file . PHP_EOL);
}

if ($failed) {
    exit(1);
}

fwrite(STDOUT, 'Syntax OK for ' . count($files) . ' PHP files.' . PHP_EOL);
