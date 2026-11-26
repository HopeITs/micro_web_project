<?php

declare(strict_types=1);

namespace App\Services\Staff;

use App\Mail\StaffMessage;
use App\Models\WebAccount;
use App\Services\AccessRoles;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Services\Finance\Fields;
use App\Services\ProjectNumberAllocator;
use App\Support\TableTools\TableSearch;
use App\Support\TableTools\TableSort;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * The notification box (`Notification_Box`, `SendMessage_Box`) of a web account: the unseen notices addressed to it in the cities where it
 * holds `notifications.view`. As in the desktop box, a financial-role account reads its notices up to today and every other account up
 * to three days ahead; the bell count is the size of the same list.
 */
final class NotificationInboxService
{
    public const PER_PAGE = 50;

    public const AHEAD_DAYS = 3;

    /** The sortable columns of the box. */
    public const SORT_KEYS = ['project', 'kind', 'title', 'date', 'sender'];

    public function __construct(private AccessRoles $roles, private AccountAccess $access, private AccountAuditTrail $audit, private NoticeService $notices) {}

    /** Cities where the account may read its notices. */
    public function cities(WebAccount $account): Collection
    {
        return $this->access->cities($account, 'notifications.view');
    }

    /** Cities where the account may send messages. */
    public function sendCities(WebAccount $account): Collection
    {
        return $this->access->cities($account, 'notifications.send');
    }

    /** Number of unseen notices in the account's box (0 without the permission). */
    public function count(WebAccount $account): int
    {
        $cities = $this->cities($account);

        return $cities->isEmpty() ? 0 : $this->query($account, $cities->pluck('id')->map(fn ($id) => (int) $id)->all())->count();
    }

    /**
     * @param  array{cities?: list<int>, kind?: ?string, q?: ?string, sort?: ?string, dir?: ?string}  $filters
     * @return array{rows: LengthAwarePaginator, cities: Collection}
     */
    public function inbox(WebAccount $account, array $filters): array
    {
        [$permitted, $ids] = $this->scope($account, $filters, 'notifications.list');
        $rows = $this->listed($account, $ids, $filters)->paginate(self::PER_PAGE)->withQueryString();
        $senders = DB::table('web_accounts')->whereIn('id', $rows->getCollection()->pluck('sender_account_id')->filter()->unique()->all())->pluck('username', 'id');
        $capabilities = [];
        foreach ($ids as $city) {
            $view = $this->access->check($account, $city, 'applications.view');
            $capabilities[$city] = ['finance' => $view && $this->access->check($account, $city, 'finance.view'), 'visits' => $view && $this->access->check($account, $city, 'visits.view')];
        }
        $rows->setCollection($rows->getCollection()->map(function ($row) use ($senders, $capabilities) {
            $row->sender = $row->sender_account_id === null ? null : (string) ($senders[(int) $row->sender_account_id] ?? '');
            $row->links = $capabilities[(int) $row->city_id];
            $row->amount = $row->kind === NoticeService::PAYMENT && preg_match('/\A[0-9]+\z/', (string) $row->body) === 1 && (int) $row->body !== 0 ? number_format((int) $row->body) : null;

            return $row;
        }));

        return ['rows' => $rows, 'cities' => $permitted];
    }

    /**
     * The permitted cities, and the ones the request narrows to (an unpermitted requested city is ignored); a refusal is audited.
     *
     * @return array{0: Collection, 1: list<int>}
     */
    private function scope(WebAccount $account, array $filters, string $event): array
    {
        $permitted = $this->cities($account);
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $selected = array_values(array_intersect($filters['cities'] ?? [], $permitted->pluck('id')->all()));
        $ids = array_map('intval', $selected !== [] ? $selected : $permitted->pluck('id')->all());
        $this->audit->record($account->id, $event, 'succeeded');

        return [$permitted, $ids];
    }

