#!/usr/bin/env php
<?php
/**
 * Copyright 2018 Glu Mobile Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/*
 * Sample code to return some value back, with customized condition class used to determine if a retry is needed or not.
 */

declare(strict_types=1);

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

/** @var string $result */
$result = $backoff->run(
    function () use ($helper) {
        return $helper->getValue();
    }
);
echo "result is: {$result}\n";
