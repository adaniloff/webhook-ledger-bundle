<?php

declare(strict_types=1);

namespace WebhookLedger\Infrastructure\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use WebhookLedger\Application\Worker\Message\ProcessWebhookEvent;

final class EnforceOutboxConnectionPass implements CompilerPassInterface
{
    private const WHITELISTED_DRIVERS = [
        'firebird',
        'mssql',
        'pgsql',
        'postgres',
        'sqlite',
        'sqlsrv',
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('messenger.senders_locator')) {
            return;
        }

        $transport = $this->resolveOutboxTransportName(container: $container);
        if (null === $transport || !$container->hasDefinition('messenger.transport.' . $transport)) {
            return;
        }

        $connection = $this->resolveConnectionName(dsn: $this->resolveDsn(
            container: $container,
            transportName: $transport,
        ));
        if (null === $connection) {
            return;
        }

        $emConnection = $container->hasParameter('doctrine.default_connection')
            ? $container->getParameter('doctrine.default_connection')
            : 'default';
        $emConnection = is_string($emConnection) ? $emConnection : 'default';

        if ($emConnection !== $connection) {
            throw new LogicException(sprintf(
                'webhook-ledger: Messenger transport "%s" (used for %s) targets Doctrine connection "%s", which differs from "%s" (used by the EntityManager). The transport routed for ProcessWebhookEvent must be a doctrine://%s DSN for the transactional outbox to hold.',
                $transport,
                ProcessWebhookEvent::class,
                $connection,
                $emConnection,
                $emConnection,
            ));
        }

        if (!$this->isDriverWhitelisted(container: $container, connectionName: $emConnection)) {
            $this->assertAutoSetupDisabled(container: $container, transportName: $transport);

            $failureTransport = $this->resolveFailureTransportName(container: $container, transportName: $transport);
            if (null !== $failureTransport) {
                $this->assertAutoSetupDisabled(container: $container, transportName: $failureTransport);
            }
        }
    }

    private function resolveOutboxTransportName(ContainerBuilder $container): ?string
    {
        $mapping = $container->getDefinition('messenger.senders_locator')->getArgument(0);
        if (!is_array($mapping)) {
            return null;
        }

        $types = [
            ProcessWebhookEvent::class,
            ...(class_parents(ProcessWebhookEvent::class) ?: []),
            ...(class_implements(ProcessWebhookEvent::class) ?: []),
            '*',
        ];

        foreach ($types as $type) {
            $senders = $mapping[$type] ?? null;
            $sender = is_array($senders) ? ($senders[0] ?? null) : null;

            if (is_string($sender)) {
                return $sender;
            }
        }

        return null;
    }

    private function resolveConnectionName(string $dsn): ?string
    {
        if (!str_starts_with($dsn, 'doctrine://')) {
            return null;
        }

        $host = parse_url($dsn, \PHP_URL_HOST);

        return is_string($host) && '' !== $host ? $host : null;
    }

    private function resolveDsn(ContainerBuilder $container, string $transportName): string
    {
        $dsn = $container->getDefinition('messenger.transport.' . $transportName)->getArgument(0);
        if (!is_string($dsn)) {
            return '';
        }

        $resolved = $container->resolveEnvPlaceholders($dsn, true);

        return is_string($resolved) ? $resolved : '';
    }

    private function assertAutoSetupDisabled(ContainerBuilder $container, string $transportName): void
    {
        $dsn = $this->resolveDsn(container: $container, transportName: $transportName);
        if (!str_starts_with($dsn, 'doctrine://')) {
            return;
        }

        $query = [];
        parse_str((string) parse_url($dsn, \PHP_URL_QUERY), $query);

        $optionsArg = $container->getDefinition('messenger.transport.' . $transportName)->getArgument(1);
        $options = is_array($optionsArg) ? $optionsArg : [];

        if (filter_var($query['auto_setup'] ?? $options['auto_setup'] ?? true, \FILTER_VALIDATE_BOOL)) {
            throw new LogicException(sprintf(
                'webhook-ledger: Messenger transport "%s" must run with auto_setup=0 in its DSN, otherwise Messenger may implicitly run a DDL statement to create the messenger_messages table, which can break the transactional outbox atomicity on engines without transactional DDL.',
                $transportName,
            ));
        }
    }

    private function isDriverWhitelisted(ContainerBuilder $container, string $connectionName): bool
    {
        $driver = $this->resolveActiveDbalDriver(container: $container, connectionName: $connectionName);
        if (null === $driver) {
            return false;
        }

        foreach (self::WHITELISTED_DRIVERS as $marker) {
            if (str_contains($driver, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function resolveActiveDbalDriver(ContainerBuilder $container, string $connectionName): ?string
    {
        $connectionServiceId = 'doctrine.dbal.' . $connectionName . '_connection';
        if (!$container->hasDefinition($connectionServiceId)) {
            return null;
        }

        $options = $container->getDefinition($connectionServiceId)->getArgument(0);
        if (!is_array($options)) {
            return null;
        }

        $candidate = $options['url'] ?? $options['driver'] ?? null;
        if (!is_string($candidate)) {
            return null;
        }

        $resolved = $container->resolveEnvPlaceholders($candidate, true);
        $value = is_string($resolved) ? $resolved : $candidate;

        $scheme = parse_url($value, \PHP_URL_SCHEME);

        return strtolower(is_string($scheme) ? $scheme : $value);
    }

    private function resolveFailureTransportName(ContainerBuilder $container, string $transportName): ?string
    {
        $listenerId = 'messenger.failure.send_failed_message_to_failure_transport_listener';
        if (!$container->hasDefinition($listenerId)) {
            return null;
        }

        $failureTransportsByName = $container->getDefinition($listenerId)->getArgument(2);
        if (!is_array($failureTransportsByName)) {
            return null;
        }

        $failureTransport = $failureTransportsByName[$transportName] ?? null;

        return is_string($failureTransport) && $container->hasDefinition('messenger.transport.' . $failureTransport)
            ? $failureTransport
            : null;
    }
}
