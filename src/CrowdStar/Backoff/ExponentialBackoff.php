<?php

namespace CrowdStar\Backoff;

use Closure;

/**
 * Class ExponentialBackoff
 *
 * This class uses an exponential back-off algorithm to calculate the timeout for the next request. Exponential
 * back-offs prevent overloading an unavailable service by doubling the timeout each iteration.
 *
 * @package CrowdStar\Backoff
 */
class ExponentialBackoff
{
    const TYPE_MICROSECONDS = 1;
    const TYPE_SECONDS      = 2;

    /**
     * @var int
     */
    protected $type = self::TYPE_MICROSECONDS;

    /**
     * Retry conditions:
     *     1. If a Closure object:
     *        retry when calling the Closure object returns false back.
     *     2. If an non-empty string (an Exception class):
     *        retry when the Exception thrown out is an instance of given Exception class.
     *     3. If null or empty:
     *        retry when return value is empty.
     *
     * @var null|string|Closure
     */
    protected $retryCondition;

    /**
     * @var int
     */
    protected $maxAttempts = 4;

    /**
     * @var int
     */
    protected $currentAttempts;

    /**
     * ExponentialBackoff constructor.
     *
     * @param null|string|Closure $retryCondition
     */
    public function __construct($retryCondition = null)
    {
        $this->reset()->setRetryCondition($retryCondition);
    }

    /**
     * @param Closure $op
     * @param array ...$params
     * @return mixed|null
     * @throws Exception
     */
    public function run(Closure $op, ...$params)
    {
        do {
            $result = $e = null;

            try {
                $result = $op(...$params);
            } catch (\Exception $e) {
                // nothing to process here.
            }
        } while ($this->cont($result, $e));

        // If you still have an exception, throw it
        if (!empty($e)) {
            throw $e;
        }

        return $result;
    }

    /**
     * @return ExponentialBackoff
     */
    public function reset(): ExponentialBackoff
    {
        $this->currentAttempts = 1;

        return $this;
    }

    /**
     * @return int
     */
    public function getType(): int
    {
        return $this->type;
    }

    /**
     * @param int $type
     * @return ExponentialBackoff
     */
    public function setType(int $type): ExponentialBackoff
    {
        $this->type = $type;

        return $this;
    }

    /**
     * @return null|string|Closure
     */
    public function getRetryCondition()
    {
        return $this->retryCondition;
    }

    /**
     * @param null|string|Closure $retryCondition
     * @return ExponentialBackoff
     */
    public function setRetryCondition($retryCondition): ExponentialBackoff
    {
        $this->retryCondition = $retryCondition;

        return $this;
    }

    /**
     * @return int
     */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * @param int $maxAttempts
     * @return ExponentialBackoff
     */
    public function setMaxAttempts(int $maxAttempts): ExponentialBackoff
    {
        $this->maxAttempts = $maxAttempts;

        return $this;
    }

    /**
     * @return int
     */
    public function getCurrentAttempts(): int
    {
        return $this->currentAttempts;
    }

    /**
     * @return ExponentialBackoff
     */
    protected function increaseCurrentAttempts(): ExponentialBackoff
    {
        $this->currentAttempts++;

        return $this;
    }

    /**
     * @param mixed $result
     * @param \Exception|null $e
     * @return bool
     * @throws Exception
     */
    protected function cont($result, ?\Exception $e): bool
    {
        if ($this->getCurrentAttempts() <= $this->getMaxAttempts()) {
            if ($this->getRetryCondition() instanceof Closure) {
                if (!$this->getRetryCondition()($result, $e)) {
                    return false;
                }
            } elseif ($this->getRetryCondition()) {
                $exceptionClass = $this->getRetryCondition();
                if (empty($e) || (!($e instanceof $exceptionClass))) {
                    return false;
                }
            } else {
                if (!empty($result)) {
                    return false;
                }
            }

            $this->sleep();

            return true;
        }

        return false;
    }

    /**
     * @return ExponentialBackoff
     * @throws Exception
     */
    protected function sleep(): ExponentialBackoff
    {
        switch ($this->getType()) {
            case self::TYPE_MICROSECONDS:
                usleep($this->getTimeoutMicroseconds($this->getCurrentAttempts()));
                break;
            case self::TYPE_SECONDS:
                usleep($this->getTimeoutSeconds($this->getCurrentAttempts()));
                break;
            default:
                throw new Exception("invalid backoff type '{$this->getType()}'");
                break;
        }

        return $this->increaseCurrentAttempts();
    }

    /**
     * Get the next timeout in seconds
     *
     * @param int $iteration
     * @param int $initialTimeout
     * @return int
     */
    protected function getTimeoutSeconds(int $iteration, int $initialTimeout = 1): int
    {
        return ($this->getTimeoutMicroseconds($iteration, $initialTimeout * 1000000) / 1000000);
    }

    /**
     * Get the next timeout in microseconds
     *
     * @param int $iteration
     * @param int $initialTimeout
     * @return int
     */
    protected function getTimeoutMicroseconds(int $iteration, int $initialTimeout = 250000): int
    {
        $timeout = $initialTimeout * (1 << --$iteration);

        // We throw in some randomness here to try to prevent connections from colliding
        return ($timeout + rand(0, $timeout / 10));
    }
}
