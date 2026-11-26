<?php

declare(strict_types=1);

namespace App\Services\Attachments;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use App\Support\TableTools\TableSearch;
use App\Support\TableTools\TableSort;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * M4.11 attachments of a project or a person (desktop Attachments_Form). Every call rechecks the city grants:
 * the owner must be readable (projects.view / people.view), and each file needs attachments.download plus the grant of its
 * category (AccountAccess::ATTACHMENT_CATEGORIES), as the desktop showed each role only its own categories. Files are kept on the
 * private disk; imported FTP rows (stored_path "ftp/...") stay listed as "not moved yet" until the M5.4 copy places them there.
 */
final class AttachmentService
{
    /** owner kind => table, column on attachments, read capability, audit subject */
    public const OWNERS = [
        'project' => ['table' => 'projects', 'column' => 'project_id', 'view' => 'projects.view', 'subject' => 'application_entity'],
        'person' => ['table' => 'people', 'column' => 'person_id', 'view' => 'people.view', 'subject' => 'person_entity'],
        // M4.15: the minutes, photos and papers of a profession meeting.
        'meeting' => ['table' => 'profession_meetings', 'column' => 'profession_meeting_id', 'view' => 'profession_meetings.view', 'subject' => 'meeting_entity'],
    ];

    /** The desktop's personal photo type (`imagetype.Name_en` "Profile"): set from the person page only, never from the list. */
    public const PHOTO = 'Profile';

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit) {}

    // ---------------------------------------------------------------- access

    /** The project or person the account may read (404 otherwise, as if it did not exist). */
    public function owner(WebAccount $account, string $kind, int $id): object
    {
        $spec = self::OWNERS[$kind] ?? abort(404);
        $cities = $this->access->cities($account, $spec['view'])->pluck('id')->all();
        $row = DB::table($spec['table'])->where('id', $id)->whereNull('deleted_at')->whereIn('city_id', $cities ?: [0])->first();
        abort_if($row === null, 404);

        return $row;
    }

    /**
     * The category grants the account holds in a city (only with attachments.download).
     *
     * @return list<string> category names as the desktop spells them
     */
    public function categories(WebAccount $account, int $city): array
    {
        if (! $this->access->check($account, $city, 'attachments.download')) {
            return [];
        }

        return array_values(array_keys(array_filter(AccountAccess::ATTACHMENT_CATEGORIES, fn (string $grant) => $this->access->check($account, $city, $grant))));
    }

    /** May the account open (and add to / delete from) this category? A file without a type needs every category grant. */
    private function allowed(array $categories, ?string $category): bool
    {
        return $category === null || ! array_key_exists($category, AccountAccess::ATTACHMENT_CATEGORIES)
            ? count($categories) === count(AccountAccess::ATTACHMENT_CATEGORIES)
            : in_array($category, $categories, true);
    }

    public function canSee(WebAccount $account, int $city): bool
    {
        return $this->categories($account, $city) !== [];
    }

    /** Types the account may upload in a city: active, in a category it holds, never the personal photo. */
    public function uploadTypes(WebAccount $account, int $city): Collection
    {
        $categories = $this->categories($account, $city);

        return DB::table('attachment_types')->where('is_active', true)->whereIn('category', $categories ?: ['-'])
            ->where(fn ($q) => $q->whereNull('name_en')->orWhere('name_en', '!=', self::PHOTO))
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'name_ar', 'name_en', 'category']);
    }

    /** An attachment the account may open (404 for anything else, including other cities and hidden categories). */
    public function find(WebAccount $account, int $id): object
    {
        $row = $this->query()->where('a.id', $id)->first();
        abort_if($row === null, 404);
        $kind = $row->project_id ? 'project' : ($row->person_id ? 'person' : ($row->profession_meeting_id ? 'meeting' : null));
        abort_if($kind === null, 404);
        $owner = $this->owner($account, $kind, (int) ($row->project_id ?: ($row->person_id ?: $row->profession_meeting_id)));
        abort_unless((int) $owner->city_id === (int) $row->city_id && $this->allowed($this->categories($account, (int) $row->city_id), $row->category), 404);
        $row->owner_kind = $kind;
        $row->owner_id = (int) $owner->id;

        return $row;
    }

    // ---------------------------------------------------------------- reading

    private function query()
    {
        return DB::table('attachments as a')->leftJoin('attachment_types as t', 't.id', '=', 'a.attachment_type_id')
            ->leftJoin('web_accounts as w', 'w.id', '=', 'a.uploaded_by_account_id')->whereNull('a.deleted_at')
            ->select(['a.*', 't.name_ar as type_ar', 't.name_en as type_en', 't.category', 'w.username as uploader']);
    }

    /**
     * The visible attachments of a project or person, newest first, with the counts per type for the filter.
     *
     * @return array{rows: Collection, counts: array<int|string, int>, hidden: int}
     */
    public function list(WebAccount $account, string $kind, int $id, ?int $type = null): array
    {
        $owner = $this->owner($account, $kind, $id);
        $categories = $this->categories($account, (int) $owner->city_id);
        $all = $this->query()->where('a.'.self::OWNERS[$kind]['column'], $id)->where('a.city_id', $owner->city_id)
            ->when($kind === 'person', fn ($q) => $q->where(fn ($q) => $q->whereNull('t.name_en')->orWhere('t.name_en', '!=', self::PHOTO)))
            ->orderByDesc('a.uploaded_at')->orderByDesc('a.id')->get();
        $visible = $all->filter(fn ($r) => $this->allowed($categories, $r->category))->values();
        $counts = $visible->countBy(fn ($r) => (int) $r->attachment_type_id)->all();
        $rows = $type === null ? $visible : $visible->where('attachment_type_id', $type)->values();

        return ['rows' => $rows->map(fn ($r) => $this->describe($r)), 'counts' => $counts, 'hidden' => $all->count() - $visible->count()];
    }

    /**
     * Attachments across the permitted cities for the attachments page: each city only with the categories the account holds there,
     * project files only where it reads projects, person files only where it reads people.
     *
     * @param  array{q?: string, cities?: list<int>, category?: string, missing?: bool}  $filters
     */
    /** The sortable columns of the attachments page. */
    public const SORT_KEYS = ['name', 'type', 'owner', 'city', 'uploaded'];

    public function search(WebAccount $account, array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $projects = $this->access->cities($account, 'projects.view')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $people = $this->access->cities($account, 'people.view')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $grants = [];
        foreach ($this->access->cities($account, 'attachments.download') as $city) {
            if (($filters['cities'] ?? []) !== [] && ! in_array((int) $city->id, $filters['cities'], true)) {
                continue;
            }
            $categories = $this->categories($account, (int) $city->id);
            if ($categories !== []) {
                $grants[(int) $city->id] = $categories;
            }
        }
        $query = $this->query()->leftJoin('projects as p', 'p.id', '=', 'a.project_id')->leftJoin('people as h', 'h.id', '=', 'a.person_id')
            ->join('cities as c', 'c.id', '=', 'a.city_id')
            ->addSelect(['p.project_number', 'p.name as project_name', 'h.first_name', 'h.father_name', 'h.last_name', 'c.code as city_code', 'c.name_ar as city_ar', 'c.name_en as city_en'])
            ->where(function ($scope) use ($grants, $projects, $people) {
                $scope->whereRaw('1 = 0');
                foreach ($grants as $city => $categories) {
                    $scope->orWhere(function ($q) use ($city, $categories, $projects, $people) {
                        $q->where('a.city_id', $city)->where(function ($o) use ($city, $projects, $people) {
                            $o->where(fn ($x) => $x->whereNotNull('a.project_id')->whereNull('p.deleted_at')->whereRaw(in_array($city, $projects, true) ? '1 = 1' : '1 = 0'))
                                ->orWhere(fn ($x) => $x->whereNull('a.project_id')->whereNotNull('a.person_id')->whereNull('h.deleted_at')->whereRaw(in_array($city, $people, true) ? '1 = 1' : '1 = 0'));
                        })->where(function ($t) use ($categories) {
                            $t->whereIn('t.category', $categories);
                            if (count($categories) === count(AccountAccess::ATTACHMENT_CATEGORIES)) {
                                $t->orWhereNull('t.category')->orWhereNotIn('t.category', array_keys(AccountAccess::ATTACHMENT_CATEGORIES));
                            }
                        });
                    });
                }
            });
        if (($filters['category'] ?? '') !== '') {
            $query->where('t.category', $filters['category']);
        }
        $term = trim((string) ($filters['q'] ?? ''));
        if (preg_match('/\A[A-Za-z]{0,3}-?\s*(\d{1,10})\z/', $term, $m) === 1) {
            $query->where('p.project_number', (int) $m[1]);
        } elseif ($term !== '') {
            // Every word must appear in the file name, the project name or the person's name (Arabic letters folded).
            TableSearch::apply($query, ['a.original_name', 'p.name', 'h.first_name', 'h.father_name', 'h.last_name'], $term);
        }
        TableSort::apply($query, [
            'name' => 'a.original_name', 'type' => app()->isLocale('en') ? "COALESCE(NULLIF(t.name_en, ''), t.name_ar)" : 't.name_ar',
            'owner' => ['c.code', 'p.project_number', 'h.first_name', 'h.last_name'], 'city' => app()->isLocale('en') ? 'c.name_en' : 'c.name_ar', 'uploaded' => 'a.uploaded_at',
        ], $filters['sort'] ?? null, $filters['dir'] ?? null);

        return $query->orderByDesc('a.uploaded_at')->orderByDesc('a.id')->paginate($perPage)->through(fn ($r) => $this->describe($r));
    }

    /** Categories the account holds in at least one city, for the page filter. */
    public function categoryOptions(WebAccount $account): array
    {
        $held = [];
        foreach ($this->access->cities($account, 'attachments.download') as $city) {
            $held = [...$held, ...$this->categories($account, (int) $city->id)];
        }

        return array_values(array_intersect(array_keys(AccountAccess::ATTACHMENT_CATEGORIES), $held));
    }

    public function cityOptions(WebAccount $account): Collection
    {
        return $this->access->cities($account, 'attachments.download')->filter(fn ($c) => $this->categories($account, (int) $c->id) !== [])->values();
    }

    /** The current personal photo of a person, if the account may see it. */
    public function photo(WebAccount $account, int $personId): ?object
    {
        $person = $this->owner($account, 'person', $personId);
        $categories = $this->categories($account, (int) $person->city_id);
        $row = $this->query()->where('a.person_id', $personId)->where('a.city_id', $person->city_id)->where('t.name_en', self::PHOTO)
            ->orderByDesc('a.uploaded_at')->orderByDesc('a.id')->first();

        return $row !== null && $this->allowed($categories, $row->category) ? $this->describe($row) : null;
    }

    public function canSetPhoto(WebAccount $account, int $city): bool
    {
        return $this->access->check($account, $city, 'people.manage') && in_array(array_search('attachments.personal', AccountAccess::ATTACHMENT_CATEGORIES, true), $this->categories($account, $city), true);
    }

    private function describe(object $row): object
    {
        $ext = strtolower((string) $row->extension);
        $row->is_image = in_array($ext, ['jpg', 'jpeg', 'png'], true);
        $row->is_pdf = $ext === 'pdf';
        $row->available = $this->path($row) !== null;
        $row->type_label = $row->type_ar === null ? null : (app()->isLocale('en') && $row->type_en ? $row->type_en : $row->type_ar);
        $row->size = $row->available ? filesize((string) $this->path($row)) : null;

        return $row;
    }

    /** The file on the private disk, or null when it is missing (not moved yet) or the stored path is not a safe relative path. */
    public function path(object $row): ?string
    {
        $stored = str_replace('\\', '/', (string) $row->stored_path);
        if ($stored === '' || str_starts_with($stored, '/') || preg_match('#(^|/)\.\.?(/|$)|^[a-z]:#i', $stored) === 1 || str_contains($stored, "\0")) {
            return null;
        }
        $root = realpath(Storage::disk(config('attachments.disk', 'local'))->path(''));
        $path = $root === false ? false : realpath($root.DIRECTORY_SEPARATOR.$stored);
        if ($path === false || ! is_file($path) || is_link($path) || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }

    /** MIME type to send: only the checked kinds are shown in the browser, anything else is a plain download. */
    public static function mime(object $row): ?string
    {
        return config('attachments.upload.types')[strtolower((string) $row->extension)] ?? null;
    }

    public function audit(WebAccount $account, object $row, string $event): void
    {
        $this->audit->record($account->id, $event, 'succeeded', (int) $row->city_id, subjectType: self::OWNERS[$row->owner_kind]['subject'],
            subjectId: $row->owner_id, decision: 'attachment:'.$row->id);
    }

    // ---------------------------------------------------------------- changes

    /**
     * Saves the files under one type; all are checked before any is stored.
     *
     * @param  list<UploadedFile>  $files
     * @return list<int>
     */
    public function upload(WebAccount $account, string $kind, int $id, ?int $typeId, array $files): array
    {
        $owner = $this->owner($account, $kind, $id);
        $city = (int) $owner->city_id;
        $this->canSee($account, $city) || $this->deny($account);
        $type = $this->uploadTypes($account, $city)->firstWhere('id', $typeId);
        if ($type === null) {
            throw ValidationException::withMessages(['type' => __('attachments.errors.type')]);
        }

        return $this->store($account, $kind, $owner, (int) $type->id, $files);
    }

    /** Sets the person's photo; earlier photos are hidden, not destroyed. */
    public function setPhoto(WebAccount $account, int $personId, UploadedFile $file): int
    {
        $person = $this->owner($account, 'person', $personId);
        $this->canSetPhoto($account, (int) $person->city_id) || $this->deny($account);
        $type = DB::table('attachment_types')->where('name_en', self::PHOTO)->value('id');
        $type !== null || throw ValidationException::withMessages(['photo' => __('attachments.errors.type')]);
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            throw ValidationException::withMessages(['photo' => __('attachments.errors.photo_kind')]);
        }

        return DB::transaction(function () use ($account, $person, $type, $file) {
            $old = DB::table('attachments as a')->join('attachment_types as t', 't.id', '=', 'a.attachment_type_id')->where('a.person_id', $person->id)
                ->where('t.name_en', self::PHOTO)->whereNull('a.deleted_at')->pluck('a.id');
            $id = $this->store($account, 'person', $person, (int) $type, [$file], 'photo')[0];
            foreach ($old as $previous) {
                $this->remove($account, (int) $previous, $person, 'person', permanent: false);
            }

            return $id;
        });
    }

    /** @param list<UploadedFile> $files */
    private function store(WebAccount $account, string $kind, object $owner, int $type, array $files, string $field = 'files'): array
    {
        $types = config('attachments.upload.types');
        $max = (int) config('attachments.upload.max_kb') * 1024;
        $files = array_values(array_filter($files, fn ($f) => $f instanceof UploadedFile));
        if ($files === []) {
            throw ValidationException::withMessages([$field => __('attachments.errors.none')]);
        }
        if (count($files) > (int) config('attachments.upload.max_files')) {
            throw ValidationException::withMessages([$field => __('attachments.errors.too_many', ['max' => config('attachments.upload.max_files')])]);
        }
        foreach ($files as $file) {
            $ext = strtolower($file->getClientOriginalExtension());
            $name = $this->cleanName($file->getClientOriginalName());
            if (! $file->isValid() || ! isset($types[$ext])) {
                throw ValidationException::withMessages([$field => __('attachments.errors.kind', ['name' => $name, 'kinds' => implode(', ', array_keys($types))])]);
            }
            if ($file->getSize() > $max || $file->getSize() < 1) {
                throw ValidationException::withMessages([$field => __('attachments.errors.size', ['name' => $name, 'max' => (int) config('attachments.upload.max_kb') / 1024])]);
            }
            // The content must be what the name says (a renamed program is refused).
            if ((new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) !== $types[$ext]) {
                throw ValidationException::withMessages([$field => __('attachments.errors.content', ['name' => $name])]);
            }
        }
        $code = (string) DB::table('cities')->where('id', $owner->city_id)->value('code');
        $disk = Storage::disk(config('attachments.disk', 'local'));
        $ids = [];
        $written = [];
        try {
            DB::transaction(function () use ($files, $kind, $owner, $type, $code, $disk, $account, &$ids, &$written) {
                foreach ($files as $file) {
                    $ext = strtolower($file->getClientOriginalExtension());
                    $stored = "attachments/{$code}/{$kind}-{$owner->id}/".Str::uuid()->toString().'.'.$ext;
                    $disk->putFileAs(dirname($stored), $file, basename($stored)) !== false || throw new \RuntimeException('Attachment could not be stored.');
                    $written[] = $stored;
                    $now = now();
                    $id = (int) DB::table('attachments')->insertGetId([
                        'city_id' => $owner->city_id, 'legacy_id' => null,
                        'project_id' => $kind === 'project' ? $owner->id : null, 'person_id' => $kind === 'person' ? $owner->id : null,
                        'profession_meeting_id' => $kind === 'meeting' ? $owner->id : null, 'attachment_type_id' => $type, 'original_name' => $this->cleanName($file->getClientOriginalName()),
                        'stored_path' => $stored, 'extension' => $ext,
                        // The schema keeps the desktop source; a web upload is the same kind of record as the desktop `image` row.
                        'legacy_source' => 'image', 'uploaded_by_account_id' => $account->id, 'uploaded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $ids[] = $id;
                    $this->audit->record($account->id, 'attachments.uploaded', 'succeeded', (int) $owner->city_id, subjectType: self::OWNERS[$kind]['subject'],
                        subjectId: (int) $owner->id, decision: 'attachment:'.$id, details: [
                            ['field' => 'attachment.original_name', 'before' => null, 'after' => $this->cleanName($file->getClientOriginalName())],
                            ['field' => 'attachment.type', 'before' => null, 'after' => $this->typeName($type)],
                        ]);
                }
            });
        } catch (\Throwable $e) {
            foreach ($written as $path) {
                $disk->delete($path);
            }
            throw $e;
        }

        return $ids;
    }

    public function delete(WebAccount $account, int $id): void
    {
        $row = $this->find($account, $id);
        $owner = $this->owner($account, $row->owner_kind, $row->owner_id);
        $this->remove($account, $id, $owner, $row->owner_kind, permanent: true);
    }

    /**
     * Owner decision (21 Nov 2026): deleting is permanent, as in the desktop (the row and the stored file go; the audit log keeps who,
     * which file and which category). Only a replaced personal photo is hidden instead (permanent: false).
     */
    private function remove(WebAccount $account, int $id, object $owner, string $kind, bool $permanent): void
    {
        $row = $this->query()->where('a.id', $id)->first();
        abort_if($row === null, 404);
        $this->allowed($this->categories($account, (int) $row->city_id), $row->category) || $this->deny($account);
        if ($row->type_en === self::PHOTO && ! $this->canSetPhoto($account, (int) $row->city_id)) {
            $this->deny($account);
        }
        if (! $permanent) {
            DB::table('attachments')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);

            return;
        }
        $path = $this->path($row);
        DB::transaction(function () use ($account, $id, $row, $owner, $kind) {
            DB::table('attachments')->where('id', $id)->delete();
            $this->audit->record($account->id, 'attachments.deleted', 'succeeded', (int) $row->city_id, subjectType: self::OWNERS[$kind]['subject'],
                subjectId: (int) $owner->id, decision: 'attachment:'.$id, details: [
                    ['field' => 'attachment.original_name', 'before' => $row->original_name, 'after' => null],
                    ['field' => 'attachment.type', 'before' => $this->typeName($row->attachment_type_id), 'after' => null],
                    ['field' => 'attachment.category', 'before' => $row->category, 'after' => null],
                ]);
        });
        // The file goes only after the row is gone, so a failed delete never leaves a row pointing at nothing.
        if ($path !== null) {
            @unlink($path);
        }
    }

    private function typeName(?int $type): ?string
    {
        return $type === null ? null : DB::table('attachment_types')->where('id', $type)->value('name_ar');
    }

    /** The name as shown and offered back on download: no folders, no control characters, at most 255 characters. */
    private function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"<>|*?:]+/u', '', $name));

        return mb_substr($name !== '' ? $name : 'file', 0, 255);
    }

    private function deny(WebAccount $account): never
    {
        $this->audit->record($account->id, 'access.denied', 'denied');
        abort(403);
    }
}
