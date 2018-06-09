<?php

namespace CrowdStar\Backoff;

use Exception;

/**
 * Class ExceptionBasedCondition
 *
 * @package CrowdStar\Backoff
 */
class ExceptionCondition extends AbstractRetryCondition
{
    /**
     * @var string
     */
    protected $exception;

    /**
     * Condition constructor.
     *
     * @param string $exception
     */
    public function __construct(string $exception = Exception::class)
    {
        $this->setException($exception);
    }

    /**
     * @inheritdoc
     */
    public function met($result, ?Exception $e): bool
    {
        $exception = $this->getException();

        return (empty($e) || (!($e instanceof $exception)));
    }

    /**
     * @return string
     */
    public function getException(): string
    {
        return $this->exception;
    }

    /**
     * @param string $exception
     * @return ExceptionCondition
     */
    public function setException(string $exception): ExceptionCondition
    {
        $this->exception = $exception;

        return $this;
    }
}
