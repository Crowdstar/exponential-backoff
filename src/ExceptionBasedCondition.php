<?php
/**
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
 */

declare(strict_types=1);

namespace CrowdStar\Backoff;

use Exception as BaseException;
use ReflectionClass;
use Throwable;

/**
 * Class ExceptionBasedCondition
 * Do a retry if specified types of exceptions are thrown out.
 */
class ExceptionBasedCondition extends AbstractRetryCondition
{
    /** @var string[] */
    protected array $exceptions = [];

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
     * {@inheritdoc}
     */
    public function met(mixed $result, ?BaseException $e): bool
    {
        if (empty($e)) {
            return true;
        }

        foreach ($this->getExceptions() as $exception) {
            if ($e instanceof $exception) {
                return false;
            }
        }

        return true;
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
     */
    public function setExceptions(string ...$exceptions): self
    {
        $this->exceptions = [];
        foreach ($exceptions as $exception) {
            if (!class_exists($exception) && !interface_exists($exception)) {
                throw new Exception("Class/interface \"{$exception}\" does not exist");
            }

            $class = new ReflectionClass($exception);

            if (class_exists($exception)) {
                if (($class->getName() != BaseException::class) && !$class->isSubclassOf(BaseException::class)) {
                    throw new Exception("{$exception} objects are not instances of class \\" . BaseException::class);
                }
            } else {
                if (($class->getName() != Throwable::class) && !$class->implementsInterface(Throwable::class)) {
                    throw new Exception("{$exception} objects are not instances of interface \\" . Throwable::class);
                }
            }

            $this->exceptions[] = $exception;
        }

        return $this;
    }
}
