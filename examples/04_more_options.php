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
 * Sample code to show what options are available when doing exponential backoff with the package.
 */

declare(strict_types=1);

use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExceptionBasedCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Backoff\Jitter;
use CrowdStar\Tests\Backoff\Helper;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$helper = new Helper();

$backoff = new ExponentialBackoff(new EmptyValueCondition());
$backoff = new ExponentialBackoff(new ExceptionBasedCondition());
$backoff = new ExponentialBackoff(new ExceptionBasedCondition(Exception::class));
$backoff = ExponentialBackoff::when(fn (): bool => !$helper->reachExpectedAttempts());

$backoff
    ->setInitialDelay(1_000_000)  // Wait up to about 1 second before the first retry; jitter decides.
    ->setInitialDelay(ExponentialBackoff::DEFAULT_INITIAL_DELAY)
    ->setMaxAttempts(3)
    ->setMaxAttempts(4)
    ->setMaxDelay(5_000_000)  // Wait at most 5 seconds between two attempts.
    ->setMaxDelay(ExponentialBackoff::DEFAULT_MAX_DELAY)
    ->setMaxElapsedTime(2_000_000)  // Give the whole run 2 seconds, however many attempts fit inside it.
    ->setMaxElapsedTime(null)       // No budget at all. The default.
    ->setJitter(Jitter::None)   // Wait exactly as long as calculated; predictable, but no protection from collisions.
    ->setJitter(Jitter::Equal)  // Wait at least half of the calculated delay, and randomly up to all of it.
    ->setJitter(Jitter::Full)   // Wait anywhere between nothing and the calculated delay. The default.
    // Hand the waiting over to a callback of your own: an event loop this library knows nothing about, or a recorder
    // that makes a retrying test instant.
    ->setSleeper(
        function (int $microseconds): void {
            usleep($microseconds);
        }
    )
    ->setSleeper(null)  // Wait inside the library again. The default.
;

/** @var string $result */
$result = $backoff->run($helper->getValue(...));

echo "result is: {$result}\n";
