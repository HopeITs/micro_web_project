<?php

declare(strict_types=1);

namespace App\Services\Papers;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The papers the library really offers: the catalogue built from the owner's folder (config/papers.php) changed by what the administrator and
 * media staff added, replaced or removed (owner decision, 23 Nov 2026). The changes live in one small JSON file next to the paper files
 * (storage/app/private/papers/changes.json), so no database table is needed; every change is also written to the audit log by PaperManager.
 *
 * changes.json: { "forms": {"C01": {name_ar, name_en, group}}, "files": [entry...], "removed": [{form, city, place, variant, format}...] }
 *  - a file in "files" with the key of a catalogue file replaces it; any other is a new file;
 *  - "removed" hides a catalogue file (it can be restored); a removed added file is simply forgotten.
 */
final class PaperCatalogue
{
    public const CHANGES = PaperLibrary::DIRECTORY.'/changes.json';

    public const LOCK = PaperLibrary::DIRECTORY.'/.changes.lock';

    /** The identity of a file in the catalogue: one per paper, city, place, version and format. */
    public static function key(array $e): string
    {
        return $e['form'].'|'.$e['city'].'|'.($e['place'] ?? '').'|'.$e['variant'].'|'.$e['format'];
    }

    /** @return array{forms: array<string, array<string, mixed>>, files: list<array<string, mixed>>, removed: list<array<string, mixed>>} */
    public function changes(): array
    {
        $disk = Storage::disk('local');
        $data = $disk->exists(self::CHANGES) ? json_decode((string) $disk->get(self::CHANGES), true) : null;
        $data = is_array($data) ? $data : [];

        return ['forms' => (array) ($data['forms'] ?? []), 'files' => array_values((array) ($data['files'] ?? [])), 'removed' => array_values((array) ($data['removed'] ?? []))];
    }

    /** @return array<string, array<string, mixed>> every paper (catalogue and added), by id such as F10 or C01 */
    public function forms(): array
    {
        $added = [];
        foreach ($this->changes()['forms'] as $id => $form) {
            $added[$id] = ['key' => 'custom_'.strtolower($id), 'name_ar' => (string) $form['name_ar'], 'name_en' => $form['name_en'] ?? null, 'group' => (string) $form['group'], 'fillable' => false, 'custom' => true];
        }

        return array_merge((array) config('papers.forms', []), $added);
    }

    /**
     * Every file on offer, each marked `custom` (added or replacing the original) and `replaces` (it hides an original of the same key).
     *
     * @return list<array<string, mixed>>
     */
    public function files(): array
    {
        $changes = $this->changes();
        $hidden = array_flip(array_map(fn ($e) => self::key($e), $changes['removed']));
        $over = [];
        foreach ($changes['files'] as $file) {
            $over[self::key($file)] = $file + ['place' => null];
        }
        $out = [];
        $base = [];
        foreach ((array) config('papers.files', []) as $entry) {
            $key = self::key($entry);
            $base[$key] = true;
            if (isset($hidden[$key])) {
                continue;
            }
            $out[] = isset($over[$key]) ? $over[$key] + ['custom' => true, 'replaces' => true] : $entry + ['custom' => false, 'replaces' => false];
        }
        foreach ($over as $key => $file) {
            if (! isset($base[$key])) {
                $out[] = $file + ['custom' => true, 'replaces' => false];
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> the catalogue files an administrator hid (they can be restored) */
    public function removed(): array
    {
        $out = [];
        $byKey = [];
        foreach ((array) config('papers.files', []) as $entry) {
            $byKey[self::key($entry)] = $entry;
        }
        foreach ($this->changes()['removed'] as $row) {
            if (isset($byKey[self::key($row)])) {
                $out[] = $byKey[self::key($row)];
            }
        }

        return $out;
    }

    /**
     * Runs a change of changes.json under an exclusive lock, so two people editing at once never lose each other's work.
     *
     * @param  callable(array): array  $change  receives the current changes and returns the new ones
     */
    public function mutate(callable $change): void
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(PaperLibrary::DIRECTORY);
        $lock = fopen($disk->path(self::LOCK), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('The papers list is busy, try again.');
        }
        try {
            $new = $change($this->changes());
            $disk->put(self::CHANGES, json_encode($new, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
