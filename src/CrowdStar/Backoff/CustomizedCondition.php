<?php

namespace CrowdStar\Backoff;

use Closure;
use Exception;

/**
 * Class CustomizedCondition
 * Use self-defined function to determine if a retry is needed to do or not.
 *
 * @package CrowdStar\Backoff
 */
class CustomizedCondition extends AbstractRetryCondition
{
    /**
     * @var Closure
     */
    protected $closure;

    /**
     * CustomizedCondition constructor.
     *
     * @param Closure $closure
     */
    public function __construct(Closure $closure)
    {
        $this->setClosure($closure);
    }

    /**
     * @inheritdoc
     */
    public function met($result, ?Exception $e): bool
    {
        return $this->getClosure()($result, $e);
    }

    /**
     * @return Closure
     */
    public function getClosure(): Closure
    {
        return $this->closure;
    }

    /**
     * @param Closure $closure
     * @return $this
     */
    public function setClosure(Closure $closure): CustomizedCondition
    {
        $this->closure = $closure;

        return $this;
    }
}
