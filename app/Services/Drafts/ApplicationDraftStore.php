<?php

declare(strict_types=1);

namespace App\Services\Drafts;

use App\Models\WebAccount;
use App\Services\Applications\ApplicationService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Draft recovery for the application form (M4.5; delivery rule "online operation with draft recovery"). The whole form state is kept
 * encrypted with the application key on the private local disk, one file per draft under the account's own folder, so only that account
 * reads it back. A draft is never a committed record: it is deleted once the application is saved and expires after RETENTION_DAYS.
 */
final class ApplicationDraftStore
{
    public const RETENTION_DAYS = 7;

    private const MAX_BYTES = 262144;

    public function __construct(private ApplicationService $applications) {}

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    private function path(WebAccount $account, string $id): string
    {
        preg_match('/\A[a-z0-9-]{8,64}\z/', $id) === 1 || abort(404);

        return 'application-drafts/'.(int) $account->id.'/'.$id.'.draft';
    }

    /** A new draft id; an application being edited always uses the same one, so it has a single draft. */
    public static function newId(?int $projectId = null): string
    {
        return $projectId ? 'project-'.$projectId : (string) Str::uuid();
    }

    /** Saves the form state; refuses a state too large or of a city the account may not write. */
    public function save(WebAccount $account, string $id, array $state, ?int $projectId): void
    {
        $this->applications->canWrite($account, (int) ($state['city'] ?? 0)) || abort(403);
        $json = json_encode(['state' => $state, 'project_id' => $projectId, 'saved_at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BYTES) {
            return; // too large to keep: the form still works, only recovery is skipped
        }
        $this->disk()->put($this->path($account, $id), Crypt::encryptString($json));
    }

    /** @return array{state: array, project_id: ?int, saved_at: string}|null */
    public function load(WebAccount $account, string $id): ?array
    {
        $path = $this->path($account, $id);
        if (! $this->disk()->exists($path)) {
            return null;
        }
        try {
            $draft = json_decode(Crypt::decryptString($this->disk()->get($path)), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->disk()->delete($path); // unreadable (e.g. the key changed): nothing to recover

            return null;
        }
        // Permissions may have changed since the draft was written.
        if (! $this->applications->canWrite($account, (int) ($draft['state']['city'] ?? 0))) {
            return null;
        }

        return $draft;
    }

    public function discard(WebAccount $account, string $id): void
    {
        $this->disk()->delete($this->path($account, $id));
    }

    /**
     * The account's drafts, newest first, after removing expired ones.
     *
     * @return list<array{id: string, project_id: ?int, city: int, number: string, name: string, person: string, saved_at: string}>
     */
    public function listing(WebAccount $account): array
    {
        $out = [];
        foreach ($this->disk()->files('application-drafts/'.(int) $account->id) as $file) {
            if ($this->disk()->lastModified($file) < now()->subDays(self::RETENTION_DAYS)->getTimestamp()) {
                $this->disk()->delete($file);

                continue;
            }
            $id = basename($file, '.draft');
            $draft = $this->load($account, $id);
            if ($draft === null) {
                continue;
            }
            $s = $draft['state'];
            $out[] = [
                'id' => $id, 'project_id' => $draft['project_id'], 'city' => (int) $s['city'], 'number' => (string) ($s['number'] ?? ''),
                'name' => (string) ($s['project']['name'] ?? ''), 'person' => trim(($s['person']['first_name'] ?? '').' '.($s['person']['father_name'] ?? '').' '.($s['person']['last_name'] ?? '')),
                'saved_at' => (string) $draft['saved_at'],
            ];
        }
        usort($out, fn ($a, $b) => strcmp($b['saved_at'], $a['saved_at']));

        return $out;
    }
}
