#!/usr/bin/env php
<?php

require_once __DIR__ . '/vendor/autoload.php';

use PatchGenerator\PatchGenerator;

// Parse command line arguments
$args = array_slice($GLOBALS['argv'], 1); // Skip script name
$ticketId = null;
$patchVersion = '';
$gitPrs = null;

for ($i = 0; $i < count($args); $i++) {
    $arg = $args[$i];
    if ($arg === '-v' || $arg === '--patch-version') {
        $patchVersion = $args[++$i] ?? '';
    } elseif ($arg === '-g' || $arg === '--git-pr') {
        $gitPrs = $args[++$i] ?? null;
    } elseif ($arg[0] !== '-') {
        $ticketId = $arg;
    }
}

if (!$ticketId) {
    die("Error: Jira ticket ID is required\n");
}

try {
    $patchGenerator = new PatchGenerator([], $ticketId, $patchVersion, $gitPrs);
    $patchGenerator->generate();
} catch (\Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
}