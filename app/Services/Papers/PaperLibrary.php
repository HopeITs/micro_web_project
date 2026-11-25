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
    public const REDRAWN = [
        'F02' => ['applications.print', []], 'F03' => ['applications.print', []],
        'F04' => ['applications.paper', ['paper' => 'audio-consent']], 'F05' => ['applications.paper', ['paper' => 'special-case']], 'F06' => ['applications.paper', ['paper' => 'experience-test']],
        'F08' => ['applications.purchase-paper', ['paper' => 'price-quote']], 'F09' => ['applications.purchase-paper', ['paper' => 'purchase-request']],
        'F10' => ['applications.finance-paper', ['paper' => 'promissory-note']],
        'F11' => ['applications.deeds.print', ['kind' => 'tools']], 'F12' => ['applications.deeds.print', ['kind' => 'tools_agri']], 'F13' => ['applications.deeds.print', ['kind' => 'solar']],
        'F14' => ['applications.deeds.print', ['kind' => 'solar_agri']], 'F15' => ['applications.deeds.print', ['kind' => 'vehicles']],
        'F16' => ['applications.sale-contracts.print', ['kind' => 'car']], 'F17' => ['applications.sale-contracts.print', ['kind' => 'device']],
        'F18' => ['applications.finance-paper', ['paper' => 'partnership-contract']], 'F19' => ['applications.finance-paper', ['paper' => 'disbursement-request']],
        'F20' => ['applications.purchase-paper', ['paper' => 'equipment-receipt']], 'F21' => ['applications.finance-paper', ['paper' => 'beneficiary-card']],
        'F22' => ['applications.purchase-paper', ['paper' => 'equipment-return']], 'F23' => ['applications.finance-paper', ['paper' => 'clearance']],
        'F24' => ['applications.paper', ['paper' => 'photo-adult']], 'F25' => ['applications.paper', ['paper' => 'photo-child']], 'F27' => ['applications.paper', ['paper' => 'photo-informed']],
    ];

    /** The application forms are redrawn for a stage; every other paper is compared on a full application. */
    private const STAGE = ['F02' => 'preliminary'];

    /** The consents that print only for Caritas projects. */
    private const CARITAS_ONLY = ['F24', 'F25'];

    public const GROUPS = ['registration', 'study', 'financing', 'legal', 'purchasing', 'consents', 'meetings', 'training', 'handouts'];

    public function __construct(private AccountAccess $access, private AccountAuditTrail $audit, private PaperCatalogue $catalogue) {}

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
        foreach ($this->catalogue->files() as $entry) {
            if (! in_array($entry['city'], $codes, true) || ($city !== null && $entry['city'] !== $city) || ($variant !== null && $entry['variant'] !== $variant)) {
                continue;
            }
            $byForm[$entry['form']][] = $entry + ['installed' => $this->installed($entry)];
        }
        $papers = [];
        foreach ($this->catalogue->forms() as $id => $form) {
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

        // The people who keep the papers (papers.manage) also see what they may do: the cities they manage, the paper ids to add to, and the hidden originals.
        $manage = app(PaperManager::class)->cities($account)->pluck('code')->all();
        $hidden = array_values(array_filter($this->catalogue->removed(), fn ($e) => in_array($e['city'], $manage, true)));

        return ['papers' => $papers, 'cities' => $cities, 'installed' => collect($this->catalogue->files())->contains(fn ($e) => $this->installed($e)),
            'manage' => $manage, 'hidden' => $hidden, 'forms' => $this->catalogue->forms()];
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
        $files = collect($this->catalogue->files())->filter(fn ($e) => $e['form'] === $form && $e['variant'] === 'syrian' && $this->installed($e) && $permitted->contains('code', $e['city']));
        $codes = $files->pluck('city')->unique()->values()->all();
        $city = in_array($filters['city'] ?? null, $codes, true) ? $filters['city'] : ($codes[0] ?? null);
        $original = $city === null ? null : ($files->first(fn ($e) => $e['city'] === $city && $e['format'] === 'pdf' && $e['place'] === null) ?? $files->first(fn ($e) => $e['city'] === $city));
        $cityRow = $city === null ? null : $permitted->firstWhere('code', $city);
        $projects = $cityRow === null ? collect() : DB::table('projects')->where('city_id', $cityRow->id)->where('application_stage', self::STAGE[$form] ?? 'full')->whereNull('deleted_at')
            ->when(in_array($form, self::CARITAS_ONLY, true), fn ($q) => $q->whereIn('donor_id', DB::table('donors')->where(fn ($d) => $d->where('name_en', 'like', '%aritas%')->orWhere('name_ar', 'like', '%aritas%')->orWhere('name_ar', 'like', '%كاريتاس%'))->select('id')))
            ->orderByDesc('id')->limit(60)->get(['id', 'project_number', 'name']);
        $project = in_array((int) ($filters['project'] ?? 0), $projects->pluck('id')->map(fn ($id) => (int) $id)->all(), true) ? (int) $filters['project'] : (int) ($projects->first()->id ?? 0);

        [$route, $with] = self::REDRAWN[$form];
        $redraw = $project ? route($route, ['locale' => app()->getLocale(), 'project' => $project] + $with) : null;

        return ['form' => $form, 'title' => self::title($form), 'cities' => $permitted->filter(fn ($c) => in_array($c->code, $codes, true))->values(), 'city' => $city, 'original' => $original, 'projects' => $projects, 'project' => $project ?: null, 'redraw' => $redraw];
    }

    /** @return array{0: array<string, mixed>, 1: int} the catalogue entry and the city id, after the per-city permission check */
    private function resolve(WebAccount $account, string $form, string $city, ?string $place, string $variant, string $format): array
    {
        $entry = collect($this->catalogue->files())->first(fn ($e) => $e['form'] === $form && $e['city'] === $city && ($e['place'] ?? null) === $place
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
        $added = app(PaperCatalogue::class)->forms()[$form] ?? null;
        $arabic = (string) ($added['name_ar'] ?? config('papers.forms.'.$form.'.name_ar', $form));
        if (app()->isLocale('ar')) {
            return $arabic;
        }
        if (! empty($added['name_en'])) {
            return (string) $added['name_en'];
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
