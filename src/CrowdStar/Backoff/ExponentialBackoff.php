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
     * @var int
     */
    protected $maxAttempts = 4;

    /**
     * @var int
     */
    protected $currentAttempts = 1;

    /**
     * @var AbstractRetryCondition
     */
    protected $retryCondition;

    /**
     * ExponentialBackoff constructor.
     *
     * @param AbstractRetryCondition $retryCondition
     */
    public function __construct(AbstractRetryCondition $retryCondition)
    {
        $this->setRetryCondition($retryCondition);
    }

    /**
     * @param Closure $c
     * @param array ...$params
     * @return mixed
     * @throws Exception
     */
    public function run(Closure $c, ...$params)
    {
        do {
            $result = $e = null;

            try {
                $result = $c(...$params);
            } catch (\Exception $e) {
                // Nothing to process here.
            }
        } while ($this->retry($result, $e));

        // If you still have an exception, throw it
        if (!empty($e)) {
            throw $e;
        }

        return $result;
    }

    /**
     * @return $this
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
     * @return $this
     */
    public function setType(int $type): ExponentialBackoff
    {
        $this->type = $type;

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
     * @return $this
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
     * @return $this
     */
    protected function increaseCurrentAttempts(): ExponentialBackoff
    {
        $this->currentAttempts++;

        return $this;
    }

    /**
     * @return AbstractRetryCondition
     */
    public function getRetryCondition()
    {
        return $this->retryCondition;
    }

    /**
     * @param AbstractRetryCondition $retryCondition
     * @return $this
     */
    public function setRetryCondition(AbstractRetryCondition $retryCondition): ExponentialBackoff
    {
        $this->retryCondition = $retryCondition;

        return $this;
    }

    /**
     * @param mixed $result
     * @param \Exception|null $e
     * @return bool
     * @throws Exception
     */
    protected function retry($result, ?\Exception $e): bool
    {
        if ($this->getCurrentAttempts() <= $this->getMaxAttempts()) {
            if ($this->getRetryCondition()->met($result, $e)) {
                return false;
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
