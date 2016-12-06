<?php

$testCase = $argv[1] ?? NULL;
switch ($testCase) {
    case 'server':
    case 'client':
        require_once __DIR__ . DIRECTORY_SEPARATOR . $testCase . '.php.inc';
        break;
    default:
        throw new \Exception('Unknown test case: ' . $testCase);
}
