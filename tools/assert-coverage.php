<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

$arguments = $_SERVER['argv'] ?? null;

if (!\is_array($arguments) || \count($arguments) !== 2 || !\is_string($arguments[1] ?? null)) {
    \fwrite(STDERR, "Usage: php tools/assert-coverage.php <coverage.xml>\n");
    exit(2);
}

$coveragePath = $arguments[1];
$coverage = @\simplexml_load_file($coveragePath);

if ($coverage === false || !isset($coverage->project->metrics)) {
    \fwrite(STDERR, "Unable to read Clover project metrics from {$coveragePath}.\n");
    exit(2);
}

$attributes = $coverage->project->metrics->attributes();
$classes = (int) ($attributes['classes'] ?? 0);

if ($classes === 0) {
    \fwrite(STDERR, "Coverage report does not contain executable classes.\n");
    exit(1);
}

$coveredClasses = 0;

foreach ($coverage->project->package as $package) {
    foreach ($package->file as $file) {
        foreach ($file->class as $class) {
            $classMetrics = $class->metrics->attributes();
            $methods = (int) ($classMetrics['methods'] ?? 0);
            $statements = (int) ($classMetrics['statements'] ?? 0);

            if ($methods === 0 && $statements === 0) {
                continue;
            }

            if (
                (int) ($classMetrics['coveredmethods'] ?? 0) === $methods
                && (int) ($classMetrics['coveredstatements'] ?? 0) === $statements
            ) {
                ++$coveredClasses;
            }
        }
    }
}

if ($coveredClasses !== $classes) {
    \fwrite(STDERR, \sprintf(
        "Coverage requirement failed for classes: %d/%d covered.\n",
        $coveredClasses,
        $classes,
    ));
    exit(1);
}

$requirements = [
    'methods' => 'coveredmethods',
    'statements' => 'coveredstatements',
];

foreach ($requirements as $totalName => $coveredName) {
    $total = (int) ($attributes[$totalName] ?? 0);
    $covered = (int) ($attributes[$coveredName] ?? 0);

    if ($total === 0 || $covered !== $total) {
        \fwrite(STDERR, \sprintf(
            "Coverage requirement failed for %s: %d/%d covered.\n",
            $totalName,
            $covered,
            $total,
        ));
        exit(1);
    }
}

echo "Coverage requirement satisfied: 100% of classes, methods, and lines.\n";
