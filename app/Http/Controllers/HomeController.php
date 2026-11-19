<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Project;
use App\Support\CityContext;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Home: totals and per-city summary for the permitted cities (CityScope applies to every query). */
class HomeController
{
    public function __invoke(CityContext $context): View
    {
        $cities = $context->cityIds();
        $current = fn () => DB::table('project_state_history as h')->join('project_states as s', 's.id', '=', 'h.project_state_id')
            ->where('h.is_current', true)->whereNull('h.deleted_at')->whereIn('h.city_id', $cities);

        $byState = $current()->groupBy('s.name_en')->pluck(DB::raw('COUNT(*)'), 's.name_en');
        $dueQuery = DB::table('loan_installments')->whereIn('city_id', $cities)->whereNull('deleted_at')->where('is_paid', false)->where('due_on', '<', now()->toDateString());

        $perCity = City::whereIn('id', $cities)->orderBy('id')->get()->map(function (City $city) {
            $inst = DB::table('loan_installments')->where('city_id', $city->id)->whereNull('deleted_at')->where('due_on', '<', now()->toDateString());
            $due = (clone $inst)->sum('amount');
            $paid = (clone $inst)->where('is_paid', true)->sum('amount');

            return [
                'city' => $city,
                'projects' => Project::where('city_id', $city->id)->count(),
                'financed' => DB::table('project_state_history as h')->join('project_states as s', 's.id', '=', 'h.project_state_id')
                    ->where('h.city_id', $city->id)->where('h.is_current', true)->whereIn('s.name_en', ['Financed', 'Finished'])->count(),
                'collected' => $due > 0 ? (int) round($paid / $due * 100) : null,
            ];
        });

        return view('home', [
            'total' => Project::count(),
            'financed' => (int) ($byState['Financed'] ?? 0) + (int) ($byState['Finished'] ?? 0),
            'review' => (int) ($byState['Under Review'] ?? 0),
            'preliminary' => Project::where('application_stage', 'preliminary')->count(),
            'due' => (clone $dueQuery)->count(),
            'perCity' => $perCity,
            'recent' => Project::with(['city', 'projectApplicants.person'])->orderByDesc('request_date')->limit(6)->get(),
        ]);
    }
}
