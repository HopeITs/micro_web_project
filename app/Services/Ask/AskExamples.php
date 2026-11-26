<?php

namespace App\Services\Ask;

use App\Models\WebAccount;
use App\Services\AccountAccess;

/** Example questions shown as suggestions: only the ones that make sense for what the account may see (its cities and capabilities). */
final class AskExamples
{
    /** dataset => [key, capability needed in some city (or null), cities needed] */
    private const CATALOG = [
        'projects' => [['financed_city', null, 1], ['last_30', null, 0], ['amount', null, 0], ['women_year', null, 0], ['rejected_except', null, 2], ['overdue', 'finance.view', 0]],
        'people' => [['women_over', null, 0], ['married_city', null, 1], ['no_projects', null, 0], ['born', null, 0], ['students', null, 0]],
        'installments' => [['overdue_30', null, 0], ['unpaid_usd', null, 0], ['next_30', null, 0], ['overdue_city', null, 1], ['paid_month', null, 0]],
    ];

    /**
     * @param  list<object>  $cities  the cities the account may read this dataset in (id, name_ar, name_en)
     * @return list<string>
     */
    public static function for(WebAccount $account, string $dataset, array $cities): array
    {
        $access = app(AccountAccess::class);
        $en = app()->getLocale() === 'en';
        $name = fn (int $i) => isset($cities[$i]) ? ($en ? $cities[$i]->name_en : $cities[$i]->name_ar) : '';
        $out = [];
        foreach (self::CATALOG[$dataset] ?? [] as [$key, $capability, $needCities]) {
            if (count($cities) < $needCities || ($capability !== null && $access->cities($account, $capability)->isEmpty())) {
                continue;
            }
            $out[] = __('ask.ex_'.$dataset.'_'.$key, ['city' => $name(0), 'other' => $name(1)]);
        }

        return array_slice($out, 0, 6);
    }
}
