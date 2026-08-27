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
use CrowdStar\Backoff\Type;
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
            $this->assertSame(4, getCurrentAttempts($backoff), 'current iteration should be 4 (after 4 attempts)');
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
            new ExponentialBackoff(new EmptyValueCondition()),
            $helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...),
            'fetch a non-empty value after 3 failed attempts where empty values were returned.',
        ];

        $helper = (new Helper())->setException(Exception::class);
        $data[] = [
            $helper,
            new ExponentialBackoff(new ExceptionBasedCondition()),
            $helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut(...),
            'fetch a value after 3 failed attempts where exceptions were thrown out.',
        ];

        $helper = (new Helper())->setException(Exception::class);
        $data[] = [
            $helper,
            new ExponentialBackoff(
                new class($helper) extends AbstractRetryCondition {
                    public function __construct(protected readonly Helper $helper)
                    {
                    }

                    public function met(mixed $result, ?Exception $e): bool
                    {
                        return $this->helper->getCurrentAttempts() - 1 > $this->helper->getExpectedFailedAttempts();
                    }
                }
            ),
            $helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...),
            'fetch a value after 3 failed attempts through a self-defined retry function.',
        ];

        return $data;
    }

    /**
     * @dataProvider dataDelays
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getTimeoutMicroseconds()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getTimeoutSeconds()
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
        return [
            [
                (new ExponentialBackoff(new EmptyValueCondition()))->setMaxAttempts(1),
                0.00,
                0.20,
                'It takes no time to do exponential backoff with maximum # of attempts "1".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))->setMaxAttempts(2),
                0.25,
                0.40,
                'It takes barely over 0.25 second to do exponential backoff with maximum # of attempts "2".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))->setMaxAttempts(3),
                0.75,
                0.95,
                'It takes barely over 0.75 second to do exponential backoff with maximum # of attempts "3".',
            ],
            [
                new ExponentialBackoff(new EmptyValueCondition()),
                1.75,
                1.95,
                'It takes barely over 1.75 seconds to do exponential backoff with a default maximum # of attempts "4".',
            ],

            [
                (new ExponentialBackoff(new EmptyValueCondition()))
                    ->setType(Type::Seconds)
                    ->setMaxAttempts(1),
                0.0,
                0.2,
                'It takes no time to do exponential backoff with maximum # of attempts "1".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))
                    ->setType(Type::Seconds)
                    ->setMaxAttempts(2),
                1.0,
                1.2,
                'It takes barely over 1 second to do exponential backoff with maximum # of attempts "2".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))
                    ->setType(Type::Seconds)
                    ->setMaxAttempts(3),
                3.0,
                3.2,
                'It takes barely over 3 seconds to do exponential backoff with maximum # of attempts "3".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))->setType(Type::Seconds),
                7.0,
                7.2,
                'It takes barely over 7 seconds to do exponential backoff with a default maximum # of attempts "4".',
            ],
        ];
    }

    /**
     * @dataProvider dataGetTimeoutSeconds
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getTimeoutSeconds
     */
    public function testGetTimeoutSeconds(int $expectedMin, int $expectedMax, int $iteration, int $initialTimeout): void
    {
        // These data sets characterise the doubling curve, so they opt out of the maximum timeout.
        $timeout = ExponentialBackoff::getTimeoutSeconds($iteration, $initialTimeout, 3600);
        $message = sprintf(
            'For round #%d with initial timeout %d, expected timeout should be between %d and %d.',
            $iteration,
            $initialTimeout,
            $expectedMin,
            $expectedMax
        );

        self::assertGreaterThanOrEqual($expectedMin, $timeout, $message);
        self::assertLessThanOrEqual($expectedMax, $timeout, $message);
    }

    /**
     * @return array<array<int>>
     */
    public static function dataGetTimeoutSeconds(): array
    {
        // Test data to help to understand how timeouts are calculated, with input data in following order:
        //     ($expectedMin, $expectedMax, $iteration, $initialTimeout)
        $data = [
            [50 * 1, (50 * 1) + ((50 * 1) / 10), 1, 50],
            [60 * 2, (60 * 2) + ((60 * 2) / 10), 2, 60],
            [70 * 4, (70 * 4) + ((70 * 4) / 10), 3, 70],

            // Exactly same input data as above 3 ones, just to help to understand the timeouts better.
            [50,  55, 1, 50],
            [120, 132, 2, 60],
            [280, 308, 3, 70],
        ];

        // Since we are testing methods with random output, repeat tests on same data for 20 (4 * 5) times.
        $data = array_merge($data, $data, $data, $data);
        return array_merge($data, $data, $data, $data, $data);
    }

    /**
     * @dataProvider dataGetTimeoutMicroseconds
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getTimeoutMicroseconds
     */
    public function testGetTimeoutMicroseconds(
        int $expectedMin,
        int $expectedMax,
        int $iteration,
        int $initialTimeout
    ): void {
        $timeout = ExponentialBackoff::getTimeoutMicroseconds($iteration, $initialTimeout);
        $message = sprintf(
            'For round #%d with initial timeout %d, expected timeout should be between %d and %d.',
            $iteration,
            $initialTimeout,
            $expectedMin,
            $expectedMax
        );

        self::assertGreaterThanOrEqual($expectedMin, $timeout, $message);
        self::assertLessThanOrEqual($expectedMax, $timeout, $message);
    }

    /**
     * @return array<array<int>>
     */
    public static function dataGetTimeoutMicroseconds(): array
    {
        // Test data to help to understand how timeouts are calculated, with input data in following order:
        //     ($expectedMin, $expectedMax, $iteration, $initialTimeout)
        $simpleData = [
            [50 * 1, (50 * 1) + ((50 * 1) / 10), 1, 50],
            [60 * 2, (60 * 2) + ((60 * 2) / 10), 2, 60],
            [70 * 4, (70 * 4) + ((70 * 4) / 10), 3, 70],

            // Exactly same input data as above 3 ones, just to help to understand the timeouts better.
            [50,  55, 1, 50],
            [120, 132, 2, 60],
            [280, 308, 3, 70],
        ];

        // Test data for simulating actual application timeouts.
        $data = [
            [250_000 * 1, (250_000 * 1) + ((250_000 * 1) / 10), 1, 250_000],
            [300_000 * 2, (300_000 * 2) + ((300_000 * 2) / 10), 2, 300_000],
            [350_000 * 4, (350_000 * 4) + ((350_000 * 4) / 10), 3, 350_000],
            [400_000 * 8, (400_000 * 8) + ((400_000 * 8) / 10), 4, 400_000],
            [450_000 * 16, (450_000 * 16) + ((450_000 * 16) / 10), 5, 450_000],

            // Exactly same input data as above 5 ones, just to help to understand the timeouts better.
            [250_000,  275_000, 1, 250_000],
            [600_000,  660_000, 2, 300_000],
            [1_400_000, 1_540_000, 3, 350_000],
            [3_200_000, 3_520_000, 4, 400_000],
            [7_200_000, 7_920_000, 5, 450_000],
        ];

        // Since we are testing methods with random output, repeat tests on same data for 20 (4 * 5) times.
        $data = array_merge($data, $data, $data, $data);
        $data = array_merge($data, $data, $data, $data, $data);

        return array_merge($simpleData, $data);
    }

    /**
     * Timeouts stop doubling once they reach the maximum, and the randomness is added on top of the capped value.
     *
     * The iterations here used to overflow: from #46 on, the timeout no longer fit in an integer and the method threw
     * a TypeError, while from #65 on the bit shift returned 0 and backoff switched itself off silently.
     *
     * @dataProvider dataMaxTimeout
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getTimeoutMicroseconds
     */
    public function testMaxTimeout(int $iteration): void
    {
        $timeout = ExponentialBackoff::getTimeoutMicroseconds($iteration, 250_000, 1_000_000);
        $message = sprintf('For round #%d the timeout should be capped at 1 second plus randomness.', $iteration);

        // Round #4 is the first one reaching the cap: 250_000 * 2 ** 3 == 2_000_000.
        self::assertGreaterThanOrEqual(($iteration >= 4) ? 1_000_000 : 250_000, $timeout, $message);
        self::assertLessThanOrEqual(1_100_000, $timeout, $message);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function dataMaxTimeout(): array
    {
        return [
            'iteration below 1 is treated as the first one' => [0],
            'first iteration, below the cap'                => [1],
            'second iteration, below the cap'               => [2],
            'fourth iteration, reaching the cap'            => [4],
            'tenth iteration, capped'                       => [10],
            'iteration 45, the last one that used to work'  => [45],
            'iteration 46, used to throw a TypeError'       => [46],
            'iteration 64, used to throw a TypeError'       => [64],
            'iteration 65, used to return no timeout at all' => [65],
            'iteration 200, way beyond the integer range'   => [200],
        ];
    }

    /**
     * The cap applies to the timeout before the randomness is added, so a capped timeout still varies by up to 10%.
     *
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getTimeoutSeconds
     */
    public function testMaxTimeoutInSeconds(): void
    {
        $timeouts = [ExponentialBackoff::getTimeoutSeconds(200, 1, 30), ExponentialBackoff::getTimeoutSeconds(200)];

        foreach ($timeouts as $timeout) {
            self::assertGreaterThanOrEqual(30, $timeout, 'the timeout grew all the way to the cap');
            self::assertLessThanOrEqual(33, $timeout, 'the timeout stayed within the cap plus 10% of randomness');
        }
    }

    /**
     * @covers \CrowdStar\Backoff\ExponentialBackoff::setMaxTimeout
     */
    public function testSetMaxTimeout(): void
    {
        $backoff = new ExponentialBackoff(new EmptyValueCondition());

        self::assertSame(ExponentialBackoff::DEFAULT_MAX_TIMEOUT, $backoff->getMaxTimeout());
        self::assertSame(500, $backoff->setMaxTimeout(500)->getMaxTimeout());

        $this->expectException(\CrowdStar\Backoff\Exception::class);
        $this->expectExceptionMessage('maximum timeout must be at least 1 microsecond');
        $backoff->setMaxTimeout(0);
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
            ->setMaxTimeout(1000)
        ;

        $start = microtime(true);
        self::assertSame(
            $helper->getValue(),
            $backoff->run($helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...))
        );
        self::assertLessThanOrEqual(0.2, microtime(true) - $start, 'three sleeps of at most 1.1ms each');
    }
}
