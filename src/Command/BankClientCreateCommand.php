<?php

namespace App\Command;

use App\Application\Security\BankClientProvisionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:bank-client:create',
    description: 'Crea credenciales client_id/client_secret para un banco y ambiente.'
)]
final class BankClientCreateCommand extends Command
{
    public function __construct(
        private readonly BankClientProvisionService $provisionService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('bank', null, InputOption::VALUE_REQUIRED, 'Código del banco')
            ->addOption('environment', null, InputOption::VALUE_REQUIRED, 'Ambiente: QA o PROD')
            ->addOption('label', null, InputOption::VALUE_OPTIONAL, 'Etiqueta descriptiva');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $bank = trim((string) $input->getOption('bank'));
        $environment = trim((string) $input->getOption('environment'));
        $label = $input->getOption('label');

        if ($bank === '' || $environment === '') {
            $io->error('Debes enviar --bank y --environment.');
            return Command::FAILURE;
        }

        try {
            $result = $this->provisionService->create(
                $bank,
                $environment,
                $label !== null ? (string) $label : null
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success('Credencial creada exitosamente.');
        $io->writeln('bank_code: ' . $result['bank_code']);
        $io->writeln('bank_name: ' . $result['bank_name']);
        $io->writeln('environment: ' . $result['environment']);
        $io->writeln('client_id: ' . $result['client_id']);
        $io->writeln('client_secret: ' . $result['client_secret']);
        $io->writeln('expires_at: ' . $result['expires_at']);
        $io->warning('El client_secret se mostrará una sola vez. Guárdalo de inmediato.');

        return Command::SUCCESS;
    }
}