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
use CrowdStar\Backoff\AbstractRetryCondition;
use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExceptionBasedCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Backoff\Jitter;
use CrowdStar\Backoff\Mode;
use Deminy\Counit\TestCase;
use Exception;

/**
 * Class ExponentialBackoffTest
 *
 * @internal
 * @coversNothing
 */
class ExponentialBackoffTest extends TestCase
{
    /**
     * How much shorter than requested a wait is still accepted. Method Swoole\Coroutine::sleep() works with
     * millisecond precision and can resume a coroutine a fraction of a millisecond early, where usleep() never
     * returns too soon.
     */
    protected const TIMER_TOLERANCE = 0.01;

    /**
     * There are two cases covered in this test:
     *     1. Test successful retries with exponential backoff.
     *     2. Test reusing the same instance of ExponentialBackoff multiple times.
     *
     * @dataProvider dataSuccessfulRetries
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testSuccessfulRetries(
        Helper $helper,
        ExponentialBackoff $backoff,
        Closure $c,
        string $message
    ): void {
        // Reuse the same instance of ExponentialBackoff multiple times.
        for ($i = 0; $i < 2; $i++) {
            $helper->reset();
            $this->assertSame($helper->getValue(), $backoff->run($c), $message);
            $this->assertSame(4, $helper->getAttemptsMade(), 'current iteration should be 4 (after 4 attempts)');
        }
    }

    /**
     * Every data set gets a Helper of its own. Counit runs the test cases concurrently, where a shared Helper would
     * have them interfering with each other's attempt counting.
     *
     * @return array<array{0: Helper, 1: ExponentialBackoff, 2: Closure, 3: string}>
     */
    public static function dataSuccessfulRetries(): array
    {
        $data = [];

        $helper = (new Helper())->setException(Exception::class);
        $data[] = [
            $helper,
            (new ExponentialBackoff(new EmptyValueCondition()))->setSleeper(Helper::doNotSleep()),
            $helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...),
            'fetch a non-empty value after 3 failed attempts where empty values were returned.',
        ];

        $helper = (new Helper())->setException(Exception::class);
        $data[] = [
            $helper,
            (new ExponentialBackoff(new ExceptionBasedCondition()))->setSleeper(Helper::doNotSleep()),
            $helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut(...),
            'fetch a value after 3 failed attempts where exceptions were thrown out.',
        ];

