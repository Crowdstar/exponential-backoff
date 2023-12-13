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
use Error;
use Exception;
use LogicException;
use OutOfRangeException;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use TypeError;

/**
 * Class ExceptionsBasedConditionTest
 *
 * @internal
 * @coversNothing
 */
class ExceptionsBasedConditionTest extends TestCase
{
    /**
     * @return array<array{0: array<string>, 1: array<string>, 2: string}>
     */
    public function dataSuccessfulRetries(): array
    {
        // @see http://php.net/manual/en/spl.exceptions.php SPL exceptions
        // Exception > LogicException > BadFunctionCallException > BadMethodCallException
        // Exception > LogicException > OutOfRangeException
        // Exception > RuntimeException
        return [
            // Single type of throwable objects.
            // Same as those in method \CrowdStar\Tests\Backoff\ExceptionBasedConditionTest::dataSuccessfulRetries().
            [
                [Throwable::class],
                [Exception::class],
                'try to catch a throwable object that implements interface \throwable.',
            ],
            [
                [Exception::class],
                [Exception::class],
                'try to catch exception \Exception and get \Exception objects thrown out.',
            ],
            [
                [LogicException::class],
                [LogicException::class],
                'try to catch exception \LogicException and get \LogicException objects thrown out.',
            ],
            [
                [BadFunctionCallException::class],
                [BadFunctionCallException::class],
                'try to catch exception \BadFunctionCallException; get \BadFunctionCallException objects thrown out.',
            ],
            [
                [BadMethodCallException::class],
                [BadMethodCallException::class],
                'try to catch exception \BadMethodCallException and get \BadMethodCallException objects thrown out.',
            ],

            [
                [Exception::class],
                [LogicException::class],
                'try to catch exception \Exception and get \LogicException objects thrown out.',
            ],
            [
                [Exception::class],
                [BadFunctionCallException::class],
                'try to catch exception \Exception and get \BadFunctionCallException objects thrown out.',
            ],
            [
                [Exception::class],
                [BadMethodCallException::class],
                'try to catch exception \Exception and get \BadMethodCallException objects thrown out.',
            ],

            [
                [LogicException::class],
                [BadFunctionCallException::class],
                'try to catch exception \LogicException and get \BadFunctionCallException objects thrown out.',
            ],
            [
                [LogicException::class],
                [BadMethodCallException::class],
                'try to catch exception \LogicException and get \BadMethodCallException objects thrown out.',
            ],

            [
                [BadFunctionCallException::class],
                [BadMethodCallException::class],
                'try to catch exception \BadFunctionCallException and get \BadMethodCallException objects thrown out.',
            ],

            // Multiple types of throwable objects.
            [
                [Throwable::class], // $exceptionsToCatch
                [Exception::class, OutOfRangeException::class], // $exceptionsToThrow
                'The exception to catch is an interface',
            ],
            [
                [Exception::class], // $exceptionsToCatch
                [Exception::class, RuntimeException::class], // $exceptionsToThrow
                'The exception to catch is the one thrown out, or a parent exception of the one thrown out.',
            ],
            [
                [LogicException::class], // $exceptionsToCatch
                [BadMethodCallException::class, OutOfRangeException::class], // $exceptionsToThrow
                'The exception to catch is a parent exception.',
            ],
            [
                [LogicException::class, BadMethodCallException::class, RuntimeException::class], // $exceptionsToCatch
                [OutOfRangeException::class], // $exceptionsToThrow
                'The exceptions to catch are more than what is thrown out.',
            ],
            [
                [LogicException::class, BadMethodCallException::class, RuntimeException::class], // $exceptionsToCatch
                [RuntimeException::class, BadFunctionCallException::class], // $exceptionsToThrow
                'The exceptions to catch are more than what to throw out.',
            ],
            [
                [LogicException::class, BadMethodCallException::class, RuntimeException::class], // $exceptionsToCatch
                [RuntimeException::class, BadMethodCallException::class, LogicException::class], // $exceptionsToThrow
                'The exceptions to catch are of the same as those to throw out (although ordered differently).',
            ],
        ];
    }

