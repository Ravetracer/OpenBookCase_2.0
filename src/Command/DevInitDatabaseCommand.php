<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates a clean, empty database with the current schema — the starting point
 * for a developer picking up the project.
 *
 * The schema is built from the entity mappings (doctrine:schema:create), NOT by
 * replaying migrations: this project's migrations are *incremental* (the very
 * first one ALTERs an already-existing `bookcase` table — the original schema
 * came from the legacy SQL dump), so they cannot build a database from scratch.
 * After creating the schema we mark every migration as already applied, so a
 * later `doctrine:migrations:migrate` is a clean no-op and future migrations
 * still work. DB-agnostic: works for the default SQLite file as well as a
 * MySQL/Postgres DATABASE_URL.
 *
 * This is a DEV helper. It is destructive: prompts for confirmation when run
 * interactively, and proceeds without asking when run with -n / --no-interaction.
 */
#[AsCommand(
    name: 'app:dev:db-init',
    description: 'Drop and recreate a clean database with the current schema (dev setup)',
)]
class DevInitDatabaseCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->isInteractive()) {
            $io->warning('This DROPS the entire database and recreates it from the migrations. All data is lost.');
            if (!$io->confirm('Continue?', false)) {
                $io->note('Aborted — nothing changed.');

                return Command::SUCCESS;
            }
        }

        // NB: do NOT use --if-exists/--if-not-exists here — those flags make
        // Doctrine call listDatabases(), which SQLite does not support
        // (throws NotSupported). Instead the drop is best-effort: an empty/
        // missing database is fine, we just want a clean slate afterwards.
        $io->section('doctrine:database:drop');
        try {
            $drop = new ArrayInput(['--force' => true]);
            $drop->setInteractive(false);
            $this->getApplication()->find('doctrine:database:drop')->run($drop, $output);
        } catch (\Throwable $e) {
            $io->note('Nothing to drop (' . $e->getMessage() . ').');
        }

        // Create the database. Best-effort too: SQLite creates the file lazily
        // on connect (and getCreateDatabaseSQL throws NotSupported), so this is
        // a no-op there — but it IS needed for MySQL/Postgres.
        $io->section('doctrine:database:create');
        try {
            $create = new ArrayInput([]);
            $create->setInteractive(false);
            $this->getApplication()->find('doctrine:database:create')->run($create, $output);
        } catch (\Throwable $e) {
            $io->note('Skipped (' . $e->getMessage() . ').');
        }

        // These must all succeed: build the schema from the entity mappings,
        // then record every migration as already applied so the history is
        // consistent (a later `migrate` is a no-op; new migrations still run).
        $required = [
            ['doctrine:schema:create', []],
            ['doctrine:migrations:sync-metadata-storage', []],
            ['doctrine:migrations:version', ['--add' => true, '--all' => true, '--no-interaction' => true]],
        ];

        foreach ($required as [$name, $args]) {
            $io->section($name);
            $sub = new ArrayInput($args);
            $sub->setInteractive(false);
            if (($code = $this->getApplication()->find($name)->run($sub, $output)) !== Command::SUCCESS) {
                $io->error(sprintf('Step "%s" failed (exit code %d). Stopping.', $name, $code));

                return $code;
            }
        }

        $io->success('Clean database ready. Run "php bin/console app:dev:fixtures" to add sample data.');

        return Command::SUCCESS;
    }
}
