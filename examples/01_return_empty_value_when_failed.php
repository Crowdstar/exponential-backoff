#!/usr/bin/env php
<?php
/**
 * Sample code to fetch some non-empty value back after few failed attempts, where each failed attempt returns an empty
 * value back.
 */

use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Tests\Backoff\Helper;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$helper = new Helper();
$result = (new ExponentialBackoff(new EmptyValueCondition()))->run(
    function () use ($helper) {
        return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned();
    }
);

echo "result is: {$result}\n";
