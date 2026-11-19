<?php

namespace App\Console\Commands;

use App\Services\Transfer\Reconciler;
use App\Services\Transfer\UnifiedImporter;
use Illuminate\Console\Command;

/**
 * M3: php artisan unified:transfer LAT --source-db=m1src_lat [--archive=path.zip] [--reconcile-only] [--report=path.json]
 * Imports one restored desktop city database into the unified schema, then runs the independent reconciler.
 */
class UnifiedTransfer extends Command
{
    protected $signature = 'unified:transfer {city : ALP, DAM, HMS, LAT or WAD} {--source-db= : restored desktop database (read with the SELECT-only source account)}
        {--archive= : backup archive the source was restored from (its SHA-256 is recorded)} {--reconcile-only} {--report= : write the reconciliation JSON here}';

    protected $description = 'Transfer one desktop city database into the unified schema and reconcile it';

    public function handle(UnifiedImporter $importer, Reconciler $reconciler): int
    {
        if (app()->isProduction()) {
            $this->error('Run transfers only on an isolated target database.');

            return self::FAILURE;
        }
        $city = strtoupper((string) $this->argument('city'));
        $db = (string) $this->option('source-db');
        ini_set('memory_limit', '2G');
        if (! $this->option('reconcile-only')) {
            $started = microtime(true);
            $stats = $importer->run($city, $db, $this->option('archive'), fn (string $m) => $this->line('  '.$m));
            $this->info(sprintf('%s: run %d finished in %.1fs, %d rows written, %d exceptions', $city, $stats['run'], microtime(true) - $started,
                array_sum($stats['written']), array_sum($stats['exceptions'])));
        }
        $report = $reconciler->check($city, $db);
        $this->table(['status', 'checks'], collect($report['summary'])->map(fn ($n, $s) => [$s, $n])->values()->all());
        foreach ($report['checks'] as $c) {
            if ($c['status'] === 'unexplained') {
                $this->warn("unexplained: {$c['group']} {$c['check']} source={$c['source']} target={$c['target']} explained={$c['explained']}");
            }
        }
        if ($path = $this->option('report')) {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->line("report: {$path}");
        }

        return $report['summary']['unexplained'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
