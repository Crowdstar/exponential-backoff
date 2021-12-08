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
    public function setException(string $exception): self
    {
        if (class_exists($exception)) {
            $class = new ReflectionClass($exception);
            if ((BaseException::class != $class->getName()) && !$class->isSubclassOf(BaseException::class)) {
                throw new Exception("{$exception} objects are not instances of class \Exception");
            }

            $this->exception = $exception;

            return $this;
        } elseif (interface_exists($exception)) {
            $class = new ReflectionClass($exception);
            if ((Throwable::class != $class->getName()) && !$class->implementsInterface(Throwable::class)) {
                throw new Exception("{$exception} objects are not instances of interface \Throwable");
            }

            $this->exception = $exception;

            return $this;
        }

        throw new Exception("Class/interface \"{$exception}\" does not exist");
    }
}
