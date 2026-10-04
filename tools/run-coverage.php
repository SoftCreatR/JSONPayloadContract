<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

$command = [PHP_BINARY];

if (!\extension_loaded('xdebug')) {
    $extensionDirectory = \ini_get('extension_dir');
    $windowsCandidates = \is_string($extensionDirectory)
        ? (\glob($extensionDirectory . DIRECTORY_SEPARATOR . 'php_xdebug*.dll') ?: [])
        : [];
    $candidates = \is_string($extensionDirectory)
        ? [
            $extensionDirectory . DIRECTORY_SEPARATOR . 'xdebug.so',
            ...$windowsCandidates,
        ]
        : [];
    $xdebug = \array_find($candidates, static fn(string $candidate): bool => \is_file($candidate));

    if ($xdebug === null) {
        \fwrite(STDERR, "Xdebug is required to verify coverage.\n");
        exit(2);
    }

    $command[] = '-d';
    $command[] = 'zend_extension=' . $xdebug;
}

\putenv('XDEBUG_MODE=coverage');
$command = [
    ...$command,
    __DIR__ . '/../vendor/bin/phpunit',
    '--coverage-clover=' . __DIR__ . '/../coverage.xml',
    '--coverage-text',
];
$process = \proc_open($command, [STDIN, STDOUT, STDERR], $pipes, \dirname(__DIR__));

if (!\is_resource($process)) {
    \fwrite(STDERR, "Unable to start PHPUnit.\n");
    exit(2);
}

exit(\proc_close($process));
