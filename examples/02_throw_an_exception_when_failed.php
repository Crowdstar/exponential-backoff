#!/usr/bin/env php
<?php
/**
 * Sample code to return a non-empty value back after 3 failed attempts, where each failed attempt throws an
 * exception out.
 */

use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Backoff\Test;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/helpers/Test.php';

$result = (new ExponentialBackoff(Exception::class))->run(
    function () {
        return Test::getValueAfterThreeExceptions();
    }
);

echo "result is: {$result}\n";
