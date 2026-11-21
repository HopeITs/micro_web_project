<?php

namespace App\Support\Datasets;

use InvalidArgumentException;

/** A QuerySpec that does not fit its dataset; errors() lists every problem. */
final class InvalidQuerySpec extends InvalidArgumentException
{
    /** @param list<string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Invalid query spec: '.implode('; ', $errors));
    }

    /** @return list<string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
