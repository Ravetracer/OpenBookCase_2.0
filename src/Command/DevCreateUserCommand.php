<?php

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates a ready-to-use account on the fly so a developer can log in and test
 * without going through the registration + e-mail-verification flow.
 *
 * The account is created already verified (isVerified=true) so it can log in
 * immediately. Pass --admin to also grant ROLE_ADMIN.
 *
 *   php bin/console app:dev:create-user dev dev@example.com secret123
 *   php bin/console app:dev:create-user            # prompts for the values
 *   php bin/console app:dev:create-user admin admin@example.com secret123 --admin
 */
#[AsCommand(
    name: 'app:dev:create-user',
    description: 'Create a verified user account for local testing (dev)',
)]
class DevCreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::OPTIONAL, 'Username (login identifier)')
            ->addArgument('email', InputArgument::OPTIONAL, 'E-mail address')
            ->addArgument('password', InputArgument::OPTIONAL, 'Plain password (will be hashed)')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Also grant ROLE_ADMIN')
            ->addOption('role', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Extra role(s) to grant, e.g. --role=ROLE_EDITOR', []);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $username = $input->getArgument('username') ?: $io->ask('Username');
        $email = $input->getArgument('email') ?: $io->ask('E-mail');
        $password = $input->getArgument('password') ?: $io->askHidden('Password (plain, will be hashed)');

        if (!$username || !$email || !$password) {
            $io->error('Username, e-mail and password are all required.');

            return Command::INVALID;
        }

        $email = strtolower((string) $email);

        if ($this->userRepository->findOneBy(['username' => $username])) {
            $io->error(sprintf('A user with username "%s" already exists.', $username));

            return Command::FAILURE;
        }
        if ($this->userRepository->findOneBy(['email' => $email])) {
            $io->error(sprintf('A user with e-mail "%s" already exists.', $email));

            return Command::FAILURE;
        }

        $roles = $input->getOption('role');
        if ($input->getOption('admin')) {
            $roles[] = 'ROLE_ADMIN';
        }

        $user = new User();
        $user->username = $username;
        $user->email = $email;
        $user->roles = array_values(array_unique($roles));
        $user->isVerified = true; // skip the e-mail verification step
        $user->setPassword($this->passwordHasher->hashPassword($user, (string) $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            'Created user "%s" (%s)%s — you can log in now.',
            $username,
            $email,
            $roles ? ' with roles: ' . implode(', ', $user->getRoles()) : '',
        ));

        return Command::SUCCESS;
    }
}
