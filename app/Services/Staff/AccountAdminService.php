<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\AccountPasswords;
use App\Services\AccountProvisioner;
use App\Support\InstitutionEmail;
use App\Support\TableTools\TableSearch;
use App\Support\TableTools\TableSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Staff administration of web accounts, city by city (M4.1, unified schema v1.2). An administrator holds `accounts.manage` in some
 * cities and acts only inside them: it sees accounts that hold grants or reviewed links there (and accounts that have neither, so
 * they can be assigned), grants and revokes only the capabilities it holds itself, links desktop users (`legacy_staff_users`) of its
 * own cities after review, and never edits its own grants. Every change goes through {@see AccountProvisioner} / {@see AccessRoles},
 * which record it in the account audit trail with the acting account.
 */
final class AccountAdminService
{
    public const PER_PAGE = 30;

    /** The sortable columns of the accounts list. */
    public const SORT_KEYS = ['username', 'email', 'identities', 'status', 'created'];

    /** Desktop role "Out of Service" (`UserRoles`): such users are never offered for linking. */
    public const OUT_OF_SERVICE = 6;

    public function __construct(
        private AccountAccess $access, private AccountAuditTrail $audit, private AccountProvisioner $provisioner, private AccessRoles $roles,
    ) {}

    /** Cities the administrator manages. */
    public function cities(WebAccount $admin): Collection
    {
        return $this->access->cities($admin, 'accounts.manage');
    }

