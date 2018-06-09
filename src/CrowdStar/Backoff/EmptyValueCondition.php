<?php

namespace CrowdStar\Backoff;

use Exception;

/**
 * Class EmptyValueCondition
 *
 * @package CrowdStar\Backoff
 */
class EmptyValueCondition extends AbstractRetryCondition
{
    /**
     * @inheritdoc
     */
    public function met($result, ?Exception $e): bool
    {
        return !empty($result);
    }
}