    /**
     * @param string[] $exceptionsToCatch
     * @param string[] $exceptionsToThrow
     * @dataProvider dataSuccessfulRetries
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::met()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testSuccessfulRetries(array $exceptionsToCatch, array $exceptionsToThrow, string $message): void
    {
        $maxAttempts = 3; // Three attempts are enough for verification purpose.
        foreach ([0, 1, 2] as $expectedFailedAttempts) {
            $helper  = (new Helper())
                ->setExceptions(...$exceptionsToThrow)
                ->setExpectedFailedAttempts($expectedFailedAttempts)
            ;
            $backoff = (new ExponentialBackoff(new ExceptionBasedCondition(...$exceptionsToCatch)))
                ->setMaxAttempts($maxAttempts)
            ;

            self::assertSame(1, getCurrentAttempts($backoff), 'current iteration should be 1 (not yet started)');
            self::assertSame(
                $helper->getValue(),
                $backoff->run(
                    function () use ($helper) {
                        return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut();
                    }
                ),
                $message
            );
            self::assertSame(
                $expectedFailedAttempts + 1,
                getCurrentAttempts($backoff),
                'total # of attempts made should be one time more than failed attempts'
            );
        }
    }

    /**
     * @return array<array{0: int, 1: int, 2: array<string>, 3: array<string>}>
     */
    public function dataUnsuccessfulRetries(): array
    {
        // @see http://php.net/manual/en/spl.exceptions.php SPL exceptions
        // Exception > LogicException > BadFunctionCallException > BadMethodCallException
        // Exception > LogicException > OutOfRangeException
        // Exception > RuntimeException
        return [
            [
                // NOTE: The exception to catch is an interface.
                1, // $expectedFailedAttempts
                1, // $maxAttempts
                [Throwable::class], // $exceptionsToCatch
                [Exception::class, OutOfRangeException::class], // $exceptionsToThrow
                'will fail 1 time  before getting a value back, but maximally only 1 time  allowed',
            ],
            [
                // NOTE: The exception to catch is the one thrown out, or a parent exception of the one thrown out.
                2, // $expectedFailedAttempts
                1, // $maxAttempts
                [Exception::class], // $exceptionsToCatch
                [Exception::class, RuntimeException::class], // $exceptionsToThrow
                'will fail 2 times before getting a value back, but maximally only 1 time  allowed',
            ],
            [
                // NOTE: The exception to catch is a parent exception.
                2, // $expectedFailedAttempts
                2, // $maxAttempts
                [LogicException::class], // $exceptionsToCatch
                [BadMethodCallException::class, OutOfRangeException::class], // $exceptionsToThrow
                'will fail 2 times before getting a value back, but maximally only 2 times allowed',
            ],
            [
                // NOTE: The exceptions to catch are more than what is thrown out.
                3, // $expectedFailedAttempts
                1, // $maxAttempts
                [LogicException::class, BadMethodCallException::class, RuntimeException::class], // $exceptionsToCatch
                [OutOfRangeException::class], // $exceptionsToThrow
                'will fail 3 times before getting a value back, but maximally only 1 time  allowed',
            ],
            [
                // NOTE: The exceptions to catch are more than what to throw out.
                3, // $expectedFailedAttempts
                2, // $maxAttempts
                [LogicException::class, BadMethodCallException::class, RuntimeException::class], // $exceptionsToCatch
                [RuntimeException::class, BadFunctionCallException::class], // $exceptionsToThrow
                'will fail 3 times before getting a value back, but maximally only 2 times allowed',
            ],
            [
                // NOTE: The exceptions to catch are of the same as those to throw out (although ordered differently).
                3, // $expectedFailedAttempts
                3, // $maxAttempts
                [LogicException::class, BadMethodCallException::class, RuntimeException::class], // $exceptionsToCatch
                [RuntimeException::class, BadMethodCallException::class, LogicException::class], // $exceptionsToThrow
                'will fail 3 times before getting a value back, but maximally only 3 times allowed',
            ],
        ];
    }