    /** @return list<int> */
    private function managed(WebAccount $admin): array
    {
        $cities = $this->cities($admin);
        if ($cities->isEmpty()) {
            $this->deny($admin);
        }

        return $cities->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function deny(WebAccount $admin): never
    {
        $this->audit->record($admin->id, 'access.denied', 'denied');
        abort(403);
    }

    /** Capabilities the administrator holds in a city: the only ones it may grant or revoke there. */
    private function grantable(WebAccount $admin, int $city): array
    {
        return array_values(array_intersect(AccountAccess::CAPABILITIES, DB::table('account_city_capabilities')->where('account_id', $admin->id)->where('city_id', $city)
            ->whereNull('revoked_at')->pluck('capability')->all()));
    }

    private function decision(WebAccount $admin): string
    {
        return 'web:admin:'.$admin->id.':'.now('UTC')->format('Ymd\THis');
    }

    /** The accounts an administrator may see: grants or links in its cities, or none at all. */
    private function visible(array $managed): Builder
    {
        $grants = fn (Builder $q) => $q->selectRaw('1')->from('account_city_capabilities as g')->whereColumn('g.account_id', 'a.id')->whereNull('g.revoked_at');
        $links = fn (Builder $q) => $q->selectRaw('1')->from('account_legacy_links as l')->whereColumn('l.account_id', 'a.id');

        return DB::table('web_accounts as a')->where(function (Builder $v) use ($managed, $grants, $links) {
            $v->whereExists(fn (Builder $q) => $grants($q)->whereIn('g.city_id', $managed))
                ->orWhereExists(fn (Builder $q) => $links($q)->whereIn('l.city_id', $managed))
                ->orWhere(fn (Builder $n) => $n->whereNotExists(fn (Builder $q) => $grants($q))->whereNotExists(fn (Builder $q) => $links($q)));
        });
    }

    private function account(int $id, array $managed): object
    {
        $account = $this->visible($managed)->where('a.id', $id)->first(['a.id', 'a.username', 'a.email', 'a.password_hash', 'a.password_changed_at', 'a.credential_legacy_user_id', 'a.disabled_at', 'a.created_at']);
        abort_if($account === null, 404);

        return $account;
    }

    /**
     * @param  array{q?: ?string, city?: ?int, status?: ?string, role?: ?string, sort?: ?string, dir?: ?string}  $filters
     * @return array{rows: LengthAwarePaginator, cities: Collection}
     */
    public function index(WebAccount $admin, array $filters): array
    {
        $managed = $this->managed($admin);
        $cities = $this->cities($admin);
        $this->audit->record($admin->id, 'accounts.list', 'succeeded');
        $query = $this->visible($managed);
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            TableSearch::apply($query, ['a.username', 'a.email'], $search);
        }
        if (in_array($filters['status'] ?? '', ['active', 'disabled'], true)) {
            $filters['status'] === 'disabled' ? $query->whereNotNull('a.disabled_at') : $query->whereNull('a.disabled_at');
        }
        $city = ! empty($filters['city']) && in_array((int) $filters['city'], $managed, true) ? (int) $filters['city'] : null;
        if ($city !== null) {
            $query->where(function (Builder $c) use ($city) {
                $c->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('account_city_capabilities as g2')->whereColumn('g2.account_id', 'a.id')->whereNull('g2.revoked_at')->where('g2.city_id', $city))
                    ->orWhereExists(fn (Builder $q) => $q->selectRaw('1')->from('account_legacy_links as l2')->whereColumn('l2.account_id', 'a.id')->where('l2.city_id', $city));
            });
        }
        $role = (string) ($filters['role'] ?? '');
        if (isset(AccessRoles::ROLES[$role])) {
            $query->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('account_city_roles as r2')->whereColumn('r2.account_id', 'a.id')->whereNull('r2.revoked_at')
                ->where('r2.role', $role)->whereIn('r2.city_id', $city !== null ? [$city] : $managed));
        }
        TableSort::apply($query, [
            'username' => 'a.username', 'email' => "NULLIF(a.email, '')",
            'identities' => DB::table('account_legacy_links as sl')->whereColumn('sl.account_id', 'a.id')->whereIn('sl.city_id', $managed)->whereNull('sl.revoked_at')->selectRaw('COUNT(*)'),
            'status' => 'CASE WHEN a.disabled_at IS NULL THEN 0 ELSE 1 END', 'created' => 'a.created_at',
        ], $filters['sort'] ?? null, $filters['dir'] ?? null);
        $rows = $query->orderBy('a.username')->orderBy('a.id')->paginate(self::PER_PAGE, ['a.id', 'a.username', 'a.email', 'a.disabled_at', 'a.created_at', 'a.credential_legacy_user_id'])->withQueryString();
        $ids = $rows->getCollection()->pluck('id')->all();
        $roles = collect();
        $linked = [];
        if ($ids !== []) {
            $roles = DB::table('account_city_roles')->whereIn('account_id', $ids)->whereIn('city_id', $managed)->whereNull('revoked_at')->orderBy('city_id')->orderBy('role')
                ->get(['account_id', 'city_id', 'role'])->groupBy('account_id');
            foreach (DB::table('account_legacy_links')->whereIn('account_id', $ids)->whereIn('city_id', $managed)->whereNull('revoked_at')
                ->selectRaw('account_id, COUNT(*) as n')->groupBy('account_id')->get() as $row) {
                $linked[(int) $row->account_id] = (int) $row->n;
            }
        }
        $byId = $cities->keyBy('id');
        $rows->setCollection($rows->getCollection()->map(function ($row) use ($roles, $linked, $admin, $byId) {
            $row->username = (string) $row->username;
            $row->roles = ($roles->get($row->id) ?? collect())->map(fn ($r) => (object) ['city' => $byId->get((int) $r->city_id), 'role' => (string) $r->role])->values()->all();
            $row->links = $linked[(int) $row->id] ?? 0;
            $row->self = (int) $row->id === $admin->id;

            return $row;
        }));

        return ['rows' => $rows, 'cities' => $cities];
    }

    /** The account with what the administrator may change: per-city roles and capabilities, e-mail, reviewed identities and candidates to link. */
    public function show(WebAccount $admin, int $accountId): array
    {
        $managed = $this->managed($admin);
        $account = $this->account($accountId, $managed);
        $this->audit->record($admin->id, 'accounts.view', 'succeeded', subjectAccount: $accountId, subjectType: 'account', subjectId: $accountId);
        $cities = $this->cities($admin)->keyBy('id');
        $self = $accountId === $admin->id;
        $active = DB::table('account_city_capabilities')->where('account_id', $accountId)->whereIn('city_id', $managed)->whereNull('revoked_at')->get()->groupBy('city_id');
        $heldRoles = DB::table('account_city_roles')->where('account_id', $accountId)->whereIn('city_id', $managed)->whereNull('revoked_at')->orderBy('role')->get()->groupBy('city_id');
        $panels = [];
        foreach ($managed as $city) {
            $grantable = $this->grantable($admin, $city);
            $have = ($active->get($city) ?? collect())->pluck('capability')->all();
            $panels[] = [
                'city' => $cities->get($city), 'grantable' => $grantable, 'active' => $have, 'fixed' => array_values(array_diff($have, $grantable)),
                'roles' => collect(AccessRoles::ROLES)->filter(fn ($caps) => array_diff($caps, $grantable) === [])->keys()->all(),
                'held' => ($heldRoles->get($city) ?? collect())->pluck('role')->all(),
            ];
        }
        $links = DB::table('account_legacy_links as l')->join('legacy_staff_users as u', 'u.id', '=', 'l.legacy_staff_user_id')
            ->where('l.account_id', $accountId)->whereIn('l.city_id', $managed)->orderBy('l.city_id')->orderBy('l.id')
            ->get(['l.id', 'l.city_id', 'l.legacy_staff_user_id', 'l.reviewed_at', 'l.revoked_at', 'u.username', 'u.legacy_user_id', 'u.legacy_role_id', 'u.disabled_at as user_disabled_at'])
            ->map(function ($link) use ($account, $cities) {
                $link->city = $cities->get((int) $link->city_id);
                $link->username = (string) $link->username;
                $link->role = $link->legacy_role_id === null ? null : (int) $link->legacy_role_id;
                $link->credential = (int) $account->credential_legacy_user_id === (int) $link->legacy_staff_user_id && $account->password_hash === null;

                return $link;
            });
        $names = $links->pluck('username')->push((string) $account->username)->all();
        $otherLinks = DB::table('account_legacy_links')->where('account_id', $accountId)->whereNotIn('city_id', $managed)->whereNull('revoked_at')->count();
        $account->username = (string) $account->username;
        $account->has_password = $account->password_hash !== null;
        $account->must_change = $account->password_hash !== null && $account->password_changed_at === null;
        unset($account->password_hash);

        return [
            'account' => $account, 'self' => $self, 'panels' => $panels, 'links' => $links, 'otherLinks' => $otherLinks, 'canEmail' => ! $self && $this->outranks($admin, $accountId, $managed),
            'candidates' => $self ? collect() : $this->unlinked($managed, $cities)->map(function ($user) use ($names) {
                $user->same = in_array($user->username, $names, true);

                return $user;
            })->sortBy([['same', 'desc'], ['username', 'asc']])->values(),
            'canDisable' => ! $self && $this->disablable($accountId, $managed),
        ];
    }

    /**
     * Sets one city's capabilities to exactly the submitted list, within what the administrator holds. Capabilities it does not hold are left
     * untouched. Returns the number granted and revoked.
     *
     * @param  list<string>  $capabilities
     * @return array{granted: int, revoked: int}
     */
    public function saveCapabilities(WebAccount $admin, int $accountId, int $city, array $capabilities): array
    {
        $this->guardCity($admin, $accountId, $city);
        $grantable = $this->grantable($admin, $city);
        $submitted = array_values(array_unique($capabilities));
        if (array_diff($submitted, $grantable) !== []) {
            throw ValidationException::withMessages(['capabilities' => __('admin.errors.capability')]);
        }
        $decision = $this->decision($admin);
        $account = WebAccount::findOrFail($accountId);

        return DB::transaction(function () use ($admin, $account, $city, $submitted, $grantable, $decision) {
            $active = DB::table('account_city_capabilities')->where('account_id', $account->id)->where('city_id', $city)->whereNull('revoked_at')->pluck('capability')->all();
            $grant = array_values(array_diff($submitted, $active));
            $revoke = array_values(array_diff(array_intersect($active, $grantable), $submitted));
            foreach ($grant as $capability) {
                $this->provisioner->grant($account, $city, $capability, $decision, $admin->id);
            }
            foreach ($revoke as $capability) {
                $this->provisioner->revoke($account, $city, $capability, $decision, $admin->id);
            }

            return ['granted' => count($grant), 'revoked' => count($revoke)];
        });
    }

    /** Assigns a named role in one city: its capabilities are granted (each still audited) and the assignment routes the city's notices to the account. */
    public function assignRole(WebAccount $admin, int $accountId, int $city, string $role): int
    {
        $this->guardCity($admin, $accountId, $city);
        $capabilities = $this->roleWithin($admin, $city, $role);
        $decision = $this->decision($admin);
        $this->roles->assign(WebAccount::findOrFail($accountId), $city, $role, $decision, $admin->id);
        $this->audit->record($admin->id, 'accounts.role_assigned', 'succeeded', $city, $accountId, 'account', $accountId, $decision);

        return count($capabilities);
    }

    /** Ends a role in one city and revokes the capabilities no other role of the account there still includes. Returns how many were revoked. */
    public function revokeRole(WebAccount $admin, int $accountId, int $city, string $role): int
    {
        $this->guardCity($admin, $accountId, $city);
        $this->roleWithin($admin, $city, $role);
        $decision = $this->decision($admin);
        try {
            $revoked = $this->roles->revoke(WebAccount::findOrFail($accountId), $city, $role, $decision, $admin->id);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['role' => __('admin.errors.role_not_held')]);
        }
        $this->audit->record($admin->id, 'accounts.role_revoked', 'succeeded', $city, $accountId, 'account', $accountId, $decision);

        return $revoked;
    }

    /** A managed city, a visible account that is not the administrator's own. */
    private function guardCity(WebAccount $admin, int $accountId, int $city): void
    {
        $managed = $this->managed($admin);
        in_array($city, $managed, true) || $this->deny($admin);
        $this->account($accountId, $managed);
        $accountId !== $admin->id || $this->deny($admin);
    }

    /** @return list<string> the role's capabilities, when the administrator holds all of them in the city */
    private function roleWithin(WebAccount $admin, int $city, string $role): array
    {
        $capabilities = AccessRoles::ROLES[$role] ?? throw ValidationException::withMessages(['role' => __('admin.errors.role')]);
        if (array_diff($capabilities, $this->grantable($admin, $city)) !== []) {
            throw ValidationException::withMessages(['role' => __('admin.errors.role_beyond')]);
        }

        return $capabilities;
    }

    /** Links one more reviewed desktop user of a managed city to an existing account. */
    public function link(WebAccount $admin, int $accountId, int $legacyUserId): void
    {
        $managed = $this->managed($admin);
        $this->account($accountId, $managed);
        $this->linkable($admin, $legacyUserId, $managed);
        try {
            $this->provisioner->linkLegacyUser(WebAccount::findOrFail($accountId), $legacyUserId, $this->decision($admin), $admin->id);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['identity' => __('admin.errors.identity')]);
        }
    }

    /** Revokes or restores a reviewed link. Revoking the credential of an account that has no password of its own is refused (lockout). */
    public function setLinkRevoked(WebAccount $admin, int $accountId, int $linkId, bool $revoked): void
    {
        $managed = $this->managed($admin);
        $account = $this->account($accountId, $managed);
        $link = DB::table('account_legacy_links')->where('id', $linkId)->where('account_id', $accountId)->first();
        abort_if($link === null, 404);
        in_array((int) $link->city_id, $managed, true) || $this->deny($admin);
        if ($revoked && $account->password_hash === null && (int) $account->credential_legacy_user_id === (int) $link->legacy_staff_user_id) {
            throw ValidationException::withMessages(['link' => __('admin.errors.credential_link')]);
        }
        try {
            $this->provisioner->setLinkRevoked(WebAccount::findOrFail($accountId), (int) $link->legacy_staff_user_id, $revoked, $this->decision($admin), $admin->id);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['link' => __('admin.errors.identity')]);
        }
    }

    /** Disables or re-enables the whole account. Needs every city the account is involved in, and never one's own account. */
    public function setDisabled(WebAccount $admin, int $accountId, bool $disabled): void
    {
        $managed = $this->managed($admin);
        $this->account($accountId, $managed);
        if ($accountId === $admin->id || ! $this->disablable($accountId, $managed)) {
            $this->deny($admin);
        }
        $this->provisioner->setDisabled(WebAccount::findOrFail($accountId), $disabled, $admin->id, $this->decision($admin));
    }

    /** Whether every city the account is active in (grants or links) is managed by the administrator. */
    private function disablable(int $accountId, array $managed): bool
    {
        $cities = DB::table('account_city_capabilities')->where('account_id', $accountId)->whereNull('revoked_at')->pluck('city_id')
            ->merge(DB::table('account_legacy_links')->where('account_id', $accountId)->whereNull('revoked_at')->pluck('city_id'))
            ->map(fn ($id) => (int) $id)->unique();

        return $cities->diff($managed)->isEmpty();
    }

    /** A desktop user of a managed city, enabled, in service and not linked to any account yet. */
    private function linkable(WebAccount $admin, int $legacyUserId, array $managed): object
    {
        $user = DB::table('legacy_staff_users')->where('id', $legacyUserId)->first();
        $user !== null || throw ValidationException::withMessages(['identities' => __('admin.errors.identity')]);
        in_array((int) $user->city_id, $managed, true) || $this->deny($admin);
        if ($user->disabled_at !== null || (int) $user->legacy_role_id === self::OUT_OF_SERVICE
            || DB::table('account_legacy_links')->where('legacy_staff_user_id', $legacyUserId)->exists()) {
            throw ValidationException::withMessages(['identities' => __('admin.errors.identity')]);
        }

        return $user;
    }

    /**
     * Desktop users of the managed cities that no account is linked to yet, for linking and for the duplicate review.
     *
     * @return Collection<int, object{id: int, city: int, city_name: ?object, legacy_user_id: int, username: string, role: ?int, flags: list<string>, credential_ok: bool}>
     */
    private function unlinked(array $managed, Collection $cities): Collection
    {
        return DB::table('legacy_staff_users as u')->whereIn('u.city_id', $managed)->whereNull('u.disabled_at')
            ->where(fn (Builder $q) => $q->whereNull('u.legacy_role_id')->orWhere('u.legacy_role_id', '<>', self::OUT_OF_SERVICE))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('account_legacy_links as l')->whereColumn('l.legacy_staff_user_id', 'u.id'))
            ->orderBy('u.username')->orderBy('u.city_id')->orderBy('u.legacy_user_id')
            ->get(['u.id', 'u.city_id', 'u.legacy_user_id', 'u.username', 'u.email', 'u.legacy_role_id', 'u.is_visitor', 'u.is_me', 'u.is_communication', 'u.is_cashier', 'u.password_hash'])
            ->map(fn ($user) => (object) [
                'id' => (int) $user->id, 'city' => (int) $user->city_id, 'city_name' => $cities->get((int) $user->city_id), 'legacy_user_id' => (int) $user->legacy_user_id,
                'username' => (string) $user->username, 'email' => $user->email, 'role' => $user->legacy_role_id === null ? null : (int) $user->legacy_role_id,
                'flags' => array_keys(array_filter(['visitor' => (bool) $user->is_visitor, 'me' => (bool) $user->is_me, 'communication' => (bool) $user->is_communication, 'cashier' => (bool) $user->is_cashier])),
                'credential_ok' => AccountProvisioner::compatibleLegacyHash($user->password_hash),
            ]);
    }

    /** Unlinked desktop users grouped by exact username: the duplicate review before an account is created. */
    public function candidates(WebAccount $admin): Collection
    {
        $managed = $this->managed($admin);

        return $this->unlinked($managed, $this->cities($admin)->keyBy('id'))->groupBy('username')
            ->map(fn ($group, $username) => (object) ['username' => (string) $username, 'identities' => $group->values()])->sortKeys()->values();
    }

    /**
     * Creates an account from reviewed desktop users of the managed cities. `identities`: `legacy_staff_users` ids the administrator
     * confirmed to be one person; `credential`: the one whose desktop password the account inherits; `roles`: optional role per city;
     * `email`: optional (otherwise the credential user's e-mail when it is valid and unused).
     *
     * @param  array{identities?: list<int|string>, credential?: int|string|null, username?: ?string, email?: ?string, confirm?: mixed, roles?: array<int|string, ?string>}  $input
     */
    public function create(WebAccount $admin, array $input): int
    {
        $managed = $this->managed($admin);
        $fail = fn (string $field, string $key): never => throw ValidationException::withMessages([$field => __('admin.errors.'.$key)]);
        ! empty($input['confirm']) || $fail('confirm', 'confirm');
        $ids = array_values(array_unique(array_map('intval', (array) ($input['identities'] ?? []))));
        ($ids !== [] && min($ids) > 0) || $fail('identities', 'identity');
        $credential = (int) ($input['credential'] ?? 0);
        in_array($credential, $ids, true) || $fail('credential', 'credential');
        $users = [];
        foreach ($ids as $id) {
            $users[$id] = $this->linkable($admin, $id, $managed);
        }
        AccountProvisioner::compatibleLegacyHash($users[$credential]->password_hash) || $fail('credential', 'credential_hash');
        $username = trim((string) ($input['username'] ?? '')) === '' ? (string) $users[$credential]->username : (string) $input['username'];
        $this->validUsername($username) || $fail('username', 'username');
        DB::table('web_accounts')->where('username', $username)->doesntExist() || $fail('username', 'username_taken');
        $typed = trim((string) ($input['email'] ?? ''));
        if ($typed !== '') {
            $email = $this->normalizeEmail($typed) ?? $fail('email', 'email');
            DB::table('web_accounts')->where('email', $email)->doesntExist() || $fail('email', 'email_taken');
        } else {
            $email = $this->normalizeEmail((string) ($users[$credential]->email ?? ''));
            $email !== null && DB::table('web_accounts')->where('email', $email)->exists() && $email = null;
        }
        $assign = [];
        foreach (array_unique(array_map(fn ($u) => (int) $u->city_id, $users)) as $city) {
            $role = (string) (((array) ($input['roles'] ?? []))[$city] ?? '');
            if ($role !== '') {
                $this->roleWithin($admin, $city, $role);
                $assign[$city] = $role;
            }
        }
        $decision = $this->decision($admin);

        try {
            return (int) DB::transaction(function () use ($admin, $ids, $credential, $username, $email, $assign, $decision) {
                $account = $this->provisioner->provision($username, $credential, $ids, [], $decision, $admin->id);
                if ($email !== null) {
                    DB::table('web_accounts')->where('id', $account->id)->update(['email' => $email]);
                }
                foreach ($assign as $city => $role) {
                    $this->roles->assign($account, $city, $role, $decision, $admin->id);
                }

                return $account->id;
            });
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['identities' => __('admin.errors.identity')]);
        }
    }

    /**
     * Creates an account that has no desktop identity: the administrator gives a user name, an e-mail (used by "forgot password") and a role
     * for at least one managed city, and hands over the generated one-time password, shown once; the user must replace it at first login.
     *
     * @param  array{username?: ?string, email?: ?string, roles?: array<int|string, ?string>}  $input
     * @return array{id: int, password: string}
     */
    public function createPlain(WebAccount $admin, array $input): array
    {
        $managed = $this->managed($admin);
        $fail = fn (string $field, string $key): never => throw ValidationException::withMessages([$field => __('admin.errors.'.$key)]);
        $username = (string) ($input['username'] ?? '');
        ($username !== '' && $this->validUsername($username)) || $fail('username', 'username');
        DB::table('web_accounts')->where('username', $username)->doesntExist() || $fail('username', 'username_taken');
        $email = $this->normalizeEmail((string) ($input['email'] ?? '')) ?? $fail('email', 'email');
        DB::table('web_accounts')->where('email', $email)->doesntExist() || $fail('email', 'email_taken');
        $assign = [];
        foreach ((array) ($input['roles'] ?? []) as $city => $role) {
            $role = (string) $role;
            if ($role === '') {
                continue;
            }
            in_array((int) $city, $managed, true) || $this->deny($admin);
            $this->roleWithin($admin, (int) $city, $role);
            $assign[(int) $city] = $role;
        }
        $assign !== [] || $fail('roles', 'role_needed');
        $password = AccountPasswords::generate();
        $decision = $this->decision($admin);

        return DB::transaction(function () use ($admin, $username, $email, $password, $assign, $decision) {
            $account = $this->provisioner->createPlain($username, $email, Hash::make($password), $decision, $admin->id);
            foreach ($assign as $city => $role) {
                $this->roles->assign($account, $city, $role, $decision, $admin->id);
            }

            return ['id' => (int) $account->id, 'password' => $password];
        });
    }

    /** Sets or clears the e-mail "forgot password" checks. Only for accounts whose every capability the administrator also holds. */
    public function setEmail(WebAccount $admin, int $accountId, ?string $email): void
    {
        $managed = $this->managed($admin);
        $this->account($accountId, $managed);
        if ($accountId === $admin->id || ! $this->outranks($admin, $accountId, $managed)) {
            $this->deny($admin);
        }
        $value = trim((string) $email) === '' ? null : ($this->normalizeEmail((string) $email) ?? throw ValidationException::withMessages(['email' => __('admin.errors.email')]));
        if ($value !== null && DB::table('web_accounts')->where('email', $value)->where('id', '<>', $accountId)->exists()) {
            throw ValidationException::withMessages(['email' => __('admin.errors.email_taken')]);
        }
        $old = DB::table('web_accounts')->where('id', $accountId)->value('email');
        DB::table('web_accounts')->where('id', $accountId)->update(['email' => $value, 'updated_at' => now('UTC')->format('Y-m-d H:i:s.u')]);
        $this->audit->record($admin->id, 'accounts.email_set', 'succeeded', subjectAccount: $accountId, subjectType: 'account', subjectId: $accountId,
            details: AccountAuditTrail::changes(['email' => $old], ['email' => $value]));
    }

    private function validUsername(string $username): bool
    {
        return $username !== '' && mb_strlen($username) <= 200 && strlen($username) <= 800 && $username === trim($username) && preg_match('//u', $username) === 1;
    }

    /** A lower-cased valid e-mail address of at most 190 characters, or null. */
    /** Accounts sign in with their institution e-mail (21 Nov 2026), so only an address of that domain is accepted. */
    private function normalizeEmail(string $value): ?string
    {
        return InstitutionEmail::normalize($value);
    }

    /** Whether every capability the account holds, in every city, is one the administrator holds there too (so it cannot take the account over). */
    private function outranks(WebAccount $admin, int $accountId, array $managed): bool
    {
        $held = DB::table('account_city_capabilities')->where('account_id', $accountId)->whereNull('revoked_at')->get()->groupBy('city_id');
        foreach ($held as $city => $rows) {
            if (! in_array((int) $city, $managed, true) || array_diff($rows->pluck('capability')->all(), $this->grantable($admin, (int) $city)) !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * The staff directory: per city, the web accounts with their roles and task count, and the desktop users of that city with
     * the account each one is linked to (or none yet).
     */
    public function staff(WebAccount $admin, array $filters): array
    {
        $managed = $this->managed($admin);
        $cities = $this->cities($admin);
        $selected = ! empty($filters['city']) && in_array((int) $filters['city'], $managed, true) ? [(int) $filters['city']] : $managed;
        $groups = [];
        foreach ($selected as $city) {
            $accounts = DB::table('web_accounts as a')->where(function (Builder $q) use ($city) {
                $q->whereExists(fn (Builder $r) => $r->selectRaw('1')->from('account_city_roles as r')->whereColumn('r.account_id', 'a.id')->where('r.city_id', $city)->whereNull('r.revoked_at'))
                    ->orWhereExists(fn (Builder $g) => $g->selectRaw('1')->from('account_city_capabilities as g')->whereColumn('g.account_id', 'a.id')->where('g.city_id', $city)->whereNull('g.revoked_at'));
            })->orderBy('a.username')->get(['a.id', 'a.username', 'a.email', 'a.disabled_at']);
            $ids = $accounts->pluck('id')->all();
            $roles = DB::table('account_city_roles')->where('city_id', $city)->whereNull('revoked_at')->whereIn('account_id', $ids)->orderBy('role')->get()->groupBy('account_id');
            $tasks = DB::table('account_city_tasks')->where('city_id', $city)->whereNull('revoked_at')->whereIn('account_id', $ids)->selectRaw('account_id, COUNT(*) as n')->groupBy('account_id')->pluck('n', 'account_id');
            $desktop = DB::table('legacy_staff_users as u')->leftJoin('account_legacy_links as l', 'l.legacy_staff_user_id', '=', 'u.id')->leftJoin('web_accounts as a', 'a.id', '=', 'l.account_id')
                ->where('u.city_id', $city)->orderBy('u.username')
                ->get(['u.id', 'u.username', 'u.legacy_user_id', 'u.legacy_role_id', 'u.is_visitor', 'u.is_me', 'u.is_communication', 'u.is_cashier', 'u.disabled_at', 'l.revoked_at as link_revoked_at', 'a.id as account_id', 'a.username as account_username']);
            $groups[] = [
                'city' => $cities->firstWhere('id', $city),
                'users' => $accounts->map(fn ($row) => (object) [
                    'id' => (int) $row->id, 'username' => (string) $row->username, 'email' => $row->email, 'disabled' => $row->disabled_at !== null,
                    'roles' => ($roles->get($row->id) ?? collect())->pluck('role')->all(), 'tasks' => (int) ($tasks[$row->id] ?? 0),
                ]),
                'desktop' => $desktop->map(fn ($u) => (object) [
                    'id' => (int) $u->id, 'username' => (string) $u->username, 'legacy_user_id' => (int) $u->legacy_user_id, 'role' => $u->legacy_role_id === null ? null : (int) $u->legacy_role_id,
                    'flags' => array_keys(array_filter(['visitor' => (bool) $u->is_visitor, 'me' => (bool) $u->is_me, 'communication' => (bool) $u->is_communication, 'cashier' => (bool) $u->is_cashier])),
                    'disabled' => $u->disabled_at !== null, 'account_id' => $u->account_id === null ? null : (int) $u->account_id,
                    'account' => $u->account_username === null ? null : (string) $u->account_username, 'link_revoked' => $u->link_revoked_at !== null,
                ]),
            ];
        }
        $this->audit->record($admin->id, 'accounts.list', 'succeeded');

        return ['cities' => $cities, 'groups' => $groups];
    }

    /**
     * The task responsibility matrix of one city: the active task definitions, the enabled accounts that may manage tasks there and who
     * is responsible for each task now.
     *
     * @return array{city: object, cities: Collection, tasks: list<object>, accounts: Collection}
     */
    public function taskMatrix(WebAccount $admin, int $city): array
    {
        $managed = $this->managed($admin);
        in_array($city, $managed, true) || $this->deny($admin);
        $current = DB::table('account_city_tasks')->where('city_id', $city)->whereNull('revoked_at')->get()->groupBy('task_definition_id');
        $tasks = $this->tasks()->map(function ($task) use ($current) {
            $task->accounts = ($current->get($task->id) ?? collect())->pluck('account_id')->map(fn ($id) => (int) $id)->all();

            return $task;
        })->values()->all();
        $this->audit->record($admin->id, 'accounts.list', 'succeeded');

        return ['city' => $this->cities($admin)->firstWhere('id', $city), 'cities' => $this->cities($admin), 'tasks' => $tasks, 'accounts' => $this->taskAccounts($city)];
    }

    /**
     * Sets who is responsible for each task of the city to exactly the submitted lists (task id → account ids). Tasks left out of the
     * submission lose their assignments. Returns how many assignments were added and withdrawn.
     *
     * @param  array<int|string, list<int|string>>  $assign
     * @return array{added: int, withdrawn: int}
     */
    public function saveTasks(WebAccount $admin, int $city, array $assign): array
    {
        $managed = $this->managed($admin);
        in_array($city, $managed, true) || $this->deny($admin);
        $tasks = $this->tasks()->keyBy('id');
        $accounts = $this->taskAccounts($city)->pluck('id')->all();
        foreach ($assign as $taskId => $list) {
            $tasks->has((int) $taskId) || throw ValidationException::withMessages(['tasks' => __('admin.errors.task')]);
            array_diff(array_map('intval', (array) $list), $accounts) === [] || throw ValidationException::withMessages(['tasks' => __('admin.errors.task_account')]);
        }
        $desired = [];
        foreach ($tasks->keys() as $taskId) {
            $desired[(int) $taskId] = array_values(array_unique(array_map('intval', (array) ($assign[$taskId] ?? []))));
        }

        return DB::transaction(function () use ($admin, $city, $desired) {
            $time = now('UTC')->format('Y-m-d H:i:s.u');
            $added = 0;
            $withdrawn = 0;
            foreach ($desired as $taskId => $accountIds) {
                $rows = DB::table('account_city_tasks')->where('city_id', $city)->where('task_definition_id', $taskId)->lockForUpdate()->get()->keyBy('account_id');
                foreach ($accountIds as $accountId) {
                    $row = $rows->get($accountId);
                    if ($row === null) {
                        DB::table('account_city_tasks')->insert(['account_id' => $accountId, 'city_id' => $city, 'task_definition_id' => $taskId, 'assigned_at' => $time, 'revoked_at' => null]);
                        $added++;
                    } elseif ($row->revoked_at !== null) {
                        DB::table('account_city_tasks')->where('id', $row->id)->update(['assigned_at' => $time, 'revoked_at' => null]);
                        $added++;
                    }
                }
                foreach ($rows as $accountId => $row) {
                    if ($row->revoked_at === null && ! in_array((int) $accountId, $accountIds, true)) {
                        DB::table('account_city_tasks')->where('id', $row->id)->update(['revoked_at' => $time]);
                        $withdrawn++;
                    }
                }
            }
            $this->audit->record($admin->id, 'accounts.tasks_saved', 'succeeded', $city);

            return ['added' => $added, 'withdrawn' => $withdrawn];
        });
    }

    /** Active, named task definitions (shared by every city), financed-project tasks first, in catalog order. */
    private function tasks(): Collection
    {
        return DB::table('task_definitions')->where('is_active', true)->where('name_ar', '<>', '')
            ->orderByRaw("CASE WHEN type = 'f' THEN 0 WHEN type = 'a' THEN 1 ELSE 2 END")->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name_ar', 'name_en', 'type'])->map(fn ($t) => (object) ['id' => (int) $t->id, 'name_ar' => (string) $t->name_ar, 'name_en' => $t->name_en, 'type' => (string) ($t->type ?? '')]);
    }

    /** Enabled accounts that hold `tasks.manage` in the city: the only ones a task can be assigned to. */
    private function taskAccounts(int $city): Collection
    {
        return DB::table('web_accounts as a')->join('account_city_capabilities as g', 'g.account_id', '=', 'a.id')->where('g.city_id', $city)->where('g.capability', 'tasks.manage')
            ->whereNull('g.revoked_at')->whereNull('a.disabled_at')->orderBy('a.username')->get(['a.id', 'a.username'])
            ->map(fn ($row) => (object) ['id' => (int) $row->id, 'username' => (string) $row->username])->values();
    }
}
