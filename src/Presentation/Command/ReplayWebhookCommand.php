<?php

declare(strict_types=1);

namespace WebhookLedger\Presentation\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use WebhookLedger\Application\Receiver\Exception\WebhookNotFoundException;
use WebhookLedger\Application\Receiver\Exception\WebhookOutdatedException;
use WebhookLedger\Application\Receiver\Service\Receiver;
use WebhookLedger\Domain\Exception\WebhookNotReplayableException;
use WebhookLedger\Domain\ValueObject\WebhookUuid;

#[AsCommand(
    name: 'webhook-ledger:replay',
    description: 'Replay a failed or dead webhook.',
)]
final class ReplayWebhookCommand extends Command
{
    public function __construct(private Receiver $receiver)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('uuid', InputArgument::REQUIRED, 'The webhook uuid, (string, ex: beac9249-8564-4344-a1f8-5f4826061e2b')
            ->addArgument('version', InputArgument::REQUIRED, 'The webhook version (integer, ex: 1, 5 or 11)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $error = 0;
        $uuid = (string) $input->getArgument('uuid'); // @phpstan-ignore-line
        if (!Uuid::isValid((string) $uuid)) {
            $io->error(sprintf('Not a valid uuid: %s', $uuid));
            ++$error;
        }

        $version = (int) $input->getArgument('version'); // @phpstan-ignore-line
        if ($version < 1) {
            $io->error(sprintf('Not a valid version: %s', $version));
            ++$error;
        }

        if (0 < $error) {
            return Command::INVALID;
        }

        try {
            $this->receiver->replay(uuid: WebhookUuid::fromString($uuid), version: $version);
        } catch (WebhookNotFoundException $e) {
            $io->error(sprintf('No webhook found for uuid: %s', $e->getIdentifier()));

            return Command::INVALID;
        } catch (WebhookOutdatedException $t) {
            $io->error(sprintf('The webhook has already been updated'));
            $io->warning(sprintf('Previous error: %s', $t->getPrevious()?->getMessage() ?? ''));

            return Command::FAILURE;
        } catch (WebhookNotReplayableException $t) {
            $io->error(sprintf('This webhook cannot be replayed.'));

            return Command::FAILURE;
        } catch (\Throwable $t) {
            $io->error(sprintf('Unexpected error: %s', $t->getMessage()));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