    /**
     * @param string[] $exceptionsToCatch
     * @param string[] $exceptionsToThrow
     * @dataProvider dataUnsuccessfulRetries
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::met()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testUnsuccessfulRetries(
        int $expectedFailedAttempts,
        int $maxAttempts,
        array $exceptionsToCatch,
        array $exceptionsToThrow
    ): void {
        $backoff = (new ExponentialBackoff(new ExceptionBasedCondition(...$exceptionsToCatch)))
            ->setMaxAttempts($maxAttempts)
        ;
        self::assertSame(1, getCurrentAttempts($backoff), 'current iteration should be 1 (not yet started)');

        $helper = (new Helper())
            ->setExceptions(...$exceptionsToThrow)
            ->setExpectedFailedAttempts($expectedFailedAttempts)
        ;
        try {
            $backoff->run(
                function () use ($helper) {
                    return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut();
                }
            );
        } catch (Throwable $t) {
            // Nothing to do here. Exceptions will be evaluated in the "finally" block.
        } finally {
            self::assertInstanceOf(
                $exceptionsToThrow[($maxAttempts - 1) % count($exceptionsToThrow)], // @phpstan-ignore argument.type
                $t, // @phpstan-ignore variable.undefined
                'The object thrown out is from the last failed attempt.'
            );
            self::assertSame('an exception thrown out from class \\' . Helper::class, $t->getMessage());
            self::assertSame(
                $maxAttempts,
                getCurrentAttempts($backoff),
                'maximum number of allowed attempts have been made but all failed with exceptions thrown out'
            );
        }
    }

    /**
     * @return array<array<string>>
     */
    public function dataSetExceptions(): array
    {
        return [
            // Single type of throwable objects.
            // Same as those in method \CrowdStar\Tests\Backoff\ExceptionBasedConditionTest::dataSetException().
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
                ExpectationFailedException::class, // Requires at least 1 parameter in the constructor method.
            ],

            // Multiple types of throwable objects.
            [
                Throwable::class, Exception::class, LogicException::class,
            ],
        ];
    }

    /**
     * @dataProvider dataSetExceptions
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::setExceptions()
     */
    public function testSetExceptions(string ...$exceptions): void
    {
        self::assertSame($exceptions, (new ExceptionBasedCondition(...$exceptions))->getExceptions());
    }

    /**
     * @return array<array{0: string, 1: array<string>}>
     */
    public function dataSetExceptionsWithExceptions(): array
    {
        return [
            // Single type of throwable objects.
            // Same as method \CrowdStar\Tests\Backoff\ExceptionBasedConditionTest::dataSetExceptionWithExceptions().
            [
                'ArrayAccess objects are not instances of interface \Throwable',
                [ArrayAccess::class],
            ],
            [
                'Class/interface "\CrowdStar\Backoff\a_non_existing_class_name" does not exist',
                ['\CrowdStar\Backoff\a_non_existing_class_name'],
            ],
            [
                'Error objects are not instances of class \Exception',
                [Error::class],
            ],
            [
                'TypeError objects are not instances of class \Exception',
                [TypeError::class],
            ],

            // Different types of objects, with some non-throwable objects included.
            [
                'ArrayAccess objects are not instances of interface \Throwable',
                [Throwable::class, ArrayAccess::class],
            ],
            [
                'Class/interface "\CrowdStar\Backoff\a_non_existing_class_name" does not exist',
                ['\CrowdStar\Backoff\a_non_existing_class_name', Throwable::class],
            ],
            [
                'Error objects are not instances of class \Exception',
                [Exception::class, Error::class],
            ],
            [
                'TypeError objects are not instances of class \Exception',
                [TypeError::class, Exception::class],
            ],
        ];
    }

    /**
     * @param string[] $exceptions
     * @dataProvider dataSetExceptionsWithExceptions
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::setExceptions()
     */
    public function testSetExceptionsWithExceptions(string $expectedExceptionMessage, array $exceptions): void
    {
        $this->expectException(\CrowdStar\Backoff\Exception::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        new ExceptionBasedCondition(...$exceptions);
    }
}