    /**
     * Everything the account's box shows (every page) for the Excel/CSV export, in the box's order: one line per notice with its sender.
     * Authorization and the audit record happen here, before the first byte is sent.
     *
     * @return \Generator<int, list<string|int|null>>
     */
    public function exportRows(WebAccount $account, array $filters): \Generator
    {
        [, $ids] = $this->scope($account, $filters, 'reports.exported');
        $query = $this->listed($account, $ids, $filters);

        return (function () use ($query) {
            foreach ($query->lazy(500)->chunk(500) as $chunk) {
                $senders = DB::table('web_accounts')->whereIn('id', $chunk->pluck('sender_account_id')->filter()->unique()->all())->pluck('username', 'id');
                foreach ($chunk as $row) {
                    yield [
                        $row->city_name_en, substr((string) $row->notice_date, 0, 10), $row->kind, $row->project_number, $row->title, $row->body,
                        $row->sender_account_id === null ? '' : (string) ($senders[(int) $row->sender_account_id] ?? ''),
                    ];
                }
            }
        })();
    }

    /**
     * Marks the chosen notices of the account's own box seen. Only notices that are in the box are touched. The other copies of the same
     * notice are cleared with them, as on the desktop; a payment notice clears every notice of that payment.
     *
     * @param  list<int>  $ids
     */
    public function markSeen(WebAccount $account, array $ids): int
    {
        $cities = $this->cities($account);
        if ($cities->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }

        return DB::transaction(function () use ($account, $cities, $ids) {
            $rows = $this->query($account, $cities->pluck('id')->map(fn ($id) => (int) $id)->all())->whereIn('n.id', array_values(array_unique($ids)))->get();
            foreach ($rows as $row) {
                $row->kind === NoticeService::PAYMENT ? $this->notices->setSeenForPayment($row, 1) : $this->notices->setSeenLike($row, 1);
            }
            $this->audit->record($account->id, 'notifications.seen', 'succeeded');

            return $rows->count();
        });
    }

    /**
     * Accounts a message to a city can address: enabled accounts that may read notifications there.
     *
     * @return Collection<int, object{id: int, username: string}>
     */
    public function recipients(WebAccount $account, int $city): Collection
    {
        abort_unless($this->access->check($account, $city, 'notifications.send'), 403);

        return DB::table('web_accounts as a')->join('account_city_capabilities as g', 'g.account_id', '=', 'a.id')->where('g.city_id', $city)->where('g.capability', 'notifications.view')
            ->whereNull('g.revoked_at')->whereNull('a.disabled_at')->orderBy('a.username')->get(['a.id', 'a.username'])
            ->map(fn ($row) => (object) ['id' => (int) $row->id, 'username' => (string) $row->username])->values();
    }

    /**
     * Sends a message to one account or to everyone who reads notifications in a city. `project` (a project number of the city) is for
     * administrators (`accounts.manage`), as on the desktop. The sender is the acting account.
     *
     * @param  array{city: int, to: string, date?: ?string, title?: ?string, body?: ?string, project?: ?string, email_copy?: mixed}  $input
     * @return array{sent: int, emailed: int, failed: int} notices stored, and the e-mail copies sent or that could not be delivered (never blocks the notice)
     */
    public function send(WebAccount $account, array $input): array
    {
        $city = (int) ($input['city'] ?? 0);
        if (! $this->access->check($account, $city, 'notifications.send')) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }

        $mail = [];
        $count = DB::transaction(function () use ($account, $input, $city, &$mail) {
            $fail = fn (string $field, string $key): never => throw ValidationException::withMessages([$field => __('staff.notifications.errors.'.$key)]);
            $body = Fields::text($input['body'] ?? '');
            (trim($body) !== '' && strlen($body) <= 16777215) || $fail('body', 'body');
            $title = Fields::text($input['title'] ?? '');
            strlen($title) <= 255 || $fail('title', 'title');
            $date = trim(Fields::text($input['date'] ?? ''));
            $date = $date === '' ? now()->toDateString() : $date;
            Fields::isDate($date) || $fail('date', 'date');
            $recipients = $this->recipients($account, $city);
            $to = (string) ($input['to'] ?? '');
            if ($to !== 'all') {
                $recipients = $recipients->where('id', (int) $to)->values();
                ctype_digit($to) && $recipients->isNotEmpty() || $fail('to', 'to');
            }
            $recipients->isNotEmpty() || $fail('to', 'to');
            $project = null;
            if (! Fields::blank($input['project'] ?? null)) {
                $this->access->check($account, $city, 'accounts.manage') || $fail('project', 'project_admin');
                ctype_digit(trim((string) $input['project'])) || $fail('project', 'project');
                $project = DB::table('projects')->where('city_id', $city)->where('project_number', (int) trim((string) $input['project']))->whereNull('deleted_at')->first();
                $project !== null || $fail('project', 'project');
            }
            foreach ($recipients as $recipient) {
                $this->notices->add($city, 0, $project, NoticeService::MESSAGE, $date, $title, $body, $recipient->id, $account->id);
            }
            $this->audit->record($account->id, 'notifications.sent', 'succeeded', $city);
            $mail = ['recipients' => $recipients->pluck('id')->all(), 'title' => $title, 'body' => $body, 'project' => $project];

            return $recipients->count();
        });

