<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class AccountAuditTrail
{
    private const SUBJECTS = [
        'applications.list' => [], 'reports.statistics' => [], 'reports.exported' => [], 'logs.listed' => [], 'lookups.listed' => [], 'reports.search' => [], 'applications.view' => ['application_entity'],
        'applications.created' => ['application_entity'], 'applications.updated' => ['application_entity'],
        'lifecycle.transitioned' => ['application_entity'], 'lifecycle.reason_updated' => ['application_entity'], 'lifecycle.undone' => ['application_entity'],
        'assessments.view' => ['application_entity'], 'assessments.saved' => ['application_entity'], 'assessments.printed' => ['application_entity'], 'applications.printed' => ['application_entity'],
        'finance.list' => [], 'finance.view' => ['application_entity'], 'finance.donor_assigned' => ['application_entity'], 'finance.group_created' => ['application_entity'],
        'finance.loan_saved' => ['application_entity'], 'finance.payments_saved' => ['application_entity'], 'finance.loan_deleted' => ['application_entity'], 'finance.installment_deleted' => ['application_entity'],
        'executive_files.view' => ['application_entity'], 'executive_files.saved' => ['application_entity'], 'executive_files.deleted' => ['application_entity'],
        'purchasing.view' => ['application_entity'], 'purchasing.saved' => ['application_entity'], 'purchasing.deleted' => ['application_entity'],
        'special_cases.list' => [], 'special_cases.view' => ['application_entity'], 'special_cases.saved' => ['application_entity'],
        'visits.schedule' => [], 'visits.results' => [], 'visits.statistics' => [], 'visits.view' => ['application_entity'], 'visits.saved' => ['application_entity'],
        'special_cases.deleted' => ['application_entity'], 'visits.deleted' => ['application_entity'],
        'me_visits.view' => ['application_entity'], 'me_visits.saved' => ['application_entity'], 'me_visits.deleted' => ['application_entity'],
        'special_cases.restored' => ['application_entity'],
        'profession_meetings.list' => [], 'profession_meetings.view' => ['meeting_entity'], 'profession_meetings.saved' => ['meeting_entity'], 'profession_meetings.deleted' => ['meeting_entity'], 'profession_meetings.restored' => ['meeting_entity'], 'visits.restored' => ['application_entity'], 'me_visits.restored' => ['application_entity'],
        'tasks.board' => [], 'tasks.mine' => [], 'tasks.toggled' => ['application_entity'],
        'notifications.list' => [], 'notifications.seen' => [], 'notifications.sent' => [], 'notifications.noted' => [], 'notifications.rescheduled' => [],
        'accounts.list' => [], 'accounts.role_assigned' => ['account'], 'accounts.role_revoked' => ['account'], 'accounts.email_set' => ['account'], 'accounts.tasks_saved' => [],
        'password.changed' => ['account'], 'password.reset_requested' => ['account'], 'password.reset_denied' => [], 'password.reset' => ['account'], 'accounts.view' => ['account'], 'accounts.disabled' => ['account'], 'accounts.enabled' => ['account'],
        'application_draft.saved' => [], 'application_draft.viewed' => [], 'application_draft.discarded' => [],
        'people.list' => [], 'people.view' => ['person_entity'],
        'people.created' => ['person_entity', 'family_entity'], 'people.updated' => ['person_entity'],
        'login.succeeded' => ['account'], 'login.denied' => [], 'password.rehashed' => ['account'],
        'logout.succeeded' => ['account'], 'access.denied' => [], 'projects.list' => [], 'projects.view' => ['project_record'],
        'account.provisioned' => ['account'], 'legacy.linked' => ['legacy_link'], 'legacy.revoked' => ['legacy_link'],
        'attachments.download.started' => ['attachment_record'], 'attachments.download.succeeded' => ['attachment_record'],
        'attachments.download.failed' => ['attachment_record'], 'attachments.download.denied' => [],
        'attachments.uploaded' => ['application_entity', 'person_entity'], 'attachments.deleted' => ['application_entity', 'person_entity'], 'attachments.view' => ['application_entity', 'person_entity'],
        'legacy.restored' => ['legacy_link'], 'capability.granted' => ['capability_grant'], 'capability.revoked' => ['capability_grant'],
        'saved_views.created' => [], 'saved_views.deleted' => [], 'projects.exported' => [], 'people.exported' => [], 'installments.exported' => [],
        'identity.read' => ['person_entity'],
        'national.checked' => [], 'kobo.view' => [], 'kobo.list' => [], 'kobo.exported' => [], 'papers.downloaded' => [], 'papers.printed' => ['application_entity', 'person_entity', 'meeting_entity'], 'lookups.created' => ['lookup_value'], 'lookups.updated' => ['lookup_value'], 'lookups.hidden' => ['lookup_value'], 'lookups.shown' => ['lookup_value'], 'lookups.deleted' => ['lookup_value'], 'lookups.exported' => [],
    ];

    /** v1.3 details: at most this many fields per event, each value cut at this many characters. */
    public const MAX_FIELDS = 60;

    public const MAX_VALUE = 200;

    /** Fields whose values are never stored (schema v1.3): matched against the whole field name. */
    private const NEVER_STORED = '/(password|passwd|hash|token|secret|one_time|otp|draft|payload|content|blob|file_data)/i';

    /** Fields recorded as "changed" without their values. */
    private const VALUE_HIDDEN = ['national_number'];

    private const IGNORED = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * The fields that differ between a row before and the values written to it, in the v1.3 shape:
     * list of {field, before, after}, or {field, hidden: true} for a value that is never shown, with `cut: true` on a shortened value.
     *
     * @param  array<string, mixed>|object  $before
     * @param  array<string, mixed>  $after  only the keys written are compared
     * @return ?list<array<string, mixed>>
     */
    public static function changes(array|object $before, array $after): ?array
    {
        $before = (array) $before;
        $out = [];
        foreach ($after as $field => $value) {
            $field = (string) $field;
            if (in_array($field, self::IGNORED, true) || preg_match(self::NEVER_STORED, $field) === 1) {
                continue;
            }
            $old = $before[$field] ?? null;
            if (self::plain($old) === self::plain($value)) {
                continue;
            }
            $out[] = ['field' => $field, 'before' => $old, 'after' => $value];
        }

        return self::sanitize($out);
    }

    /**
     * Applies the v1.3 rules to details from any caller: drops never-stored fields, hides the national number, cuts long values and
     * keeps at most MAX_FIELDS entries. Returns null when nothing is left.
     *
     * @param  list<array<string, mixed>>  $details
     */
    public static function sanitize(array $details): ?array
    {
        $out = [];
        foreach ($details as $item) {
            $field = is_array($item) ? (string) ($item['field'] ?? '') : '';
            if (preg_match('/\A[a-z][a-z0-9_.]{0,63}\z/', $field) !== 1 || preg_match(self::NEVER_STORED, $field) === 1) {
                continue;
            }
            if (in_array($field, self::VALUE_HIDDEN, true) || ($item['hidden'] ?? false) === true) {
                $out[] = ['field' => $field, 'hidden' => true];
            } else {
                $entry = ['field' => $field];
                foreach (['before', 'after'] as $side) {
                    $text = self::plain($item[$side] ?? null);
                    if ($text !== null && mb_strlen($text) > self::MAX_VALUE) {
                        $text = mb_substr($text, 0, self::MAX_VALUE).'…';
                        $entry['cut'] = true;
                    }
                    $entry[$side] = $text;
                }
                $out[] = $entry;
            }
            if (count($out) === self::MAX_FIELDS) {
                break;
            }
        }

        return $out === [] ? null : $out;
    }

    /** A comparable text form: null stays null, booleans 1/0, numbers without trailing zeros, dates as written. */
    private static function plain(mixed $value): ?string
    {
        return match (true) {
            $value === null || $value === '' => null,
            is_bool($value) => $value ? '1' : '0',
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_scalar($value) => is_numeric($value) && str_contains((string) $value, '.') ? rtrim(rtrim((string) $value, '0'), '.') : (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE),
        };
    }

    public static function validDecision(string $decision): bool
    {
        return strlen($decision) <= 1024 && preg_match('/\A[a-zA-Z0-9_:\/.\-]+\z/', $decision) === 1;
    }

    public function record(?int $actor, string $event, string $outcome, ?int $city = null, ?int $subjectAccount = null, ?string $subjectType = null, ?int $subjectId = null, ?string $decision = null, ?array $details = null): void
    {
        // v1.3: details only describe a change that happened; views, lists and refusals carry none.
        $details = $details === null ? null : self::sanitize($details);
        if ($details !== null && ($outcome !== 'succeeded' || preg_match('/\.(view|list|listed|board|search|statistics|viewed|schedule|exported|checked|printed)\z/', $event) === 1)) {
            throw new InvalidArgumentException('Audit details are only recorded for changes.');
        }
        if (! array_key_exists($event, self::SUBJECTS) || ! in_array($outcome, ['succeeded', 'denied', 'failed'], true)
            || (($subjectType === null) !== ($subjectId === null))
            || ($subjectType !== null && (! in_array($subjectType, self::SUBJECTS[$event], true) || $subjectId < 1))
            || ($decision !== null && ! self::validDecision($decision))
            || (preg_match('/\A(account\.|legacy\.|capability\.)/', $event) && $decision === null)
            || ($city !== null && ! DB::table('cities')->where('id', $city)->exists())) {
            throw new InvalidArgumentException('Invalid account audit metadata.');
        }
        if ($subjectType !== null) {
            $validSubject = match ($subjectType) {
                'application_entity' => DB::table('projects')->where('id', $subjectId)->where('city_id', $city)->exists(),
                'person_entity' => DB::table('people')->where('id', $subjectId)->where('city_id', $city)->exists(),
                'meeting_entity' => DB::table('profession_meetings')->where('id', $subjectId)->where('city_id', $city)->exists(),
                'family_entity' => DB::table('families')->where('id', $subjectId)->where('city_id', $city)->exists(),
                'account' => $subjectAccount === $subjectId && DB::table('web_accounts')->where('id', $subjectId)->exists(),
                // A list value (M4.2): the decision names the list ("lookup:<key>"); a deleted value no longer exists, so only the shape is checked.
                'lookup_value' => $decision !== null && str_starts_with($decision, 'lookup:'),
                'legacy_link' => DB::table('account_legacy_links')->where('id', $subjectId)->where('account_id', $subjectAccount)->exists(),
                'capability_grant' => DB::table('account_city_capabilities')->where('id', $subjectId)->where('account_id', $subjectAccount)->where('city_id', $city)->exists(),
                'project_record' => DB::table('source_records as r')->join('sources as s', 's.id', '=', 'r.source_id')->where('r.id', $subjectId)->where('s.city_id', $city)->where('r.table_id', app(SourceCatalog::class)->tableId('microproject'))->exists(),
                'attachment_record' => DB::table('source_records as r')->join('sources as s', 's.id', '=', 'r.source_id')
                    ->join('source_tables as t', 't.id', '=', 'r.table_id')->where('r.id', $subjectId)->where('s.city_id', $city)
                    ->whereIn('t.name', ['file', 'image'])->exists(),
                default => false,
            };
            if (! $validSubject) {
                throw new InvalidArgumentException('Invalid account audit subject.');
            }
        }
        DB::table('account_audit_events')->insert([
            'actor_account_id' => $actor, 'subject_account_id' => $subjectAccount, 'city_id' => $city,
            'event_type' => $event, 'outcome' => $outcome, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'decision_locator' => $decision, 'details' => $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'occurred_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }
}
