<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Grant (or revoke) ROLE_ADMIN for an existing user, identified by e-mail. Writes
 * the roles column as valid JSON via Doctrine — the safe way to do what hand-editing
 * the database breaks.
 *
 *   php bin/console app:user:grant-admin me@example.com
 *   php bin/console app:user:grant-admin me@example.com --revoke
 */
#[AsCommand(
    name: 'app:user:grant-admin',
    description: 'Grant or revoke ROLE_ADMIN for a user (by e-mail)',
)]
class UserGrantAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'E-mail address of the user')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Remove ROLE_ADMIN instead of granting it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = trim((string) $input->getArgument('email'));
        $revoke = (bool) $input->getOption('revoke');

        // Case-insensitive e-mail lookup (e-mails aren't always stored lowercased).
        $user = $this->entityManager->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('LOWER(u.email) = LOWER(:email)')
            ->setParameter('email', $email)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$user instanceof User) {
            $io->error(sprintf('No user found with e-mail "%s".', $email));

            return Command::FAILURE;
        }

        // Operate on the *stored* roles (ROLE_USER is implicit via User::getRoles).
        $roles = $user->roles;
        $hasAdmin = in_array('ROLE_ADMIN', $roles, true);

        if ($revoke) {
            if (!$hasAdmin) {
                $io->warning(sprintf('"%s" does not have ROLE_ADMIN — nothing to do.', $user->getUserIdentifier()));

                return Command::SUCCESS;
            }
            $roles = array_values(array_filter($roles, static fn (string $r) => $r !== 'ROLE_ADMIN'));
        } else {
            if ($hasAdmin) {
                $io->warning(sprintf('"%s" already has ROLE_ADMIN — nothing to do.', $user->getUserIdentifier()));

                return Command::SUCCESS;
            }
            $roles[] = 'ROLE_ADMIN';
        }

        $user->roles = array_values(array_unique($roles));
        $this->entityManager->flush();

        $io->success(sprintf(
            '%s ROLE_ADMIN for "%s" (%s). Stored roles: %s',
            $revoke ? 'Revoked' : 'Granted',
            $user->getUserIdentifier(),
            $user->email,
            $user->roles ? implode(', ', $user->roles) : '(none)',
        ));

        return Command::SUCCESS;
    }
}
