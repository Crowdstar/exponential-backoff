#!/usr/bin/env php
<?php
/**
 * Sample code to return a non-empty value back after 3 failed attempts, where each failed attempt returns FALSE while
 * calling the customized function.
 */

use CrowdStar\Backoff\AbstractRetryCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Tests\Backoff\Helper;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$helper    = new Helper();
$condition = new class extends AbstractRetryCondition {
    public function met($result, ?Exception $e): bool
    {
        return $GLOBALS['helper']->reachExpectedAttempts();
    }
};

$backoff = new ExponentialBackoff($condition);

$result = $backoff->run(
    function () use ($helper) {
        return $helper->getValue();
    }
);

echo "result is: {$result}\n";
