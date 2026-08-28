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
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Class ExponentialBackoffTest
 *
 * @internal
 * @coversNothing
 */
class ExponentialBackoffTest extends TestCase
{
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
        $this->assertSame(1, getCurrentAttempts($backoff), 'current iteration should be 1 (not yet started)');

        // Reuse the same instance of ExponentialBackoff multiple times.
        for ($i = 0; $i < 2; $i++) {
            $helper->reset();
            $this->assertSame($helper->getValue(), $backoff->run($c), $message);
            $this->assertSame(4, getCurrentAttempts($backoff), 'current iteration should be 4 (after 4 attempts)');
        }
    }

    /**
     * @return array<array{0: Helper, 1: ExponentialBackoff, 2: Closure, 3: string}>
     */
    public function dataSuccessfulRetries(): array
    {
        $helper = (new Helper())->setException(Exception::class);
        return [
            [
                $helper,
                new ExponentialBackoff(new EmptyValueCondition()),
                function () use ($helper) {
                    return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned();
                },
                'fetch a non-empty value after 3 failed attempts where empty values were returned.',
            ],
            [
                $helper,
                new ExponentialBackoff(new ExceptionBasedCondition()),
                function () use ($helper) {
                    return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut();
                },
                'fetch a value after 3 failed attempts where exceptions were thrown out.',
            ],
            [
                $helper,
                new ExponentialBackoff(
                    new class($helper) extends AbstractRetryCondition {
                        /** @var Helper */
                        protected $helper;

                        public function __construct(Helper $helper)
                        {
                            $this->helper = $helper;
                        }

                        public function met($result, ?Exception $e): bool
                        {
                            return $this->helper->getCurrentAttempts() - 1 > $this->helper->getExpectedFailedAttempts();
                        }
                    }
                ),
                function () use ($helper) {
                    return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned();
                },
                'fetch a value after 3 failed attempts through a self-defined retry function.',
            ],
        ];
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
        $backoff->run(
            function () use ($helper) {
                return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned();
            }
        );
        $end = microtime(true);

        self::assertThat(
            $end - $start,
            self::logicalAnd(
                self::greaterThanOrEqual($expectedMin),
                self::lessThanOrEqual($expectedMax)
            ),
            $message
        );
    }

    /**
     * @return array<array{0: ExponentialBackoff, 1: float, 2: float, 3: string}>
     */
    public function dataDelays(): array
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
                    ->setType(ExponentialBackoff::TYPE_SECONDS)
                    ->setMaxAttempts(1),
                0.0,
                0.2,
                'It takes no time to do exponential backoff with maximum # of attempts "1".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))
                    ->setType(ExponentialBackoff::TYPE_SECONDS)
                    ->setMaxAttempts(2),
                1.0,
                1.2,
                'It takes barely over 1 second to do exponential backoff with maximum # of attempts "2".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))
                    ->setType(ExponentialBackoff::TYPE_SECONDS)
                    ->setMaxAttempts(3),
                3.0,
                3.2,
                'It takes barely over 3 seconds to do exponential backoff with maximum # of attempts "3".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))->setType(ExponentialBackoff::TYPE_SECONDS),
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
        self::assertThat(
            ExponentialBackoff::getTimeoutSeconds($iteration, $initialTimeout),
            self::logicalAnd(
                self::greaterThanOrEqual($expectedMin),
                self::lessThanOrEqual($expectedMax)
            ),
            sprintf(
                'For round #%d with initial timeout %d, expected timeout should be between %d and %d.',
                $iteration,
                $initialTimeout,
                $expectedMin,
                $expectedMax
            )
        );
    }

    /**
     * @return array<array<int>>
     */
    public function dataGetTimeoutSeconds(): array
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
        self::assertThat(
            ExponentialBackoff::getTimeoutMicroseconds($iteration, $initialTimeout),
            self::logicalAnd(
                self::greaterThanOrEqual($expectedMin),
                self::lessThanOrEqual($expectedMax)
            ),
            sprintf(
                'For round #%d with initial timeout %d, expected timeout should be between %d and %d.',
                $iteration,
                $initialTimeout,
                $expectedMin,
                $expectedMax
            )
        );
    }

    /**
     * @return array<array<int>>
     */
    public function dataGetTimeoutMicroseconds(): array
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
            [250000 * 1, (250000 * 1) + ((250000 * 1) / 10), 1, 250000],
            [300000 * 2, (300000 * 2) + ((300000 * 2) / 10), 2, 300000],
            [350000 * 4, (350000 * 4) + ((350000 * 4) / 10), 3, 350000],
            [400000 * 8, (400000 * 8) + ((400000 * 8) / 10), 4, 400000],
            [450000 * 16, (450000 * 16) + ((450000 * 16) / 10), 5, 450000],

            // Exactly same input data as above 5 ones, just to help to understand the timeouts better.
            [250000,  275000, 1, 250000],
            [600000,  660000, 2, 300000],
            [1400000, 1540000, 3, 350000],
            [3200000, 3520000, 4, 400000],
            [7200000, 7920000, 5, 450000],
        ];

        // Since we are testing methods with random output, repeat tests on same data for 20 (4 * 5) times.
        $data = array_merge($data, $data, $data, $data);
        $data = array_merge($data, $data, $data, $data, $data);

        return array_merge($simpleData, $data);
    }

    /**
     * The doubling used to be a bit shift, which broke down once enough attempts were configured. Every iteration the
     * shift handled correctly still gets the same timeout it always did.
     *
     * @dataProvider dataTimeoutsMatchTheShiftThatCameBefore
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getTimeoutMicroseconds
     */
    public function testTimeoutsMatchTheShiftThatCameBefore(int $iteration, int $initialTimeout): void
    {
        $expected = $initialTimeout * (1 << ($iteration - 1));

        self::assertThat(
            ExponentialBackoff::getTimeoutMicroseconds($iteration, $initialTimeout),
            self::logicalAnd(
                self::greaterThanOrEqual($expected),
                self::lessThanOrEqual($expected + intdiv($expected, 10))
            ),
            sprintf(
                'For round #%d with initial timeout %d, the timeout should still be %d plus up to a tenth of it.',
                $iteration,
                $initialTimeout,
                $expected
            )
        );
    }

    /**
     * @return array<array<int>>
     */
    public function dataTimeoutsMatchTheShiftThatCameBefore(): array
    {
        // One iteration short of where each initial timeout made the shift overflow, which is as far as the old
        // behaviour is defined and therefore as far as it is worth pinning down.
        $data = [];
        foreach ([1 => 63, 250000 => 45, 1000000 => 43] as $initialTimeout => $lastGoodIteration) {
            for ($iteration = 1; $iteration <= $lastGoodIteration; $iteration++) {
                $data[] = [$iteration, $initialTimeout];
            }
        }

        return $data;
    }

    /**
     * Iterations that used to raise an ArithmeticError, throw a TypeError or silently return 0 -- all of them reachable
     * through setMaxAttempts(), and none of them catchable by run(), which handles exceptions rather than errors.
     *
     * @dataProvider dataTimeoutsSurviveEveryIteration
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getTimeoutMicroseconds
     */
    public function testTimeoutsSurviveEveryIteration(int $expectedMin, int $expectedMax, int $iteration): void
    {
        self::assertThat(
            ExponentialBackoff::getTimeoutMicroseconds($iteration),
            self::logicalAnd(
                self::greaterThanOrEqual($expectedMin),
                self::lessThanOrEqual($expectedMax)
            ),
            sprintf(
                'For round #%d, expected timeout should be between %d and %d.',
                $iteration,
                $expectedMin,
                $expectedMax
            )
        );
    }

    /**
     * @return array<array<int>>
     */
    public function dataTimeoutsSurviveEveryIteration(): array
    {
        // Ten elevenths of PHP_INT_MAX: as high as a timeout can go and still leave room for the tenth of itself that
        // gets added to it.
        $ceiling = intdiv(PHP_INT_MAX, 11) * 10;

        return [
            // Anything below the first iteration is treated as the first one, rather than shifting by a negative
            // number.
            [250000, 275000, PHP_INT_MIN],
            [250000, 275000, -1],
            [250000, 275000, 0],
            [250000, 275000, 1],

            // Where the shift used to overflow to a float that the int return type then rejected.
            [$ceiling, PHP_INT_MAX, 46],
            [$ceiling, PHP_INT_MAX, 47],

            // Where the shift used to exceed the width of an integer, come back as 0 and disable the backoff.
            [$ceiling, PHP_INT_MAX, 64],
            [$ceiling, PHP_INT_MAX, 65],
            [$ceiling, PHP_INT_MAX, 1000],
            [$ceiling, PHP_INT_MAX, PHP_INT_MAX],
        ];
    }

    /**
     * A timeout of nothing has nothing to double, which the doubling loop has to notice: it would otherwise sit there
     * multiplying 0 by 2 until $iteration ran out, about 3400 years' worth for PHP_INT_MAX.
     *
     * @dataProvider dataTimeoutsOfNothingStayNothing
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getTimeoutMicroseconds
     */
    public function testTimeoutsOfNothingStayNothing(int $initialTimeout): void
    {
        self::assertSame(
            0,
            ExponentialBackoff::getTimeoutMicroseconds(PHP_INT_MAX, $initialTimeout),
            "an initial timeout of {$initialTimeout} should come back as 0 rather than spin or throw"
        );
    }

    /**
     * @return array<array<int>>
     */
    public function dataTimeoutsOfNothingStayNothing(): array
    {
        return [[0], [-1], [PHP_INT_MIN]];
    }

    /**
     * @dataProvider dataTimeoutSecondsSurviveEveryInput
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getTimeoutSeconds
     */
    public function testTimeoutSecondsSurviveEveryInput(
        int $expectedMin,
        int $expectedMax,
        int $iteration,
        int $initialTimeout
    ): void {
        self::assertThat(
            ExponentialBackoff::getTimeoutSeconds($iteration, $initialTimeout),
            self::logicalAnd(
                self::greaterThanOrEqual($expectedMin),
                self::lessThanOrEqual($expectedMax)
            ),
            sprintf(
                'For round #%d with initial timeout %d, expected timeout should be between %d and %d.',
                $iteration,
                $initialTimeout,
                $expectedMin,
                $expectedMax
            )
        );
    }

    /**
     * @return array<array<int>>
     */
    public function dataTimeoutSecondsSurviveEveryInput(): array
    {
        return [
            [1, 1, 0, 1],
            [1, 1, -1, 1],
            [50, 55, 1, 50],

            // An initial timeout large enough that turning it into microseconds overflowed on its own, before the
            // doubling had a chance to.
            [0, PHP_INT_MAX, 1, PHP_INT_MAX],
            [0, PHP_INT_MAX, 65, PHP_INT_MAX],
            [0, PHP_INT_MAX, PHP_INT_MAX, 1],
            [0, 0, 1, 0],
            [0, 0, 1, -1],
        ];
    }
}
