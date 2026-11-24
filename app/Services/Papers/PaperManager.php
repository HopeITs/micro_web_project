<?php

declare(strict_types=1);

namespace App\Services\Papers;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Adding, replacing and removing the papers of the library (owner decision, 23 Nov 2026: the administrator and media staff keep the papers
 * the team uses). Held by the capability papers.manage, checked per city; every change is audited. The catalogue built from the owner's folder is
 * never rewritten: changes are layered on top by PaperCatalogue and a removed original can be restored.
 */
final class PaperManager
{
    public const MAX_BYTES = 15 * 1024 * 1024;

    public const VARIANTS = ['syrian', 'non_syrian', 'generic'];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit, private PaperCatalogue $catalogue) {}

    /** Cities where the account may change papers. */
    public function cities(WebAccount $account): Collection
    {
        return $this->access->cities($account, 'papers.manage');
    }

    /** The file type from its content (never from its name): pdf, docx or null when it is neither or looks unsafe. */
    public static function detect(string $bytes): ?string
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }
        if (str_starts_with($bytes, '%PDF-')) {
            // Active content has no place in a paper to print (scripts, launched programs, embedded files).
            return preg_match('~/(JavaScript|JS|Launch|EmbeddedFile|RichMedia)\b~', $bytes) === 1 ? null : 'pdf';
        }
        if (str_starts_with($bytes, "PK\x03\x04")) {
            $path = tempnam(sys_get_temp_dir(), 'paper');
            file_put_contents($path, $bytes);
            $zip = new \ZipArchive;
            $ok = false;
            if ($zip->open($path) === true) {
                $ok = $zip->locateName('[Content_Types].xml') !== false && $zip->locateName('word/document.xml') !== false
                    && $zip->locateName('word/vbaProject.bin') === false;
                $zip->close();
            }
            @unlink($path);

            return $ok ? 'docx' : null;
        }

        return null;
    }

    /**
     * Adds a file, or replaces the file of the same paper, city, version and format. `$form` is an id (F10, C01) or null with `$new` naming a new paper.
     *
     * @param  array{name_ar: string, name_en?: ?string, group: string}|null  $new
     * @return array<string, mixed> the entry that is now on offer
     */
    public function upload(WebAccount $account, ?string $form, ?array $new, string $city, ?string $place, string $variant, UploadedFile $file): array
    {
        $cityId = $this->authorize($account, $city);
        $bytes = (string) file_get_contents($file->getRealPath());
        $format = self::detect($bytes);
        if ($format === null) {
            throw new PaperException('papers.err_file');
        }
        if (! in_array($variant, self::VARIANTS, true) || ($place !== null && ($place !== 'IDL' || $city !== 'ALP'))) {
            throw new PaperException('papers.err_target');
        }
        $sha = hash('sha256', $bytes);
        $entry = null;
        $this->catalogue->mutate(function (array $c) use (&$entry, $form, $new, $city, $place, $variant, $format, $sha, $account, $file) {
            if ($form === null) {
                $id = 'C'.str_pad((string) (1 + collect(array_keys($c['forms']))->map(fn ($k) => (int) substr((string) $k, 1))->max()), 2, '0', STR_PAD_LEFT);
                $c['forms'][$id] = ['name_ar' => $new['name_ar'], 'name_en' => $new['name_en'] ?? null, 'group' => $new['group']];
                $form = $id;
            } elseif (! isset($this->catalogue->forms()[$form])) {
                throw new PaperException('papers.err_target');
            }
            $entry = ['form' => $form, 'city' => $city, 'place' => $place, 'variant' => $variant, 'format' => $format, 'sha256' => $sha,
                'uploaded_name' => mb_substr(basename((string) $file->getClientOriginalName()), 0, 120), 'uploaded_by' => $account->id, 'uploaded_at' => now('UTC')->format('Y-m-d H:i:s')];
            $key = PaperCatalogue::key($entry);
            $c['files'] = array_values(array_filter($c['files'], fn ($f) => PaperCatalogue::key($f) !== $key));
            $c['removed'] = array_values(array_filter($c['removed'], fn ($f) => PaperCatalogue::key($f) !== $key));
            $c['files'][] = $entry;
            // The blob is written inside the lock, before the list points to it.
            Storage::disk('local')->put(PaperLibrary::DIRECTORY.'/'.$sha.'.'.$format, (string) file_get_contents($file->getRealPath()));

            return $c;
        });
        $this->audit->record($account->id, 'papers.uploaded', 'succeeded', $cityId, decision: $this->decision($entry));

        return $entry;
    }

    /** Takes a file off the library: an added file is forgotten, an original is hidden (and can be restored). */
    public function remove(WebAccount $account, string $form, string $city, ?string $place, string $variant, string $format): void
    {
        $cityId = $this->authorize($account, $city);
        $target = ['form' => $form, 'city' => $city, 'place' => $place, 'variant' => $variant, 'format' => $format];
        if (! collect($this->catalogue->files())->contains(fn ($e) => PaperCatalogue::key($e) === PaperCatalogue::key($target))) {
            throw new PaperException('papers.err_target');
        }
        $this->catalogue->mutate(function (array $c) use ($target) {
            $key = PaperCatalogue::key($target);
            $c['files'] = array_values(array_filter($c['files'], fn ($f) => PaperCatalogue::key($f) !== $key));
            if (collect((array) config('papers.files', []))->contains(fn ($e) => PaperCatalogue::key($e) === $key)) {
                $c['removed'][] = $target;
            }
            // A paper that was added here and has no file left goes away with it.
            foreach (array_keys($c['forms']) as $id) {
                if (! collect($c['files'])->contains(fn ($f) => $f['form'] === $id)) {
                    unset($c['forms'][$id]);
                }
            }

            return $c;
        });
        $this->forget();
        $this->audit->record($account->id, 'papers.removed', 'succeeded', $cityId, decision: $this->decision($target));
    }

    /** Brings back an original file: the hidden mark and any replacement are dropped. */
    public function restore(WebAccount $account, string $form, string $city, ?string $place, string $variant, string $format): void
    {
        $cityId = $this->authorize($account, $city);
        $target = ['form' => $form, 'city' => $city, 'place' => $place, 'variant' => $variant, 'format' => $format];
        $key = PaperCatalogue::key($target);
        if (! collect((array) config('papers.files', []))->contains(fn ($e) => PaperCatalogue::key($e) === $key)) {
            throw new PaperException('papers.err_target');
        }
        $this->catalogue->mutate(function (array $c) use ($key) {
            $c['files'] = array_values(array_filter($c['files'], fn ($f) => PaperCatalogue::key($f) !== $key));
            $c['removed'] = array_values(array_filter($c['removed'], fn ($f) => PaperCatalogue::key($f) !== $key));

            return $c;
        });
        $this->forget();
        $this->audit->record($account->id, 'papers.restored', 'succeeded', $cityId, decision: $this->decision($target));
    }

    /** Deletes the stored blobs that no paper on offer (original or added) uses any more. */
    private function forget(): void
    {
        $used = [];
        foreach ((array) config('papers.files', []) as $e) {
            $used[$e['sha256'].'.'.$e['format']] = true;
        }
        foreach ($this->catalogue->changes()['files'] as $e) {
            $used[$e['sha256'].'.'.$e['format']] = true;
        }
        $disk = Storage::disk('local');
        foreach ($disk->files(PaperLibrary::DIRECTORY) as $path) {
            $name = basename($path);
            if (preg_match('/\A[0-9a-f]{64}\.(pdf|docx)\z/', $name) === 1 && ! isset($used[$name])) {
                $disk->delete($path);
            }
        }
    }

    private function authorize(WebAccount $account, string $city): int
    {
        $cityId = DB::table('cities')->where('code', $city)->value('id');
        if ($cityId === null) {
            throw new PaperException('papers.err_target');
        }
        if (! $this->access->check($account, (int) $cityId, 'papers.manage')) {
            $this->audit->record($account->id, 'access.denied', 'denied', (int) $cityId);
            abort(403);
        }

        return (int) $cityId;
    }

    /** @param array<string, mixed> $e */
    private function decision(array $e): string
    {
        return 'paper:'.$e['form'].':'.$e['city'].(($e['place'] ?? null) ? '-'.$e['place'] : '').':'.$e['variant'].':'.$e['format'];
    }
}
