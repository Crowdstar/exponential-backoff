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
 *
 * Types set through $this->setIgnoredExceptions() are never retried, and that takes priority: it is how you say
 * "retry every HttpException, but not HttpBadRequestException", without having to list every sibling of the one
 * exception you want left alone.
 *
 * Only exceptions ever get here, because that is all ExponentialBackoff::run() catches. Throwable and interfaces
 * only Error implements are accepted by the setters, but nothing can ever match them: an Error ends a run whatever
 * this condition is set up to retry.
 */
class ExceptionBasedCondition extends AbstractRetryCondition
{
    /** @var string[] */
    protected array $exceptions = [];

    /** @var string[] */
    protected array $ignoredExceptions = [];

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
    public function shouldRetry(mixed $result, ?BaseException $e): bool
    {
        if ($e === null) {
            return false; // The call went through.
        }

        // Checked before the types to retry on, so that an ignored subclass wins over its retryable parent.
        foreach ($this->getIgnoredExceptions() as $exception) {
            if ($e instanceof $exception) {
                return false;
            }
        }

        foreach ($this->getExceptions() as $exception) {
            if ($e instanceof $exception) {
                return true;
            }
        }

        // Something else was thrown, which retrying is not going to help with. ExponentialBackoff::run() throws it.
        return false;
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
        $this->exceptions = self::validated($exceptions);

        return $this;
    }

    /**
     * Types listed here are never retried, whether or not $this->setExceptions() covers them as well.
     *
     * @return string[]
     */
    public function getIgnoredExceptions(): array
    {
        return $this->ignoredExceptions;
    }

    /**
     * @throws Exception
     */
    public function setIgnoredExceptions(string ...$exceptions): self
    {
        $this->ignoredExceptions = self::validated($exceptions);

        return $this;
    }

    /**
     * @param string[] $exceptions
     * @return string[]
     * @throws Exception
     */
    protected static function validated(array $exceptions): array
    {
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
        }

        return $exceptions;
    }
}
