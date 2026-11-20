<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\People\PeopleService;
use App\Services\People\PersonFields;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** M4.3 people pages; the list, the form, the family and the sub-tables are Livewire components that recheck every action. */
final class PeopleController
{
    public function index(Request $request, PeopleService $people): View
    {
        $people->cityIds($request->user()) !== [] || abort(403);
        $people->audit($request->user(), 'people.list');

        return view('people.index');
    }

    public function create(Request $request, PeopleService $people): View
    {
        $people->cityIds($request->user(), 'people.manage') !== [] || abort(403);

        return view('people.create');
    }

    public function show(Request $request, string $locale, int $person, PeopleService $people): View
    {
        $row = $people->person($request->user(), $person);
        $people->audit($request->user(), 'people.view', (int) $row->city_id, $person);
        $labels = [];
        foreach (PersonFields::personFlat() as $field => $spec) {
            if ($spec['kind'] === 'lookup' && $row->{$field}) {
                $value = DB::table($spec['table'])->where('id', $row->{$field})->first(['name_ar', 'name_en']);
                $labels[$field] = $value ? ($locale === 'en' && $value->name_en ? $value->name_en : $value->name_ar) : null;
            }
        }

        return view('people.show', [
            'person' => $row, 'labels' => $labels, 'city' => DB::table('cities')->where('id', $row->city_id)->first(),
            'projects' => $people->projects($person), 'editable' => $people->canManage($request->user(), (int) $row->city_id),
        ]);
    }

    public function edit(Request $request, string $locale, int $person, PeopleService $people): View
    {
        $row = $people->person($request->user(), $person);
        $people->canManage($request->user(), (int) $row->city_id) || abort(403);

        return view('people.edit', ['person' => $row]);
    }
}
