<?php

namespace App\Support\Datasets;

use App\Models\WebAccount;
use App\Services\AccountAccess;

/** The datasets the report builder offers, by key. A dataset is offered only where the account may read it. */
final class DatasetRegistry
{
    /** @var array<string, class-string<Dataset>> */
    public const DATASETS = [
        'projects' => ProjectsDataset::class,
        'people' => PeopleDataset::class,
        'installments' => InstallmentsDataset::class,
    ];

    public static function find(string $key): ?Dataset
    {
        $class = self::DATASETS[$key] ?? null;

        return $class === null ? null : new $class;
    }

    /** @return array<string, Dataset> the datasets the account holds the view capabilities of in at least one city */
    public static function readableBy(WebAccount $account): array
    {
        $access = app(AccountAccess::class);
        $out = [];
        foreach (self::DATASETS as $key => $class) {
            $dataset = new $class;
            if ($access->citiesWithAll($account, $dataset->viewCapabilities())->isNotEmpty()) {
                $out[$key] = $dataset;
            }
        }

        return $out;
    }
}
