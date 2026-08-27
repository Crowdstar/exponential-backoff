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

use ArrayAccess;
use BadFunctionCallException;
use BadMethodCallException;
use CrowdStar\Backoff\ExceptionBasedCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use Deminy\Counit\TestCase;
use Error;
use Exception;
use LogicException;
use PHPUnit\Framework\ExpectationFailedException;
use RuntimeException;
use Throwable;
use TypeError;

/**
 * Class ExceptionBasedCondition
 *
 * @internal
 * @coversNothing
 */
class ExceptionBasedConditionTest extends TestCase
{
    /**
     * @dataProvider dataSuccessfulRetries
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::shouldRetry()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testSuccessfulRetries(string $exceptionToCatch, string $exceptionToThrow, string $message): void
    {
        $maxAttempts = 3; // Three attempts are enough for verification purpose.
        foreach ([0, 1, 2] as $expectedFailedAttempts) {
            $helper  = (new Helper())
                ->setException($exceptionToThrow)
                ->setExpectedFailedAttempts($expectedFailedAttempts)
            ;
            $backoff = (new ExponentialBackoff(new ExceptionBasedCondition($exceptionToCatch)))
                ->setSleeper(Helper::doNotSleep())
                ->setMaxAttempts($maxAttempts)
            ;

            self::assertSame(
                $helper->getValue(),
                $backoff->run($helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut(...)),
                $message
            );
            self::assertSame(
                $expectedFailedAttempts + 1,
                $helper->getAttemptsMade(),
                'total # of attempts made should be one time more than failed attempts'
            );
        }
    }

    /**
     * @return array<array<string>>
     */
    public static function dataSuccessfulRetries(): array
    {
        // @see http://php.net/manual/en/spl.exceptions.php SPL exceptions
        // Exception > LogicException > BadFunctionCallException > BadMethodCallException
        return [
            [
                Throwable::class,
                Exception::class,
                'try to catch a throwable object that implements interface \throwable.',
            ],
            [
                Exception::class,
                Exception::class,
                'try to catch exception \Exception and get \Exception objects thrown out.',
            ],
            [
                LogicException::class,
                LogicException::class,
                'try to catch exception \LogicException and get \LogicException objects thrown out.',
            ],
            [
                BadFunctionCallException::class,
                BadFunctionCallException::class,
                'try to catch exception \BadFunctionCallException; get \BadFunctionCallException objects thrown out.',
            ],
            [
                BadMethodCallException::class,
                BadMethodCallException::class,
                'try to catch exception \BadMethodCallException and get \BadMethodCallException objects thrown out.',
            ],

            [
                Exception::class,
                LogicException::class,
                'try to catch exception \Exception and get \LogicException objects thrown out.',
            ],
            [
                Exception::class,
                BadFunctionCallException::class,
                'try to catch exception \Exception and get \BadFunctionCallException objects thrown out.',
            ],
            [
                Exception::class,
                BadMethodCallException::class,
                'try to catch exception \Exception and get \BadMethodCallException objects thrown out.',
            ],

            [
                LogicException::class,
                BadFunctionCallException::class,
                'try to catch exception \LogicException and get \BadFunctionCallException objects thrown out.',
            ],
            [
                LogicException::class,
                BadMethodCallException::class,
                'try to catch exception \LogicException and get \BadMethodCallException objects thrown out.',
            ],

            [
                BadFunctionCallException::class,
                BadMethodCallException::class,
                'try to catch exception \BadFunctionCallException and get \BadMethodCallException objects thrown out.',
            ],
        ];
    }

    /**
     * @dataProvider dataUnsuccessfulRetries
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::shouldRetry()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testUnsuccessfulRetries(int $expectedFailedAttempts, int $maxAttempts): void
    {
        $backoff = (new ExponentialBackoff(new ExceptionBasedCondition(Exception::class)))
            ->setSleeper(Helper::doNotSleep())
            ->setMaxAttempts($maxAttempts)
        ;

        $helper = (new Helper())->setException(Exception::class)->setExpectedFailedAttempts($expectedFailedAttempts);
        try {
            $backoff->run($helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut(...));
        } catch (Exception $e) {
            // Nothing to do here. Exceptions will be evaluated in the "finally" block.
        } finally {
            self::assertInstanceOf(Exception::class, $e); // @phpstan-ignore variable.undefined
            self::assertSame('an exception thrown out from class \\' . Helper::class, $e->getMessage());
            self::assertSame(
                $maxAttempts,
                $helper->getAttemptsMade(),
                'maximum number of allowed attempts have been made but all failed with exceptions thrown out'
            );
        }
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function dataUnsuccessfulRetries(): array
    {
        return [
            'will fail 1 time  before getting a value back, but maximally only 1 time  allowed'  => [1, 1],
            'will fail 2 times before getting a value back, but maximally only 1 time  allowed'  => [2, 1],
            'will fail 2 times before getting a value back, but maximally only 2 times allowed'  => [2, 2],
            'will fail 3 times before getting a value back, but maximally only 1 time  allowed'  => [3, 1],
            'will fail 3 times before getting a value back, but maximally only 2 times allowed'  => [3, 2],
            'will fail 3 times before getting a value back, but maximally only 3 times allowed'  => [3, 3],
        ];
    }

    /**
     * @dataProvider dataSetException
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::setExceptions()
     */
    public function testSetException(string $exception): void
    {
        self::assertSame([$exception], (new ExceptionBasedCondition($exception))->getExceptions());
    }

