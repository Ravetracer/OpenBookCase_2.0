<?php

namespace App\Command;

use App\Enums\MessageType;
use App\Repository\UserRepository;
use App\Service\MessageService;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Send a system message to one user or to everyone. Doubles as the verification
 * tool and an interim admin utility until the automated watchlist/wishlist
 * triggers (which will call MessageService directly) land.
 */
#[AsCommand(
    name: 'app:message:send',
    description: 'Send a system message to a user (or --all users), honouring each recipient\'s notification channel',
)]
class SendSystemMessageCommand extends Command
{
    public function __construct(
        private readonly MessageService $messageService,
        private readonly UserRepository $users,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('username', InputArgument::OPTIONAL, 'Recipient username (omit when using --all)')
            ->addArgument('body', InputArgument::OPTIONAL, 'Message body')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Optional subject line')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Message type: ' . implode(', ', array_column(MessageType::cases(), 'value')), MessageType::Update->value)
            ->addOption('all', null, InputOption::VALUE_NONE, 'Send to every user');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $type = MessageType::tryFrom((string) $input->getOption('type'));
        if ($type === null) {
            $io->error('Unknown --type. Allowed: ' . implode(', ', array_column(MessageType::cases(), 'value')));

            return Command::INVALID;
        }

        $body = $input->getArgument('body');
        if ($body === null || trim($body) === '') {
            $io->error('A message body is required.');

            return Command::INVALID;
        }

        $subject = $input->getOption('subject');
        $subject = $subject !== null ? (string) $subject : null;

        if ($input->getOption('all')) {
            $recipients = $this->users->findAll();
            if (count($recipients) === 0) {
                $io->warning('No users found.');

                return Command::SUCCESS;
            }
            $this->messageService->notifyMany($recipients, $body, $type, $subject);
            $io->success(sprintf('Sent "%s" to %d user(s).', $type->value, count($recipients)));

            return Command::SUCCESS;
        }

        $username = $input->getArgument('username');
        if ($username === null) {
            $io->error('Provide a username, or use --all.');

            return Command::INVALID;
        }

        $user = $this->users->findOneBy(['username' => $username]);
        if ($user === null) {
            $io->error(sprintf('No user with username "%s".', $username));

            return Command::FAILURE;
        }

        $this->messageService->notify($user, $body, $type, $subject);
        $io->success(sprintf('Sent "%s" message to %s (channel: %s).', $type->value, $username, $user->notificationChannel->value));

        return Command::SUCCESS;
    }
}