        $helper = (new Helper())->setException(Exception::class);
        $data[] = [
            $helper,
            (new ExponentialBackoff(
                new class($helper) extends AbstractRetryCondition {
                    public function __construct(protected readonly Helper $helper)
                    {
                    }

                    public function shouldRetry(mixed $result, ?Exception $e): bool
                    {
                        return $this->helper->getAttemptsMade() <= $this->helper->getExpectedFailedAttempts();
                    }
                }
            ))->setSleeper(Helper::doNotSleep()),
            $helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...),
            'fetch a value after 3 failed attempts through a self-defined retry function.',
        ];

        return $data;
    }

    /**
     * @dataProvider dataDelays
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getDelayMicroseconds()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testDelays(
        ExponentialBackoff $backoff,
        float $expectedMin,
        float $expectedMax,
        string $message
    ): void {
        $helper = new Helper();
        $start  = microtime(true);
        $backoff->run($helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...));
        $elapsed = microtime(true) - $start;

        self::assertGreaterThanOrEqual($expectedMin - self::TIMER_TOLERANCE, $elapsed, $message);
        self::assertLessThanOrEqual($expectedMax, $elapsed, $message);
    }

    /**
     * @return array<array{0: ExponentialBackoff, 1: float, 2: float, 3: string}>
     */
    public static function dataDelays(): array
    {
        // We add 0.2 seconds to the total execution time in each test, assuming that the rest part of the test won't
        // take more than 0.2 seconds to finish.
        //
        // Randomness is switched off throughout: these data sets are about the waits actually happening and lasting as
        // long as they were calculated to, which needs a delay known upfront. See self::testJitteredDelays() for a run
        // with randomness left on.
        return [
            [
                (new ExponentialBackoff(new EmptyValueCondition()))->setJitter(Jitter::None)->setMaxAttempts(1),
                0.00,
                0.20,
                'It takes no time to do exponential backoff with maximum # of attempts "1".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))->setJitter(Jitter::None)->setMaxAttempts(2),
                0.25,
                0.40,
                'It takes barely over 0.25 second to do exponential backoff with maximum # of attempts "2".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))->setJitter(Jitter::None)->setMaxAttempts(3),
                0.75,
                0.95,
                'It takes barely over 0.75 second to do exponential backoff with maximum # of attempts "3".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))->setJitter(Jitter::None),
                1.75,
                1.95,
                'It takes barely over 1.75 seconds to do exponential backoff with a default maximum # of attempts "4".',
            ],

            [
                (new ExponentialBackoff(new EmptyValueCondition()))
                    ->setJitter(Jitter::None)
                    ->setInitialDelay(1_000_000)
                    ->setMaxAttempts(1),
                0.0,
                0.2,
                'It takes no time to do exponential backoff with maximum # of attempts "1".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))
                    ->setJitter(Jitter::None)
                    ->setInitialDelay(1_000_000)
                    ->setMaxAttempts(2),
                1.0,
                1.2,
                'It takes barely over 1 second to do exponential backoff with maximum # of attempts "2".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))
                    ->setJitter(Jitter::None)
                    ->setInitialDelay(1_000_000)
                    ->setMaxAttempts(3),
                3.0,
                3.2,
                'It takes barely over 3 seconds to do exponential backoff with maximum # of attempts "3".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))
                    ->setJitter(Jitter::None)
                    ->setInitialDelay(1_000_000),
                7.0,
                7.2,
                'It takes barely over 7 seconds to do exponential backoff with a default maximum # of attempts "4".',
            ],
        ];
    }

    /**
     * @dataProvider dataGetDelayMicroseconds
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getDelayMicroseconds
     */
    public function testGetDelayMicroseconds(int $expected, int $iteration, int $initialDelay): void
    {
        // These data sets characterise the doubling curve itself, so they opt out of the randomness. See
        // self::testJitter() for what the randomness does to these delays.
        self::assertSame(
            $expected,
            ExponentialBackoff::getDelayMicroseconds($iteration, $initialDelay, jitter: Jitter::None),
            sprintf('For round #%d with initial delay %d.', $iteration, $initialDelay)
        );
    }

    /**
     * @return array<array<int>>
     */
    public static function dataGetDelayMicroseconds(): array
    {
        // Test data to help to understand how delays are calculated, with input data in following order:
        //     ($expected, $iteration, $initialDelay)
        $simpleData = [
            [50 * 1, 1, 50],
            [60 * 2, 2, 60],
            [70 * 4, 3, 70],

            // Exactly same input data as above 3 ones, just to help to understand the delays better.
            [50, 1, 50],
            [120, 2, 60],
            [280, 3, 70],
        ];

        // Test data for simulating actual application delays.
        $data = [
            [250_000 * 1, 1, 250_000],
            [300_000 * 2, 2, 300_000],
            [350_000 * 4, 3, 350_000],
            [400_000 * 8, 4, 400_000],
            [450_000 * 16, 5, 450_000],

            // Exactly same input data as above 5 ones, just to help to understand the delays better.
            [250_000, 1, 250_000],
            [600_000, 2, 300_000],
            [1_400_000, 3, 350_000],
            [3_200_000, 4, 400_000],
            [7_200_000, 5, 450_000],
        ];

        // Since we are testing methods with random output, repeat tests on same data for 20 (4 * 5) times.
        $data = array_merge($data, $data, $data, $data);
        $data = array_merge($data, $data, $data, $data, $data);

        return array_merge($simpleData, $data);
    }

    /**
     * Delays stop doubling once they reach the maximum, and the randomness is added on top of the capped value.
     *
     * The iterations here used to overflow: from #46 on, the delay no longer fit in an integer and the method threw a
     * TypeError, while from #65 on the bit shift returned 0 and backoff switched itself off silently.
     *
     * @dataProvider dataMaxDelay
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getDelayMicroseconds
     */
    public function testMaxDelay(int $iteration): void
    {
        // Round #4 is the first one reaching the cap: 250_000 * 2 ** 3 == 2_000_000.
        self::assertSame(
            ($iteration >= 4) ? 1_000_000 : 250_000 * (2 ** max(0, $iteration - 1)),
            ExponentialBackoff::getDelayMicroseconds($iteration, 250_000, 1_000_000, Jitter::None),
            sprintf('For round #%d the delay should be capped at 1 second.', $iteration)
        );
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function dataMaxDelay(): array
    {
        return [
            'iteration below 1 is treated as the first one'  => [0],
            'first iteration, below the cap'                 => [1],
            'second iteration, below the cap'                => [2],
            'fourth iteration, reaching the cap'             => [4],
            'tenth iteration, capped'                        => [10],
            'iteration 45, the last one that used to work'   => [45],
            'iteration 46, used to throw a TypeError'        => [46],
            'iteration 64, used to throw a TypeError'        => [64],
            'iteration 65, used to return no delay at all'   => [65],
            'iteration 200, way beyond the integer range'    => [200],
        ];
    }

    /**
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getDelayMicroseconds
     */
    public function testDefaultMaxDelay(): void
    {
        self::assertSame(
            ExponentialBackoff::DEFAULT_MAX_DELAY,
            ExponentialBackoff::getDelayMicroseconds(200, jitter: Jitter::None),
            'the default maximum delay applies when none is given'
        );
    }

    /**
     * @covers \CrowdStar\Backoff\ExponentialBackoff::setInitialDelay
     */
    public function testSetInitialDelay(): void
    {
        $backoff = new ExponentialBackoff(new EmptyValueCondition());

        self::assertSame(ExponentialBackoff::DEFAULT_INITIAL_DELAY, $backoff->getInitialDelay());
        self::assertSame(1_000_000, $backoff->setInitialDelay(1_000_000)->getInitialDelay());

        $this->expectException(\CrowdStar\Backoff\Exception::class);
        $this->expectExceptionMessage('initial delay must be at least 1 microsecond');
        $backoff->setInitialDelay(0);
    }

    /**
     * @covers \CrowdStar\Backoff\ExponentialBackoff::setMaxDelay
     */
    public function testSetMaxDelay(): void
    {
        $backoff = new ExponentialBackoff(new EmptyValueCondition());

        self::assertSame(ExponentialBackoff::DEFAULT_MAX_DELAY, $backoff->getMaxDelay());
        self::assertSame(500, $backoff->setMaxDelay(500)->getMaxDelay());

        $this->expectException(\CrowdStar\Backoff\Exception::class);
        $this->expectExceptionMessage('maximum delay must be at least 1 microsecond');
        $backoff->setMaxDelay(0);
    }

    /**
     * A run with many attempts used to sleep for days, or to crash. It now takes about as long as the cap allows.
     *
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run
     */
    public function testManyAttemptsStayWithinTheCap(): void
    {
        $helper  = (new Helper())->setExpectedFailedAttempts(3);
        $backoff = (new ExponentialBackoff(new EmptyValueCondition()))
            ->setMaxAttempts(70)
            ->setMaxDelay(1000)
        ;

        $start = microtime(true);
        self::assertSame(
            $helper->getValue(),
            $backoff->run($helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...))
        );
        self::assertLessThanOrEqual(0.2, microtime(true) - $start, 'three sleeps of at most 1ms each');
    }

    /**
     * The budget is real wall clock, so this one waits for real: an initial delay of 0.1 second doubling to 0.2 does
     * not fit inside 0.25 second, so the run stops after the first wait instead of starting a second.
     *
     * @covers \CrowdStar\Backoff\ExponentialBackoff::affords()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::setMaxElapsedTime()
     */
    public function testMaxElapsedTimeStopsTheRun(): void
    {
        $helper  = (new Helper())->setExpectedFailedAttempts(10);
        $backoff = (new ExponentialBackoff(new EmptyValueCondition()))
            ->setJitter(Jitter::None)
            ->setInitialDelay(100_000)
            ->setMaxElapsedTime(250_000)
            ->setMaxAttempts(10)
        ;

        $start  = microtime(true);
        $result = $backoff->run($helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...));

        self::assertSame('', $result, 'the run gave up and handed back what the last attempt produced');
        self::assertSame(
            2,
            $helper->getAttemptsMade(),
            'one wait of 0.1s fitted the budget, the next one of 0.2s did not'
        );
        self::assertLessThanOrEqual(0.25, microtime(true) - $start, 'the budget was not overrun');
    }

    /**
     * @covers \CrowdStar\Backoff\ExponentialBackoff::affords()
     */
    public function testMaxElapsedTimeLeavesRoomForTheWholeSchedule(): void
    {
        $slept   = [];
        $helper  = new Helper();
        $backoff = (new ExponentialBackoff(new EmptyValueCondition()))
            ->setJitter(Jitter::None)
            ->setMaxElapsedTime(30_000_000)
            ->setSleeper(function (int $microSeconds) use (&$slept): void {
                $slept[] = $microSeconds;
            })
        ;

        $backoff->run($helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...));

        self::assertSame([250_000, 500_000, 1_000_000], $slept, 'a budget it fits inside changes nothing');
    }

    /**
     * @covers \CrowdStar\Backoff\ExponentialBackoff::setMaxElapsedTime()
     */
    public function testMaxElapsedTimeIsUnsetByDefault(): void
    {
        $backoff = new ExponentialBackoff(new EmptyValueCondition());

        self::assertNull($backoff->getMaxElapsedTime());
        self::assertSame(5_000, $backoff->setMaxElapsedTime(5_000)->getMaxElapsedTime());
        self::assertNull($backoff->setMaxElapsedTime(null)->getMaxElapsedTime(), 'and it can be taken back off');

        $this->expectException(\CrowdStar\Backoff\Exception::class);
        $this->expectExceptionMessage('maximum elapsed time must be at least 1 microsecond');
        $backoff->setMaxElapsedTime(0);
    }

    /**
     * A sleeper that records instead of waiting is what lets this assert the delays themselves, rather than how long
     * the whole run took. Nothing else in this suite checks the sequence.
     *
     * @covers \CrowdStar\Backoff\ExponentialBackoff::setSleeper()
     */
    public function testSleeperReceivesEveryDelay(): void
    {
        $slept   = [];
        $helper  = new Helper();
        $backoff = (new ExponentialBackoff(new EmptyValueCondition()))
            ->setJitter(Jitter::None)
            ->setSleeper(function (int $microSeconds) use (&$slept): void {
                $slept[] = $microSeconds;
            })
        ;

        $start = microtime(true);
        $backoff->run($helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...));

        self::assertSame([250_000, 500_000, 1_000_000], $slept, 'the delay doubled on every retry');
        self::assertLessThanOrEqual(0.1, microtime(true) - $start, 'and nothing actually waited');
    }

    /**
     * @covers \CrowdStar\Backoff\ExponentialBackoff::setSleeper()
     */
    public function testSleeperTakesPrecedenceOverTheMode(): void
    {
        $slept   = 0;
        $helper  = (new Helper())->setExpectedFailedAttempts(1);
        $backoff = (new ExponentialBackoff(new EmptyValueCondition(), Mode::Swoole))
            ->setSleeper(function () use (&$slept): void {
                $slept++;
            })
        ;

        self::assertSame(
            $helper->getValue(),
            $backoff->run($helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...)),
            'the run went through without Swoole being asked to sleep'
        );
        self::assertSame(1, $slept);
    }

    /**
     * The mode reported has to be the one a wait would really happen in, and a sleeper is not either of the other two.
     *
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getMode()
     */
    public function testSleeperIsReportedAsTheMode(): void
    {
        $backoff = new ExponentialBackoff(new EmptyValueCondition());
        $without = $backoff->getMode();

        self::assertNotSame(Mode::Sleeper, $without, 'nothing is doing the waiting yet');
        self::assertSame(Mode::Sleeper, $backoff->setSleeper(Helper::doNotSleep())->getMode());
        self::assertSame($without, $backoff->setSleeper(null)->getMode(), 'handing the waiting back restores the mode');
    }

    /**
     * Mode::Sleeper is what getMode() answers, never something to ask for: only setSleeper() brings it about.
     *
     * @covers \CrowdStar\Backoff\ExponentialBackoff::__construct()
     */
    public function testSleeperModeCannotBeAskedFor(): void
    {
        $this->expectException(\CrowdStar\Backoff\Exception::class);
        $this->expectExceptionMessage('mode Sleeper is answered by getMode(), not asked for');
        new ExponentialBackoff(new EmptyValueCondition(), Mode::Sleeper);
    }

    /**
     * @covers \CrowdStar\Backoff\ExponentialBackoff::when()
     */
    public function testSleeperModeCannotBeAskedForThroughWhen(): void
    {
        $this->expectException(\CrowdStar\Backoff\Exception::class);
        ExponentialBackoff::when(fn (): bool => false, true, Mode::Sleeper);
    }

    /**
     * @covers \CrowdStar\Backoff\ExponentialBackoff::setSleeper()
     */
    public function testSleeperCanBeTakenBackOut(): void
    {
        $helper  = (new Helper())->setExpectedFailedAttempts(1);
        $backoff = (new ExponentialBackoff(new EmptyValueCondition()))
            ->setJitter(Jitter::None)
            ->setInitialDelay(200_000)
            ->setSleeper(Helper::doNotSleep())
            ->setSleeper(null)
        ;

        $start = microtime(true);
        $backoff->run($helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...));

        self::assertGreaterThanOrEqual(
            0.2 - self::TIMER_TOLERANCE,
            microtime(true) - $start,
            'the wait happened here again'
        );
    }

    /**
     * One instance handed to two callers at once must not have them counting over each other. The attempt counter
     * used to live on the object, so the inner run() reset it and the outer one gave up after a single attempt.
     *
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run
     */
    public function testNestedRunsCountSeparately(): void
    {
        $backoff = (new ExponentialBackoff(new EmptyValueCondition()))->setJitter(Jitter::None)->setMaxDelay(1000);
        $outer   = (new Helper())->setExpectedFailedAttempts(2);
        $inner   = (new Helper())->setExpectedFailedAttempts(2);

        $result = $backoff->run(
            function () use ($backoff, $outer, $inner): mixed {
                $inner->reset();
                $backoff->run($inner->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...));
                self::assertSame(3, $inner->getAttemptsMade(), 'the inner run made all three of its attempts');

                return $outer->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...)();
            }
        );

        self::assertSame($outer->getValue(), $result, 'the outer run kept retrying until it had a value');
        self::assertSame(3, $outer->getAttemptsMade(), 'and it made all three of its own attempts');
    }

    /**
     * Every mode stays inside its own range, and both randomized ones do vary. 200 draws make it all but impossible
     * for a working implementation to return the same value every time, or to stay inside a narrower band by chance.
     *
     * @dataProvider dataJitter
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getDelayMicroseconds
     */
    public function testJitter(Jitter $jitter, int $expectedMin, int $expectedMax): void
    {
        $delays = [];
        for ($i = 0; $i < 200; $i++) {
            $delays[] = ExponentialBackoff::getDelayMicroseconds(3, 250_000, jitter: $jitter);
        }

        // Round #3 of a 250ms initial delay is one second, well below the default maximum.
        self::assertGreaterThanOrEqual($expectedMin, min($delays), 'no delay fell below the range');
        self::assertLessThanOrEqual($expectedMax, max($delays), 'no delay went above the range');

        // Not 200 distinct values: 200 draws from a range half a million wide collide by chance a few percent of the
        // time, which would make this test flaky rather than strict. Anything near 200 says the same thing.
        $distinct = count(array_unique($delays));
        if ($jitter === Jitter::None) {
            self::assertSame(1, $distinct, 'every delay is the same');
        } else {
            self::assertGreaterThan(150, $distinct, 'the delays differ from one another');
        }
    }

    /**
     * @return array<string, array{0: Jitter, 1: int, 2: int}>
     */
    public static function dataJitter(): array
    {
        return [
            'no randomness: exactly the delay'            => [Jitter::None, 1_000_000, 1_000_000],
            'full randomness: anywhere up to the delay'   => [Jitter::Full, 0, 1_000_000],
            'equal randomness: at least half of it'       => [Jitter::Equal, 500_000, 1_000_000],
        ];
    }

    /**
     * With randomness left on, a run waits for at most what the delays add up to, and generally for less.
     *
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run
     */
    public function testJitteredDelays(): void
    {
        $helper  = new Helper();
        $backoff = new ExponentialBackoff(new EmptyValueCondition());

        self::assertSame(Jitter::Full, $backoff->getJitter(), 'full randomness is the default');

        $start = microtime(true);
        $backoff->run($helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...));

        // Three sleeps of at most 0.25s, 0.5s and 1s. Without randomness this run would take 1.75s every time.
        self::assertLessThanOrEqual(1.95, microtime(true) - $start);
    }
}
