<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\WebAccount;
use App\Services\AccountAuditTrail;
use App\Services\Attachments\AttachmentService;
use App\Services\People\PeopleService;
use App\Services\People\PersonFields;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Reading an identity document from an attachment and applying it to the person (owner decision 23 Nov 2026): the scan is read only when staff
 * press "read" (a person's own scan or a scan of a project the person applies for), the values are proposals beside the image, and nothing
 * changes until a staff member approves them. The values are written through {@see PeopleService::update}, so validation, the duplicate check
 * of the national number and the audit trail (with the v1.3 details, the national number only as "changed") are the person form's own.
 */
final class IdentityReviewService
{
    /** Extensions the reader accepts (the attachments' own checked kinds that a vision model can read). */
    public const READABLE = ['jpg', 'jpeg', 'png', 'pdf'];

    /** The attachment type of identity papers (desktop imagetype "IDs (Personal - Family - Military)"). */
    private const ID_TYPE_PREFIX = 'IDs';

    public function __construct(private AttachmentService $attachments, private PeopleService $people, private IdCardReader $reader, private AccountAuditTrail $audit) {}

    public function enabled(): bool
    {
        return $this->reader->enabled();
    }

    /** The person, if the account may change them (people.manage in the city); 404/403 otherwise. */
    public function person(WebAccount $account, int $personId): object
    {
        $person = $this->people->person($account, $personId);
        $this->people->canManage($account, (int) $person->city_id) || abort(403);

        return $person;
    }

    /**
     * The scans staff can read for a person: their own attachments and those of the projects they apply for, only image and PDF files that are
     * on the disk and in a category the account may see. Identity papers come first.
     *
     * @return list<object>
     */
    public function scans(WebAccount $account, int $personId): array
    {
        $this->person($account, $personId);
        $rows = collect($this->attachments->list($account, 'person', $personId)['rows']);
        foreach (DB::table('project_applicants')->where('person_id', $personId)->whereNull('deleted_at')->pluck('project_id') as $projectId) {
            try {
                $rows = $rows->concat($this->attachments->list($account, 'project', (int) $projectId)['rows']);
            } catch (HttpException) {
                // A project the account may not read has no scans for it.
            }
        }

        return $rows->filter(fn ($r) => $r->available && in_array(strtolower((string) $r->extension), self::READABLE, true))
            ->map(function ($r) {
                $r->is_id = str_starts_with((string) ($r->type_en ?? ''), self::ID_TYPE_PREFIX);

                return $r;
            })
            ->sortByDesc(fn ($r) => [(int) $r->is_id, (int) $r->id])->values()->all();
    }

    /** One scan of the person (404 for anything else). */
    public function scan(WebAccount $account, int $personId, int $attachmentId): object
    {
        $scan = collect($this->scans($account, $personId))->first(fn ($r) => (int) $r->id === $attachmentId);
        abort_if($scan === null, 404);

        return $scan;
    }

    /**
     * Reads the scan. Counted per account and day (the daily limit of AI use), logged without its content, audited.
     *
     * @return array<string, mixed> the reader's result
     */
    public function read(WebAccount $account, int $personId, int $attachmentId): array
    {
        $person = $this->person($account, $personId);
        $scan = $this->scan($account, $personId, $attachmentId);
        $city = (int) $person->city_id;
        $used = DB::table('ai_queries')->where('account_id', $account->id)->where('dataset', 'identity')->where('status', 'ok')
            ->where('created_at', '>=', now('UTC')->startOfDay()->format('Y-m-d H:i:s'))->count();
        if ($used >= (int) config('identity_reader.daily_limit')) {
            $this->audit->record($account->id, 'identity.read', 'denied', $city, subjectType: 'person_entity', subjectId: $personId, decision: 'attachment:'.$attachmentId);
            throw new IdentityReadException('limit');
        }
        $path = (string) $this->attachments->path($scan);
        $mime = (string) AttachmentService::mime($scan);
        $size = (int) filesize($path);
        if ($mime === '' || $size > ($mime === 'application/pdf' ? (int) config('identity_reader.max_pdf_bytes') : (int) config('identity_reader.max_image_bytes'))) {
            throw new IdentityReadException('too_large');
        }
        $started = microtime(true);
        try {
            $result = $this->reader->read((string) file_get_contents($path), $mime);
        } catch (IdentityReadException $e) {
            $this->log($account, 'error', 0, 0, $started);
            $this->audit->record($account->id, 'identity.read', 'failed', $city, subjectType: 'person_entity', subjectId: $personId, decision: 'attachment:'.$attachmentId);
            throw $e;
        }
        $this->log($account, 'ok', $result['input_tokens'], $result['output_tokens'], $started);
        $this->audit->record($account->id, 'identity.read', 'succeeded', $city, subjectType: 'person_entity', subjectId: $personId, decision: 'attachment:'.$attachmentId);

        return $result;
    }

    /** How many readings the account has left today. */
    public function remaining(WebAccount $account): int
    {
        $used = DB::table('ai_queries')->where('account_id', $account->id)->where('dataset', 'identity')->where('status', 'ok')
            ->where('created_at', '>=', now('UTC')->startOfDay()->format('Y-m-d H:i:s'))->count();

        return max(0, (int) config('identity_reader.daily_limit') - $used);
    }

    /**
     * Saves the approved values on the person. Only the identity fields can be set here; the rest of the person is kept as it is.
     *
     * @param  array<string, string>  $values  field => value
     */
    public function apply(WebAccount $account, int $personId, array $values, bool $confirmed): void
    {
        $row = $this->person($account, $personId);
        $state = [];
        foreach (PersonFields::personFlat() as $field => $spec) {
            $value = $row->{$field};
            $state[$field] = match ($spec['kind']) {
                'bool' => $value === null ? '' : ((bool) $value ? '1' : '0'),
                'date' => $value ? substr((string) $value, 0, 10) : '',
                default => $value === null ? '' : (string) $value,
            };
        }
        foreach ($values as $field => $value) {
            if (in_array($field, [...IdCardReader::FIELDS, 'nationality', 'id_document_type'], true)) {
                $state[$field] = (string) $value;
            }
        }
        $this->people->update($account, $personId, $state, $confirmed);
    }

    private function log(WebAccount $account, string $status, int $input, int $output, float $started): void
    {
        // The reading itself is never stored: the log keeps who, when, how long and how many tokens.
        DB::table('ai_queries')->insert([
            'account_id' => $account->id, 'dataset' => 'identity', 'prompt' => '[identity document scan]', 'spec' => null, 'status' => $status,
            'model' => (string) config('identity_reader.model'), 'input_tokens' => $input, 'output_tokens' => $output,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'created_at' => now('UTC')->format('Y-m-d H:i:s.u'),
        ]);
    }
}
