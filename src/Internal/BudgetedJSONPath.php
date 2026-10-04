<?php

/**
 * JSON Payload Contract for PHP.
 *
 * @license https://github.com/SoftCreatR/JSONPayloadContract/blob/main/LICENSE  ISC License
 */

declare(strict_types=1);

namespace SoftCreatR\JsonPayloadContract\Internal;

use Flow\JSONPath\JSONPath;
use LogicException;
use Override;

/**
 * Evaluates JSONPath selectors while accounting for intermediate work.
 *
 * @internal
 */
final class BudgetedJSONPath extends JSONPath
{
    private ?EvaluationBudget $budget = null;

    /**
     * Creates an evaluator without overriding JSONPath's final constructor.
     */
    public static function create(mixed $data, EvaluationBudget $budget): self
    {
        $instance = new self($data);
        $instance->budget = $budget;

        return $instance;
    }

    /**
     * Evaluates a selector without allowing unbounded intermediate collections.
     */
    #[Override]
    public function find(string $expression): self
    {
        $budget = $this->budget ?? throw new LogicException('The evaluation budget is missing.');
        $tokens = $this->parseTokens($expression);
        $collectionData = [$this->data];

        foreach ($tokens as $token) {
            $filter = $token->buildFilter($this->options);
            $filter->setRootData($this->data);
            $filteredDataList = [];

            foreach ($collectionData as $value) {
                $budget->consume();

                if (!\is_array($value) && !\is_object($value)) {
                    continue;
                }

                $matches = $filter->filter($value);
                $budget->consume(\count($matches));

                if ($matches !== []) {
                    $filteredDataList[] = $matches;
                }
            }

            $collectionData = $filteredDataList === [] ? [] : \array_merge(...$filteredDataList);
        }

        $result = new self($collectionData, $this->options);
        $result->budget = $budget;

        return $result;
    }
}