        $emailed = $failed = 0;
        if (! empty($input['email_copy']) && $mail !== []) {
            $city_name = (string) DB::table('cities')->where('id', $city)->value(app()->isLocale('en') ? 'name_en' : 'name_ar');
            $ref = $mail['project'] === null ? null : ProjectNumberAllocator::display((string) DB::table('cities')->where('id', $city)->value('code'), (int) $mail['project']->project_number);
            foreach (DB::table('web_accounts')->whereIn('id', $mail['recipients'])->whereNotNull('email')->where('email', '<>', '')->get(['email']) as $to) {
                try {
                    Mail::to($to->email)->send(new StaffMessage($account->username, $city_name, $mail['title'], $mail['body'], $ref, app()->getLocale()));
                    $emailed++;
                } catch (\Throwable $e) {
                    report($e);
                    $failed++;
                }
            }
        }

        return ['sent' => $count, 'emailed' => $emailed, 'failed' => $failed];
    }

    /** The box as the screen and its file show it: kind, search and sort applied, newest first by default. */
    private function listed(WebAccount $account, array $ids, array $filters): Builder
    {
        $query = $this->withKind($this->query($account, $ids), $filters);
        $this->search($query, (string) ($filters['q'] ?? ''));
        TableSort::apply($query, [
            'project' => ['c.code', 'n.project_number'], 'kind' => 'n.kind', 'title' => "NULLIF(n.title, '')", 'date' => 'n.notice_date',
            'sender' => DB::table('web_accounts as sa')->whereColumn('sa.id', 'n.sender_account_id')->select('sa.username')->limit(1),
        ], $filters['sort'] ?? null, $filters['dir'] ?? null);

        return $query->orderByDesc('n.notice_date')->orderByDesc('n.id');
    }

    /** A project number ("DAM-12"), or every word in the subject, the message, the sender or a project number. */
    private function search(Builder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '') {
            return;
        }
        $number = ProjectNumberAllocator::parse($term);
        if ($number !== null && $number[0] !== null) {
            $query->where('c.code', $number[0])->where('n.project_number', $number[1]);

            return;
        }
        TableSearch::apply($query, ['n.title', 'n.body'], $term, function (Builder $any, string $word) {
            ctype_digit($word) && $any->orWhere('n.project_number', (int) $word);
            $any->orWhereExists(function (Builder $sender) use ($word) {
                $sender->selectRaw('1')->from('web_accounts as qs')->whereColumn('qs.id', 'n.sender_account_id')
                    ->where(fn (Builder $name) => TableSearch::orLike($name, 'qs.username', $word));
            });
        });
    }

    /** Narrows the box to one kind of notice (message, visit, payment, ...) when the filter asks for it. */
    private function withKind(Builder $query, array $filters): Builder
    {
        return ! empty($filters['kind']) ? $query->where('n.kind', (string) $filters['kind']) : $query;
    }

    /** The unseen notices of the account's box in the cities, with each city's date horizon. */
    private function query(WebAccount $account, array $cityIds): Builder
    {
        $today = now()->toDateString();
        $ahead = now()->addDays(self::AHEAD_DAYS)->toDateString();
        $query = DB::table('web_notices as n')->join('cities as c', 'c.id', '=', 'n.city_id')->where('n.account_id', $account->id)->where('n.seen', 0)
            ->select(['n.*', 'c.code as project_code', 'c.name_en as city_name_en', 'c.name_ar as city_name_ar']);

        return $query->where(function (Builder $any) use ($cityIds, $account, $today, $ahead) {
            foreach ($cityIds as $city) {
                $financial = in_array('financial', $this->roles->roles($account, $city), true);
                $any->orWhere(fn (Builder $q) => $q->where('n.city_id', $city)->where('n.notice_date', '<=', $financial ? $today : $ahead));
            }
        });
    }
}
