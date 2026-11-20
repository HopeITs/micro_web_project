<?php

declare(strict_types=1);

namespace App\Services\People;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\ProjectNumberAllocator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * M4.4: is this national number known anywhere at Hope Center? Like the desktop Check_NationalNumber_Form it looks in every Micro
 * Projects city and in the DISC and F2F databases (read-only connection, config/national_check.php) and gives the name, the programme
 * and the state. Owner decision (20 Nov 2026): the name, city and state are shown for every city, as the desktop did; a link to the
 * record only where the account may read people. Numbers are compared without leading zeros. The number itself is never logged.
 */
final class NationalNumberCheck
{
    public const ATTEMPTS_PER_MINUTE = 30;

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit) {}

    /** Digits 5–14 long once spaces, dashes and leading zeros are removed, or a validation error. */
    public static function normalize(string $number): string
    {
        $key = PeopleService::nationalKey($number);
        if (strlen($key) < 5 || strlen($key) > 14) {
            throw ValidationException::withMessages(['number' => __('national.errors.number')]);
        }

        return $key;
    }

    /**
     * @return array{micro: list<object>, external: list<array{source: string, program: string, city: string, status: string, matches: list<object>}>}
     */
    public function check(WebAccount $account, string $number, bool $external = true): array
    {
        $this->access->cities($account, 'people.view')->isNotEmpty() || abort(403);
        $limit = 'national-check:'.$account->id;
        if (RateLimiter::tooManyAttempts($limit, self::ATTEMPTS_PER_MINUTE)) {
            throw ValidationException::withMessages(['number' => __('national.errors.too_many', ['seconds' => RateLimiter::availableIn($limit)])]);
        }
        RateLimiter::hit($limit, 60);
        $key = self::normalize($number);
        $result = ['micro' => $this->micro($account, $key), 'external' => $external ? $this->external($key) : []];
        $this->audit->record($account->id, 'national.checked', 'succeeded');

        return $result;
    }

    /** Every city of Micro Projects, with each person's projects and their current state. @return list<object> */
    private function micro(WebAccount $account, string $key): array
    {
        $readable = $this->access->cities($account, 'people.view')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $people = DB::table('people as p')->join('cities as c', 'c.id', '=', 'p.city_id')->whereNull('p.deleted_at')
            ->where('p.national_number', 'like', '%'.substr($key, -5).'%')
            ->get(['p.id', 'p.city_id', 'p.first_name', 'p.father_name', 'p.last_name', 'p.national_number', 'c.code', 'c.name_ar', 'c.name_en'])
            ->filter(fn ($p) => PeopleService::nationalKey((string) $p->national_number) === $key)->values();
        if ($people->isEmpty()) {
            return [];
        }
        $en = app()->getLocale() === 'en';
        $projects = DB::table('project_applicants as a')->join('projects as pr', 'pr.id', '=', 'a.project_id')
            ->leftJoin('project_state_history as h', fn ($j) => $j->on('h.project_id', '=', 'pr.id')->where('h.is_current', true)->whereNull('h.deleted_at'))
            ->leftJoin('project_states as s', 's.id', '=', 'h.project_state_id')
            ->whereNull('a.deleted_at')->whereNull('pr.deleted_at')->whereIn('a.person_id', $people->pluck('id')->all())
            ->orderByDesc('pr.request_date')->get(['a.person_id', 'pr.id', 'pr.project_number', 'pr.application_stage', 's.name_ar', 's.name_en'])->groupBy('person_id');

        return $people->map(fn ($p) => (object) [
            'id' => (int) $p->id, 'name' => PeopleService::fullName($p), 'city' => $en ? $p->name_en : $p->name_ar, 'code' => $p->code,
            'national_number' => $p->national_number, 'readable' => in_array((int) $p->city_id, $readable, true),
            'projects' => ($projects->get($p->id) ?? collect())->map(fn ($r) => (object) [
                'id' => (int) $r->id, 'number' => ProjectNumberAllocator::display($p->code, (int) $r->project_number),
                'state' => $r->name_ar === null ? __('hc.stage_'.$r->application_stage) : ($en && $r->name_en ? $r->name_en : $r->name_ar),
            ])->values()->all(),
        ])->all();
    }

    /** DISC and F2F: one entry per source, `status` = found | none | unavailable | off. */
    private function external(string $key): array
    {
        $config = config('national_check');
        $out = [];
        foreach ($config['sources'] as $name => $source) {
            $entry = ['source' => $name, 'program' => $source['program'], 'city' => $source['city'], 'status' => 'off', 'matches' => []];
            if ($config['enabled']) {
                try {
                    $entry['matches'] = $this->lookup($config['connection'], $source, $key);
                    $entry['status'] = $entry['matches'] === [] ? 'none' : 'found';
                } catch (Throwable $e) {
                    report($e);
                    $entry['status'] = 'unavailable';
                }
            }
            $out[] = $entry;
        }

        return $out;
    }

    /** @return list<object{name: string, state: ?string}> */
    private function lookup(string $connection, array $source, string $key): array
    {
        $db = DB::connection($connection);
        $table = $source['database'].'.'.$source['table'];
        $rows = $db->table($table)->where($source['number'], 'like', '%'.substr($key, -5).'%')->limit(50)
            ->get([$source['id'].' as id', $source['number'].' as number', ...array_map(fn ($c, $i) => $c.' as n'.$i, $source['names'], array_keys($source['names']))])
            ->filter(fn ($r) => PeopleService::nationalKey((string) $r->number) === $key)->values();
        $matches = [];
        foreach ($rows as $row) {
            $state = null;
            if ($source['partner_state'] ?? false) {
                $active = $db->table($source['database'].'.family_partner as fp')->join($source['database'].'.person_family as pf', 'pf.family_id', '=', 'fp.family_id')
                    ->where('pf.person_id', $row->id)->orderByDesc('fp.id')->value('fp.IsActive');
                $state = $active === null ? null : ((int) $active === 1 ? __('national.f2f_active') : __('national.f2f_inactive'));
            }
            $matches[] = (object) ['name' => trim(preg_replace('/\s+/u', ' ', implode(' ', array_map(fn ($i) => (string) $row->{'n'.$i}, array_keys($source['names'])))) ?? ''), 'state' => $state];
        }

        return $matches;
    }
}
