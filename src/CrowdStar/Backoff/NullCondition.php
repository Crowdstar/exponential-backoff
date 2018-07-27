<?php

namespace CrowdStar\Backoff;

use Exception;

/**
 * Class NullCondition
 * Don't retry.
 *
 * @package CrowdStar\Backoff
 */
class NullCondition extends AbstractRetryCondition
{
    /**
     * @inheritdoc
     */
    public function met($result, ?Exception $e): bool
    {
        return true;
    }
}
