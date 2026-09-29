<?php

namespace App\Console\Commands;

use App\Services\Integrations\IntegrationManager;
use App\Services\Zenoti\ZenotiSource;
use Illuminate\Console\Command;

class SyncIntegration extends Command
{
    protected $signature = 'integrations:sync
        {provider? : zenoti or callgear (default: every configured provider)}
        {--entity= : Only one entity, e.g. employees}
        {--days= : How many days back to pull (use a large number for the first import)}
        {--all-guests : Fetch every missing guest profile in this run, not just a few minutes\' worth}
        {--tag= : Only fetch guest profiles for guests of employees with this tag (served, booked or billed), e.g. --tag=Callgear}
        {--branch= : Only this branch (name or id)}';

    protected $description = 'Pull the latest data from Zenoti / CallGear into the local database';

    public function handle(IntegrationManager $integrations): int
    {
        $sources = $this->argument('provider')
            ? [$integrations->get($this->argument('provider'))]
            : $integrations->all();

        foreach ($sources as $source) {
            if (! $source->isConfigured()) {
                $this->warn("{$source->label()}: not configured, skipped.");

                continue;
            }
            if (($source instanceof ZenotiSource || $source instanceof \App\Services\CallGear\CallGearSource) && $this->option('days')) {
                $source->withLookbackDays((int) $this->option('days'));
            }
            if ($source instanceof ZenotiSource && $this->option('all-guests')) {
                $source->withAllGuestProfiles();
            }
            if ($source instanceof ZenotiSource && ($this->option('tag') || $this->option('branch'))) {
                $branch = $this->option('branch') ? \App\Models\Branch::where('name', $this->option('branch'))->orWhere('id', $this->option('branch'))->first() : null;
                if ($this->option('branch') && ! $branch) {
                    $this->error("No branch called \"{$this->option('branch')}\".");

                    return self::FAILURE;
                }
                try {
                    $this->info('Only '.$source->scopeTo($branch?->id, $this->option('tag')));
                } catch (\InvalidArgumentException $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }
            }
            foreach ($source->sync($this->option('entity')) as $run) {
                $this->line(sprintf('%s %-13s %-8s +%d ~%d -%d %s',
                    $source->label(), $run->entity, $run->status, $run->created_count, $run->updated_count, $run->deactivated_count, $run->message));
            }
        }

        return self::SUCCESS;
    }
}
