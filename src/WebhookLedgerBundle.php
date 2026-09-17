<?php

declare(strict_types=1);

namespace WebhookLedger;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use WebhookLedger\Domain\Contract\SourceAdapterInterface;
use WebhookLedger\Infrastructure\DependencyInjection\Compiler\EnforceOutboxConnectionPass;

final class WebhookLedgerBundle extends AbstractBundle
{
    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/../config/services.yaml');

        $builder->registerForAutoconfiguration(SourceAdapterInterface::class)
            ->addTag('webhook_ledger.source_adapter');
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new EnforceOutboxConnectionPass());
    }

    public function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->import(__DIR__ . '/Presentation/Controller/', 'attribute');
    }
}
