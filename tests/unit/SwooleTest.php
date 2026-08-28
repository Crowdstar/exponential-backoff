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

use Closure;
use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Backoff\Jitter;
use CrowdStar\Backoff\Mode;
use CrowdStar\Backoff\NullCondition;
use Deminy\Counit\TestCase;
use Swoole\Coroutine;
use Swoole\Runtime;

/**
 * Class SwooleTest
 *
 * Covers how exponential backoff behaves in non-blocking mode. Everything but the first test needs extension swoole
 * and is skipped without it, which is why CI runs the suite both with and without the extension loaded.
 *
 * Unlike the other test cases, the ones here manage coroutines themselves, taking into account that binary counit
 * already runs the whole test suite inside a coroutine.
 *
 * @internal
 * @coversNothing
 */
class SwooleTest extends TestCase
{
    /**
     * How much shorter than requested a wait is still accepted; method Swoole\Coroutine::sleep() works with
     * millisecond precision and can resume a coroutine a fraction of a millisecond early.
     */
    protected const TIMER_TOLERANCE = 0.01;

    /**
     * Without a coroutine to run inside, we sleep in blocking mode whether or not extension swoole is loaded.
     *
     * @covers \CrowdStar\Backoff\ExponentialBackoff::__construct()
     */
    public function testBlockingModeOutsideCoroutine(): void
    {
        if (self::insideCoroutine()) {
            self::markTestSkipped('the test suite itself runs inside a coroutine here');
        }

        self::assertSame(Mode::Blocking, (new ExponentialBackoff(new NullCondition()))->getMode());
    }

    /**
     * The instance is built outside any coroutine on purpose: a service tends to be built during bootstrap and used
     * by coroutines afterwards, and the mode used to be settled at construction, which left such an instance blocking
     * forever.
     *
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getMode()
     */
    public function testNonBlockingModeInsideCoroutine(): void
    {
        self::skipWithoutSwoole();

        $backoff = new ExponentialBackoff(new NullCondition());
        $mode    = null;

        self::inCoroutine(
            function () use ($backoff, &$mode): void {
                $mode = $backoff->getMode();
            }
        );

        self::assertSame(Mode::Swoole, $mode, 'exponential backoff detects the coroutine it is used inside');
    }

    /**
     * Forcing non-blocking mode where it cannot work falls back to blocking, rather than letting a Swoole\Error out.
     *
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getMode()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testForcedNonBlockingModeOutsideCoroutine(): void
    {
        self::skipWithoutSwoole();

        if (self::insideCoroutine()) {
            self::markTestSkipped('the test suite itself runs inside a coroutine here');
        }

        $backoff = (new ExponentialBackoff(new EmptyValueCondition(), Mode::Swoole))
            ->setJitter(Jitter::None)
            ->setMaxTimeout(1000)
        ;
        $helper = (new Helper())->setExpectedFailedAttempts(1);

        self::assertSame(Mode::Blocking, $backoff->getMode(), 'there is no coroutine to sleep in');
        self::assertSame(
            $helper->getValue(),
            $backoff->run($helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...)),
            'the run went through, waiting in blocking mode'
        );
    }

    /**
     * Two backoffs waiting inside their own coroutines should overlap instead of queueing up, taking about as long as
     * a single one. Each does one retry, so each waits for one initial timeout of 0.25 second.
     *
     * Swoole's runtime hooks make even a blocking usleep() yield to other coroutines. The hooks are switched off here
     * so that Coroutine::sleep() is the only thing that can make the two waits overlap, which is what makes the upper
     * bound below meaningful.
     *
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::sleep()
     */
    public function testCoroutinesWaitConcurrently(): void
    {
        self::skipWithoutSwoole();

        $results   = [];
        $elapsed   = 0.0;
        $hookFlags = Runtime::getHookFlags();
        Runtime::setHookFlags(0);

        try {
            self::inCoroutine(
                function () use (&$results, &$elapsed): void {
                    $start = microtime(true);
                    $cids  = [];

                    foreach ([0, 1] as $i) {
                        $cids[] = Coroutine::create(
                            function () use (&$results, $i): void {
                                $results[$i] = self::fetchValueWithOneRetry();
                            }
                        );
                    }
                    Coroutine::join($cids);

                    $elapsed = microtime(true) - $start;
                }
            );
        } finally {
            Runtime::setHookFlags($hookFlags);
        }

        // Whichever coroutine wakes up first writes first, so the keys need sorting before being compared.
        ksort($results);

        self::assertSame(['Hello World!', 'Hello World!'], $results, 'both coroutines fetched a value back');
        self::assertGreaterThanOrEqual(
            0.25 - self::TIMER_TOLERANCE,
            $elapsed,
            'each coroutine waited for one initial timeout'
        );
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

        // Randomness is switched off so that the wait lasts a known 0.25 second: the test around this measures how
        // two of those waits overlap, which needs both of them to actually happen.
        return (new ExponentialBackoff(new EmptyValueCondition()))
            ->setJitter(Jitter::None)
            ->setMaxAttempts(2)
            ->run($helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...))
        ;
    }

    /**
     * Run given callback inside a coroutine, reusing the current one when there is one already. Starting a new
     * coroutine scheduler with Coroutine\run() is not allowed while one is running, which is the case under counit.
     */
    protected static function inCoroutine(Closure $callback): void
    {
        if (self::insideCoroutine()) {
            $callback();
        } else {
            Coroutine\run($callback);
        }
    }

    protected static function insideCoroutine(): bool
    {
        return extension_loaded('swoole') && (Coroutine::getCid() !== -1);
    }

    protected static function skipWithoutSwoole(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('extension swoole is not loaded');
        }
    }
}
