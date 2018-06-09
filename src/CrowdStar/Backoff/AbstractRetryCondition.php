<?php

namespace CrowdStar\Backoff;

use Exception;

/**
 * Class AbstractRetryCondition
 *
 * @package CrowdStar\Backoff
 */
abstract class AbstractRetryCondition
{
    /**
     * Don't retry if conditions met.
     *
     * @param mixed $result
     * @param Exception|null $e
     * @return bool
     * @see ExponentialBackoff::retry()
     */
    abstract public function met($result, ?Exception $e): bool;
}
