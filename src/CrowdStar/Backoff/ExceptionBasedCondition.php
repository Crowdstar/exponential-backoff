<?php

namespace CrowdStar\Backoff;

/**
 * Class ExceptionBasedCondition
 * Do a retry if specified type of exception is thrown out.
 *
 * @package CrowdStar\Backoff
 */
class ExceptionBasedCondition extends AbstractRetryCondition
{
    /**
     * @var string
     */
    protected $exception;

    /**
     * ExceptionBasedCondition constructor.
     *
     * @param string $exception
     * @throws Exception
     */
    public function __construct(string $exception = \Exception::class)
    {
        $this->setException($exception);
    }

    /**
     * @inheritdoc
     */
    public function met($result, ?\Exception $e): bool
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
     * @return ExceptionBasedCondition
     * @throws Exception
     */
    public function setException(string $exception): ExceptionBasedCondition
    {
        if (!class_exists($exception)) {
            throw new Exception("Exception class {$exception} not exists");
        }
        if (!(new $exception() instanceof \Exception)) {
            throw new Exception("{$exception} objects are not instance of class \Exception");
        }

        $this->exception = $exception;

        return $this;
    }
}
