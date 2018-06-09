<?php

namespace CrowdStar\Tests\Backoff;

use Exception;

/**
 * Class Helper.
 *
 * This is for demonstration purpose only. It's used by example scripts under folder /examples and unit tests.
 *
 * @package CrowdStar\Backoff
 */
class Helper
{
    const VALUE = 'Hello World!';

    /**
     * @var int
     */
    protected $currentIteration = 0;

    /**
     * To return the value back only after 3 iterations; otherwise, return an empty string back instead.
     *
     * @return string Return an empty string back when current iteration is less than 3, otherwise return the value.
     */
    public function getValueAfterThreeEmptyReturnValues(): string
    {
        if ($this->isLessThan3()) {
            return '';
        };

        return $this->getValue();
    }

    /**
     * To return the value back only after 3 iterations; otherwise, throw an exception instead.
     *
     * @return string
     * @throws Exception When current iteration is less than 3.
     */
    public function getValueAfterThreeExceptions(): string
    {
        if ($this->isLessThan3()) {
            throw new Exception();
        };

        return $this->getValue();
    }

    /**
     * @return bool Return TRUE if current iteration is less than 3, otherwise return FALSE.
     */
    public function isLessThan3(): bool
    {
        if (!defined('UNDER_PHPUNIT') || !UNDER_PHPUNIT) {
            echo "current iteration is: ", $this->currentIteration, "\n";
        }

        return ($this->currentIteration++ < 3);
    }

    /**
     * @return $this
     */
    public function reset(): Helper
    {
        $this->currentIteration = 0;

        return $this;
    }

    /**
     * @return string
     */
    public function getValue(): string
    {
        return self::VALUE;
    }

    /**
     * @return int
     */
    public function getCurrentIteration(): int
    {
        return $this->currentIteration;
    }

    /**
     * @param int $currentIteration
     * @return $this
     */
    public function setCurrentIteration(int $currentIteration): Helper
    {
        $this->currentIteration = $currentIteration;

        return $this;
    }
}