    /**
     * An ignored type wins over the type to retry on, which is the point: it is how a subclass gets left alone while
     * its parent keeps being retried.
     *
     * @dataProvider dataIgnoredExceptions
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::shouldRetry()
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::setIgnoredExceptions()
     */
    public function testIgnoredExceptions(bool $expected, Exception $thrown): void
    {
        $condition = (new ExceptionBasedCondition(LogicException::class))
            ->setIgnoredExceptions(BadMethodCallException::class)
        ;

        self::assertSame([BadMethodCallException::class], $condition->getIgnoredExceptions());
        self::assertSame($expected, $condition->shouldRetry(null, $thrown), $thrown::class);
    }

    /**
     * @return array<string, array{0: bool, 1: Exception}>
     */
    public static function dataIgnoredExceptions(): array
    {
        // LogicException > BadFunctionCallException > BadMethodCallException
        return [
            'the type to retry on is retried'                   => [true, new LogicException()],
            'so is a subclass of it'                            => [true, new BadFunctionCallException()],
            'the ignored subclass is not'                       => [false, new BadMethodCallException()],
            'and neither is anything outside the list'          => [false, new RuntimeException()],
        ];
    }

    /**
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::setIgnoredExceptions()
     */
    public function testIgnoredExceptionsAreValidatedToo(): void
    {
        $this->expectException(\CrowdStar\Backoff\Exception::class);
        $this->expectExceptionMessage('ArrayAccess objects are not instances of interface \Throwable');

        (new ExceptionBasedCondition())->setIgnoredExceptions(ArrayAccess::class);
    }

    /**
     * An ignored exception ends the run at once, and is thrown out rather than swallowed.
     *
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::shouldRetry()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testIgnoredExceptionStopsTheRun(): void
    {
        $attempts  = 0;
        $condition = (new ExceptionBasedCondition(LogicException::class))
            ->setIgnoredExceptions(BadMethodCallException::class)
        ;

        try {
            (new ExponentialBackoff($condition))->run(
                function () use (&$attempts): never {
                    $attempts++;

                    throw new BadMethodCallException('not worth retrying');
                }
            );
            self::fail('the ignored exception should have been thrown out');
        } catch (BadMethodCallException $e) {
            self::assertSame('not worth retrying', $e->getMessage());
            self::assertSame(1, $attempts, 'no retry was attempted');
        }
    }

    /**
     * @return array<array<string>>
     */
    public static function dataSetException(): array
    {
        return [
            [
                Throwable::class,
            ],
            [
                Exception::class,
            ],
            [
                LogicException::class,
            ],
            [
                BadFunctionCallException::class,
            ],
            [
                BadMethodCallException::class,
            ],
            [
                ExpectationFailedException::class, // this one requires at least 1 parameter in the constructor method.
            ],
        ];
    }

    /**
     * @dataProvider dataSetExceptionWithExceptions
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::setExceptions()
     */
    public function testSetExceptionWithExceptions(string $expectedExceptionMessage, string $exception): void
    {
        $this->expectException(\CrowdStar\Backoff\Exception::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        new ExceptionBasedCondition($exception);
    }

    /**
     * @return array<array<string>>
     */
    public static function dataSetExceptionWithExceptions(): array
    {
        return [
            [
                'ArrayAccess objects are not instances of interface \Throwable',
                ArrayAccess::class,
            ],
            [
                'Class/interface "\CrowdStar\Backoff\a_non_existing_class_name" does not exist',
                '\CrowdStar\Backoff\a_non_existing_class_name',
            ],
            [
                'Error objects are not instances of class \Exception',
                Error::class,
            ],
            [
                'TypeError objects are not instances of class \Exception',
                TypeError::class,
            ],
        ];
    }
}
