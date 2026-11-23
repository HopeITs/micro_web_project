<?php

declare(strict_types=1);

namespace App\Services\Papers;

use App\Models\WebAccount;
use App\Services\AccountAccess;
use App\Services\AccountAuditTrail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * M4.20 official papers library: the blank papers of every city and version, for download (owner decision 21 Nov 2026).
 * An account sees the papers of the cities where it holds projects.view. Idlib papers belong to Aleppo (place IDL).
 */
final class PaperLibrary
{
    public const DIRECTORY = 'papers';

    /** Papers redrawn in HTML so far, and the application stage each prints (the review page shows them beside the original). */
    public const REDRAWN = ['F02' => 'preliminary', 'F03' => 'full'];

    public const GROUPS = ['registration', 'study', 'financing', 'legal', 'purchasing', 'consents', 'meetings', 'training', 'handouts'];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit) {}

    /** Cities (with code) whose papers the account may download. */
    public function cities(WebAccount $account): Collection
    {
        return $this->access->cities($account, 'projects.view');
    }

    /**
     * The papers grouped by paper, each with its files for the permitted cities, filtered by city code and version.
     *
     * @param  array{city?: ?string, variant?: ?string, q?: ?string}  $filters
     * @return array{papers: list<array<string, mixed>>, cities: Collection, installed: bool}
     */
    public function index(WebAccount $account, array $filters): array
    {
        $cities = $this->cities($account);
        if ($cities->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $codes = $cities->pluck('code')->all();
        $city = in_array($filters['city'] ?? null, $codes, true) ? $filters['city'] : null;
        $variant = in_array($filters['variant'] ?? null, ['syrian', 'non_syrian', 'generic'], true) ? $filters['variant'] : null;
        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));

        $byForm = [];
        foreach (config('papers.files', []) as $entry) {
            if (! in_array($entry['city'], $codes, true) || ($city !== null && $entry['city'] !== $city) || ($variant !== null && $entry['variant'] !== $variant)) {
                continue;
            }
            $byForm[$entry['form']][] = $entry + ['installed' => $this->installed($entry)];
        }
        $papers = [];
        foreach (config('papers.forms', []) as $id => $form) {
            if (! isset($byForm[$id])) {
                continue;
            }
            $title = self::title($id);
            if ($q !== '' && ! str_contains(mb_strtolower($title.' '.$form['name_ar'].' '.$id), $q)) {
                continue;
            }
            $files = collect($byForm[$id])->sortBy(fn ($e) => [array_search($e['city'], $codes, true), $e['place'] ?? '', $e['variant'], $e['format']])->values()->all();
            $papers[] = ['id' => $id, 'key' => $form['key'], 'title' => $title, 'group' => $form['group'], 'fillable' => $form['fillable'], 'files' => $files];
        }

        return ['papers' => $papers, 'cities' => $cities, 'installed' => collect(config('papers.files', []))->contains(fn ($e) => $this->installed($e))];
    }

    /** Streams one blank paper after checking the city and recording the download. */
    public function download(WebAccount $account, string $form, string $city, ?string $place, string $variant, string $format): StreamedResponse
    {
        [$entry, $cityId] = $this->resolve($account, $form, $city, $place, $variant, $format);
        $this->audit->record($account->id, 'papers.downloaded', 'succeeded', $cityId, decision: 'paper:'.$form.':'.$city.($place ? '-'.$place : '').':'.$variant.':'.$format);

        return Storage::disk('local')->download(self::DIRECTORY.'/'.$entry['sha256'].'.'.$entry['format'], $this->fileName($entry), [
            'Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    /** The original paper to look at (review page): a PDF as it is, a Word paper as its full-page background picture. Not a download, so not recorded. */
    public function original(WebAccount $account, string $form, string $city, ?string $place, string $variant, string $format): Response
    {
        [$entry] = $this->resolve($account, $form, $city, $place, $variant, $format);
        $disk = Storage::disk('local');
        $path = self::DIRECTORY.'/'.$entry['sha256'].'.'.$entry['format'];
        if ($format === 'pdf') {
            return new Response((string) $disk->get($path), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline', 'X-Content-Type-Options' => 'nosniff']);
        }
        $zip = new \ZipArchive;
        $zip->open($disk->path($path));
        $best = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (preg_match('#^word/media/[^/]+\.png$#', $stat['name']) === 1 && ($best === null || $stat['size'] > $best['size'])) {
                $best = $stat;
            }
        }
        $bytes = $best ? (string) $zip->getFromName($best['name']) : '';
        $zip->close();
        abort_if($bytes === '', 404);

        return new Response($bytes, 200, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * The data of the review page of a redrawn paper: the cities that have its original, the chosen original and the projects of that
     * city (of the stage the paper prints) to redraw it for.
     *
     * @param  array{city?: ?string, project?: ?int}  $filters
     * @return array<string, mixed>
     */
    public function review(WebAccount $account, string $form, array $filters): array
    {
        abort_unless(isset(self::REDRAWN[$form]), 404);
        $permitted = $this->cities($account);
        if ($permitted->isEmpty()) {
            $this->audit->record($account->id, 'access.denied', 'denied');
            abort(403);
        }
        $files = collect(config('papers.files', []))->filter(fn ($e) => $e['form'] === $form && $e['variant'] === 'syrian' && $this->installed($e) && $permitted->contains('code', $e['city']));
        $codes = $files->pluck('city')->unique()->values()->all();
        $city = in_array($filters['city'] ?? null, $codes, true) ? $filters['city'] : ($codes[0] ?? null);
        $original = $city === null ? null : ($files->first(fn ($e) => $e['city'] === $city && $e['format'] === 'pdf' && $e['place'] === null) ?? $files->first(fn ($e) => $e['city'] === $city));
        $cityRow = $city === null ? null : $permitted->firstWhere('code', $city);
        $projects = $cityRow === null ? collect() : DB::table('projects')->where('city_id', $cityRow->id)->where('application_stage', self::REDRAWN[$form])->whereNull('deleted_at')
            ->orderByDesc('id')->limit(60)->get(['id', 'project_number', 'name']);
        $project = in_array((int) ($filters['project'] ?? 0), $projects->pluck('id')->map(fn ($id) => (int) $id)->all(), true) ? (int) $filters['project'] : (int) ($projects->first()->id ?? 0);

        return ['form' => $form, 'title' => self::title($form), 'cities' => $permitted->filter(fn ($c) => in_array($c->code, $codes, true))->values(), 'city' => $city, 'original' => $original, 'projects' => $projects, 'project' => $project ?: null];
    }

    /** @return array{0: array<string, mixed>, 1: int} the catalogue entry and the city id, after the per-city permission check */
    private function resolve(WebAccount $account, string $form, string $city, ?string $place, string $variant, string $format): array
    {
        $entry = collect(config('papers.files', []))->first(fn ($e) => $e['form'] === $form && $e['city'] === $city && ($e['place'] ?? null) === $place
            && $e['variant'] === $variant && $e['format'] === $format);
        $cityId = DB::table('cities')->where('code', $city)->value('id');
        if ($entry === null || $cityId === null) {
            abort(404);
        }
        if (! $this->access->check($account, (int) $cityId, 'projects.view')) {
            $this->audit->record($account->id, 'access.denied', 'denied', (int) $cityId);
            abort(403);
        }
        abort_unless($this->installed($entry), 404);

        return [$entry, (int) $cityId];
    }

    /** The paper's title in the page language: the Arabic name from the catalogue (the papers' own names), the English one from lang. */
    public static function title(string $form): string
    {
        $arabic = (string) config('papers.forms.'.$form.'.name_ar', $form);
        if (app()->isLocale('ar')) {
            return $arabic;
        }
        $key = 'papers.forms.'.$form;
        $title = __($key);

        return $title !== $key ? $title : $arabic;
    }

    /** "F10 سند أمانة - حلب (ادلب) - غير سوري.docx" */
    private function fileName(array $entry): string
    {
        $city = __('papers.cities.'.($entry['place'] ?? $entry['city']));
        $variant = $entry['variant'] === 'syrian' ? '' : ' - '.__('papers.variants.'.$entry['variant']);

        return $entry['form'].' '.self::title($entry['form']).' - '.$city.$variant.'.'.$entry['format'];
    }

    private function installed(array $entry): bool
    {
        return Storage::disk('local')->exists(self::DIRECTORY.'/'.$entry['sha256'].'.'.$entry['format']);
    }
}
