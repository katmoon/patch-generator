#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use PatchGenerator\PatchGenerator;

function showHelp() {
    echo "Usage: php patch-generator.php <ticket-id> [options]\n\n";
    echo "Options:\n";
    echo "  -h, --help            Show this help message\n";
    echo "  -v, --patch-version   Specify patch version\n";
    echo "  -g, --git-pr          Specify git PR\n";
    echo "  --with-tests          Ddon't exclude tests\n";
    echo "\nExample:\n";
    echo "  php patch-generator.php ABC-123 -v 2 -g 'https://github.com/org/magento2ce/pull/123 https://github.com/org/magento2ee/pull/456' \n";
    exit(0);
}

// Parse command line arguments
$args = array_slice($GLOBALS['argv'], 1); // Skip script name
$ticketId = null;
$patchVersion = '';
$gitPrs = null;
$withTests = false;

for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if ($arg === '-h' || $arg === '--help') {
        showHelp();
    } elseif ($arg === '-v' || $arg === '--patch-version') {
        $patchVersion = $args[++$i] ?? '';
    } elseif ($arg === '-g' || $arg === '--git-pr') {
        $gitPrs = $args[++$i] ?? null;
    } elseif ($arg === '--with-tests') {
        $withTests = true;
    } elseif ($arg[0] !== '-') {
        $ticketId = $arg;
    }
}

if (!$ticketId) {
    die("Error: Jira ticket ID is required\n");
}

try {
    $patchGenerator = new PatchGenerator([], $ticketId, $patchVersion, $gitPrs, $withTests);
    $patchGenerator->generate();
} catch (\Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}