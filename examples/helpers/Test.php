<?php

namespace CrowdStar\Backoff;

use Exception;

/**
 * Class Test.
 *
 * This is for demonstration purpose only. It's used by example scripts under folder /examples only.
 *
 * @package CrowdStar\Backoff
 */
class Test
{
    /**
     * @var int
     */
    protected static $currentIteration = 0;

    /**
     * @var string
     */
    protected static $value = 'Hello World!';

    /**
     * To return the value back only after 3 iterations; otherwise, return an empty string back instead.
     *
     * @return string Return an empty string back when current iteration is less than 3, otherwise return the value.
     */
    public static function getValueAfterThreeEmptyReturnValues(): string
    {
        if (self::lessThan3()) {
            return '';
        };

        return self::getValue();
    }

    /**
     * To return the value back only after 3 iterations; otherwise, throw an exception instead.
     *
     * @return string
     * @throws Exception When current iteration is less than 3.
     */
    public static function getValueAfterThreeExceptions(): string
    {
        if (self::lessThan3()) {
            throw new Exception();
        };

        return self::getValue();
    }

    /**
     * @return bool Return TRUE if current iteration is less than 3, otherwise return FALSE.
     */
    public static function lessThan3(): bool
    {
        echo "current iteration is: ", self::$currentIteration, "\n";

        return (self::$currentIteration++ < 3);
    }

    /**
     * @return string
     */
    public static function getValue(): string
    {
        return self::$value;
    }
}
