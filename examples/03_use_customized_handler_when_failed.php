#!/usr/bin/env php
<?php
/**
 * Sample code to return a non-empty value back after 3 failed attempts, where each failed attempt returns FALSE while
 * calling the customized function.
 */

use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Backoff\Test;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/helpers/Test.php';

// This object has a customized function to determine if a retry is needed or not. The customized function needs to
// return a boolean value back.
$backoff = new ExponentialBackoff(
    function ($result, ?Exception $e): bool {
        return Test::lessThan3();
    }
);

$result = $backoff->run(
    function () {
        return Test::getValue();
    }
);

echo "result is: {$result}\n";
