<?php

namespace App\Command;

use App\Dto\RuleChange;
use App\Service\Matching\RuleConsolidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:rules:consolidate',
    description: 'Relit les règles apprises à la lumière du corpus actuel et rejoue les suggestions (à planifier périodiquement)',
)]
class ConsolidateRulesCommand extends Command
{
    public function __construct(
        private readonly RuleConsolidator $consolidator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche ce qui changerait sans rien modifier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $report = $dryRun ? $this->consolidator->plan() : $this->consolidator->consolidate();

        $io->section(sprintf('%d token(s) générique(s) détecté(s)', \count($report->genericTokens)));
        if ($output->isVerbose()) {
            $io->text(implode(' ', $report->genericTokens));
        }

        if ($report->changes !== []) {
            $io->section($dryRun ? 'Changements prévus' : 'Changements appliqués');
            $io->table(
                ['Règle', 'Action', 'Avant', 'Après'],
                array_map(static fn (RuleChange $change): array => [
                    $change->rule->getName(),
                    $change->kind->label(),
                    implode(' ', $change->before),
                    implode(' ', $change->after),
                ], $report->changes),
            );
        }

        $dryRun
            ? $io->note(sprintf('Mode simulation : %d règle(s) seraient modifiée(s).', \count($report->changes)))
            : $io->success($report->summary());

        return Command::SUCCESS;
    }
}
