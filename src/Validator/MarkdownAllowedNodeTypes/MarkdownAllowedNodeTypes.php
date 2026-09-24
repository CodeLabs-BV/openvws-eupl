<?php

declare(strict_types=1);

namespace Shared\Validator\MarkdownAllowedNodeTypes;

use Symfony\Component\Validator\Constraint;

use function is_string;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class MarkdownAllowedNodeTypes extends Constraint
{
    public string $message = 'The Markdown contains an element that is not allowed ({{ type }}).';

    /** @var list<class-string> */
    public array $allowedNodeTypes = [];

    /**
     * @param class-string|list<class-string> $allowedNodeTypes
     */
    public function __construct(
        string|array $allowedNodeTypes = [],
        ?string $message = null,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(
            options: null,
            groups: $groups,
            payload: $payload,
        );

        if (is_string($allowedNodeTypes)) {
            $allowedNodeTypes = [$allowedNodeTypes];
        }

        $this->allowedNodeTypes = $allowedNodeTypes;

        if ($message !== null) {
            $this->message = $message;
        }
    }

    #[\Override]
    public function validatedBy(): string
    {
        return MarkdownAllowedNodeTypesValidator::class;
    }
}
