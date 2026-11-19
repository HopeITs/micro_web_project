<?php

namespace App\Support;

/**
 * The cities the current request may read. Set once per request by the RestrictToPermittedCities
 * middleware; when it is not set (console, imports, tests that do not opt in) city models are not filtered.
 */
final class CityContext
{
    /** @var list<int>|null */
    private ?array $cityIds = null;

    /** @param iterable<int> $cityIds */
    public function restrictTo(iterable $cityIds): void
    {
        $this->cityIds = array_values(array_map('intval', is_array($cityIds) ? $cityIds : iterator_to_array($cityIds)));
    }

    public function clear(): void
    {
        $this->cityIds = null;
    }

    public function active(): bool
    {
        return $this->cityIds !== null;
    }

    /** @return list<int> */
    public function cityIds(): array
    {
        return $this->cityIds ?? [];
    }

    public function allows(int $cityId): bool
    {
        return $this->cityIds === null || in_array($cityId, $this->cityIds, true);
    }
}
