#!/usr/bin/env php
<?php
/**
 * Sample code to show how to define customized options when running exponential backoff.
 */

use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Backoff\Test;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/helpers/Test.php';

$backoff = new ExponentialBackoff();
$backoff
    ->reset()
    ->setType(ExponentialBackoff::TYPE_SECONDS)
    ->setType(ExponentialBackoff::TYPE_MICROSECONDS)
    ->setMaxAttempts(3)
    ->setMaxAttempts(4)
    ->setRetryCondition(
        function ($result, ?Exception $e): bool {
            return Test::lessThan3();
        }
    )
    ->setRetryCondition(Exception::class)
    ->setRetryCondition(null);

$result = $backoff->run(
    function () {
        return Test::getValueAfterThreeEmptyReturnValues();
    }
);

echo "result is: {$result}\n";
