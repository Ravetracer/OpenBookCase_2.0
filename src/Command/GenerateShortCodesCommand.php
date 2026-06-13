<?php

namespace App\Command;

use App\Service\ShortCodeGenerator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backfills a unique short_code for every bookcase that doesn't have one yet
 * (existing rows after the migration). New bookcases get a code on creation, so
 * this only needs to run once after deploying the feature.
 */
#[AsCommand(
    name: 'app:generate-short-codes',
    description: 'Assign a unique short share code to every bookcase that lacks one',
)]
class GenerateShortCodesCommand extends Command
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly Connection $conn,
        private readonly ShortCodeGenerator $generator,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Seed the in-memory set with codes already in use so we never collide.
        $taken = [];
        foreach ($this->conn->executeQuery('SELECT short_code FROM bookcase WHERE short_code IS NOT NULL')->iterateColumn() as $code) {
            $taken[$code] = true;
        }

        $ids = $this->conn->executeQuery('SELECT id FROM bookcase WHERE short_code IS NULL')->fetchFirstColumn();
        $total = count($ids);
        if ($total === 0) {
            $output->writeln('<info>All bookcases already have a short code. Nothing to do.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Generating short codes for <info>%d</info> bookcases …', $total));
        $bar = new ProgressBar($output, $total);
        $bar->start();

        $this->conn->executeStatement('PRAGMA synchronous = NORMAL');
        $this->conn->beginTransaction();
        $batch = 0;

        foreach ($ids as $id) {
            $code = $this->generator->randomUniqueIn($taken);
            $taken[$code] = true;

            $this->conn->executeStatement(
                'UPDATE bookcase SET short_code = ? WHERE id = ?',
                [$code, $id],
                [ParameterType::STRING, ParameterType::BINARY],
            );

            if (++$batch >= self::BATCH_SIZE) {
                $batch = 0;
                $this->conn->commit();
                $this->conn->beginTransaction();
            }
            $bar->advance();
        }

        $this->conn->commit();
        $bar->finish();
        $output->writeln(sprintf("\n<info>Done.</info> Assigned <info>%d</info> short codes.", $total));

        return Command::SUCCESS;
    }
}
