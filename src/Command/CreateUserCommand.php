<?php

namespace App\Command;

use App\Service\UserManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:user:create',
    description: 'Crée (ou met à jour le mot de passe de) l\'utilisateur de l\'application',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly UserManager $userManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Adresse email')
            ->addArgument('password', InputArgument::OPTIONAL, 'Mot de passe (demandé interactivement si absent)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        $password = $input->getArgument('password');

        if ($password === null) {
            $password = $io->askHidden('Mot de passe');
            if ($password === null) {
                $io->error('Un mot de passe est requis.');

                return Command::FAILURE;
            }
        }

        $user = $this->userManager->createOrUpdateUser($email, $password);

        $io->success(sprintf('Utilisateur "%s" prêt.', $user->getEmail()));

        return Command::SUCCESS;
    }
}
