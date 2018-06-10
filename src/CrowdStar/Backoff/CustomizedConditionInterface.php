<?php

namespace CrowdStar\Backoff;

use Exception;

/**
 * Interface CustomizedConditionInterface
 *
 * @package CrowdStar\Backoff
 */
interface CustomizedConditionInterface
{
    /**
     * Don't retry if conditions met.
     *
     * @param mixed $result
     * @param Exception|null $e
     * @return bool return TRUE if conditions met, otherwise return FALSE.
     * @see CustomizedCondition::met()
     * @see AbstractRetryCondition::met()
     * @see ExponentialBackoff::retry()
     */
    public function met($result, ?Exception $e): bool;
}
