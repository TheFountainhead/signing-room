<?php

namespace Fountainhead\SigningRoom\Console\Commands;

use Fountainhead\SigningRoom\Enums\SigningPartyStatus;
use Fountainhead\SigningRoom\Models\SigningParty;
use Fountainhead\SigningRoom\Services\IduraSignatureService;
use Illuminate\Console\Command;

class BackfillCprFromIdura extends Command
{
    protected $signature = 'signing-room:backfill-cpr';

    protected $description = 'Backfill CPR hash from Idura evidence for signed parties missing cpr_hash';

    public function handle(IduraSignatureService $idura): int
    {
        $parties = SigningParty::where('status', SigningPartyStatus::Signed)
            ->whereNotNull('idura_signatory_id')
            ->whereNull('cpr_hash')
            ->get();

        if ($parties->isEmpty()) {
            $this->info('No signed parties missing cpr_hash.');

            return self::SUCCESS;
        }

        $this->info("Found {$parties->count()} signed parties without cpr_hash.");

        $success = 0;
        $failed = 0;

        foreach ($parties as $party) {
            $this->line("  {$party->name} ({$party->email}) — fetching evidence...");

            $cpr = $idura->getSignatoryEvidence($party->idura_signatory_id);

            if ($cpr) {
                $party->cpr = $cpr;
                $party->save();
                $this->info("    ✓ CPR stored");
                $success++;
            } else {
                $this->warn("    ✗ No CPR available (evidence may have expired)");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done. {$success} backfilled, {$failed} failed.");

        return self::SUCCESS;
    }
}
