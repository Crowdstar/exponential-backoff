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

declare(strict_types=1);

namespace CrowdStar\Tests\Backoff;

use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Backoff\NullCondition;
use CrowdStar\Backoff\Sapi;
use CrowdStar\Reflection\Reflection;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Runtime;

/**
 * Class SwooleTest
 *
 * Covers how exponential backoff behaves in non-blocking mode. Everything but the first test needs extension swoole
 * and is skipped without it, which is why CI runs the suite both with and without the extension loaded.
 *
 * @internal
 * @coversNothing
 */
class SwooleTest extends TestCase
{
    /**
     * Outside a Swoole coroutine we sleep in blocking mode, whether or not extension swoole is loaded.
     *
     * @covers \CrowdStar\Backoff\ExponentialBackoff::__construct()
     */
    public function testBlockingModeOutsideCoroutine(): void
    {
        self::assertSame(Sapi::Default, self::getSapi(new ExponentialBackoff(new NullCondition())));
    }

    /**
     * @covers \CrowdStar\Backoff\ExponentialBackoff::__construct()
     */
    public function testNonBlockingModeInsideCoroutine(): void
    {
        self::skipWithoutSwoole();

        $sapi = null;
        Coroutine\run(
            function () use (&$sapi): void {
                $sapi = self::getSapi(new ExponentialBackoff(new NullCondition()));
            }
        );

        self::assertSame(Sapi::Swoole, $sapi, 'exponential backoff detects the coroutine it runs inside');
    }

    /**
     * Two backoffs waiting inside their own coroutines should overlap instead of queueing up, taking about as long as
     * a single one. Each does one retry, so each waits for one initial timeout of 0.25 second plus up to 10% jitter.
     *
     * Method Coroutine\run() turns on Swoole's runtime hooks, which make even a blocking usleep() yield to other
     * coroutines. The hooks are switched off here so that Coroutine::sleep() is the only thing that can make the two
     * waits overlap, which is what makes the upper bound below meaningful.
     *
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::sleep()
     */
    public function testCoroutinesWaitConcurrently(): void
    {
        self::skipWithoutSwoole();

        $hookFlags = Runtime::getHookFlags();
        Runtime::setHookFlags(0);

        try {
            $results = [];
            $start   = microtime(true);
            // Method Coroutine\run() returns only after every coroutine created inside it has finished.
            Coroutine\run(
                function () use (&$results): void {
                    foreach ([0, 1] as $i) {
                        Coroutine::create(
                            function () use (&$results, $i): void {
                                $results[$i] = self::fetchValueWithOneRetry();
                            }
                        );
                    }
                }
            );
            $elapsed = microtime(true) - $start;
        } finally {
            Runtime::setHookFlags($hookFlags);
        }

        self::assertSame(['Hello World!', 'Hello World!'], $results, 'both coroutines fetched a value back');
        self::assertGreaterThanOrEqual(0.25, $elapsed, 'each coroutine waited for one initial timeout');
        self::assertLessThanOrEqual(
            0.45,
            $elapsed,
            'the two waits overlapped; sleeping in blocking mode takes over 0.5 second here'
        );
    }

    /**
     * Fetch a value back after a single failed attempt, waiting for one initial timeout of 0.25 second in between.
     */
    protected static function fetchValueWithOneRetry(): mixed
    {
        $helper = (new Helper())->setExpectedFailedAttempts(1);

        return (new ExponentialBackoff(new EmptyValueCondition()))
            ->setMaxAttempts(2)
            ->run($helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...))
        ;
    }

    protected static function getSapi(ExponentialBackoff $backoff): mixed
    {
        return Reflection::getProperty($backoff, 'sapi');
    }

    protected static function skipWithoutSwoole(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('extension swoole is not loaded');
        }
    }
}
