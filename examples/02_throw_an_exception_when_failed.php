#!/usr/bin/env php
<?php
/**
 * Sample code to return some value back after few failed attempts, where each failed attempt throws an exception out.
 */

use CrowdStar\Backoff\ExceptionBasedCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Tests\Backoff\Helper;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// You may omit the first parameter "Exception::class" since it's the default value when not passed in:
//     $backoff = new ExponentialBackoff(new ExceptionBasedCondition());
$backoff = new ExponentialBackoff(new ExceptionBasedCondition(Exception::class));
$helper  = (new Helper())->setException(Exception::class);
$result  = $backoff->run(
    function () use ($helper) {
        return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut();
    }
);

echo "result is: {$result}\n";
