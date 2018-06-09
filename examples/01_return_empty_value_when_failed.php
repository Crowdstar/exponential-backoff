#!/usr/bin/env php
<?php
/**
 * Sample code to return a non-empty value back after 3 failed attempts, where each failed attempt returns an empty
 * value back.
 */

use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Tests\Backoff\Helper;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$helper = new Helper();
$result = (new ExponentialBackoff(new EmptyValueCondition()))->run(
    function () use ($helper) {
        return $helper->getValueAfterThreeEmptyReturnValues();
    }
);

echo "result is: {$result}\n";
