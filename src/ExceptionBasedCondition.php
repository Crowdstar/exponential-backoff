<?php

/**************************************************************************
 * Copyright 2018 Glu Mobile Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *************************************************************************/

declare(strict_types=1);

namespace CrowdStar\Backoff;

use Exception as BaseException;
use ReflectionClass;
use Throwable;

/**
 * Class ExceptionBasedCondition
 * Do a retry if specified types of exceptions are thrown out.
 *
 * @package CrowdStar\Backoff
 */
class ExceptionBasedCondition extends AbstractRetryCondition
{
    /**
     * @var string[]
     */
    protected $exceptions;

    /**
     * ExceptionBasedCondition constructor.
     *
     * @throws Exception
     */
    public function __construct(string ...$exceptions)
    {
        $exceptions = $exceptions ?: [BaseException::class];
        $this->setExceptions(...$exceptions);
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
     * @deprecated This will be removed in the next major version. Use {@see self::getExceptions} instead.
     * @see \CrowdStar\Backoff\ExceptionBasedCondition::getExceptions()
     */
    public function getException(): string
    {
        if (count($this->exceptions) === 1) {
            return $this->exceptions[0];
        }

        throw new Exception('Method ' . __METHOD__ . ' can be used only when one type of exception to be handled.');
    }

    /**
     * @return string[]
     */
    public function getExceptions(): array
    {
        return $this->exceptions;
    }

    /**
     * @throws Exception
     * @deprecated This will be removed in the next major version. Use {@see self::setExceptions} instead.
     * @see \CrowdStar\Backoff\ExceptionBasedCondition::setExceptions()
     */
    public function setException(string $exception): self
    {
        return $this->setExceptions($exception);
    }

    public function setExceptions(string ...$exceptions): self
    {
        $this->exceptions = [];
        foreach ($exceptions as $exception) {
            if (!class_exists($exception) && !interface_exists($exception)) {
                throw new Exception("Class/interface \"{$exception}\" does not exist");
            }

            $class = new ReflectionClass($exception);

            if (class_exists($exception)) {
                if ((BaseException::class != $class->getName()) && !$class->isSubclassOf(BaseException::class)) {
                    throw new Exception("{$exception} objects are not instances of class \Exception");
                }
            } else {
                if ((Throwable::class != $class->getName()) && !$class->implementsInterface(Throwable::class)) {
                    throw new Exception("{$exception} objects are not instances of interface \Throwable");
                }
            }

            $this->exceptions[] = $exception;
        }

        return $this;
    }
}
