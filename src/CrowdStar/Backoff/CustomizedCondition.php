<?php

namespace CrowdStar\Backoff;

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
     * @var CustomizedConditionInterface
     */
    protected $condition;

    /**
     * CustomizedCondition constructor.
     *
     * @param CustomizedConditionInterface $condition
     */
    public function __construct(CustomizedConditionInterface $condition)
    {
        $this->setCondition($condition);
    }

    /**
     * @inheritdoc
     */
    public function met($result, ?Exception $e): bool
    {
        return $this->getCondition()->met($result, $e);
    }

    /**
     * @return CustomizedConditionInterface
     */
    public function getCondition(): CustomizedConditionInterface
    {
        return $this->condition;
    }

    /**
     * @param CustomizedConditionInterface $condition
     * @return $this
     */
    public function setCondition(CustomizedConditionInterface $condition): CustomizedCondition
    {
        $this->condition = $condition;

        return $this;
    }
}
