<?php

declare(strict_types=1);

namespace WebhookLedger\Tests\Fixtures;

use Composer\InstalledVersions;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use WebhookLedger\WebhookLedgerBundle;
use Zenstruck\Foundry\ZenstruckFoundryBundle;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new DoctrineBundle();
        yield new ZenstruckFoundryBundle();
        yield new WebhookLedgerBundle();
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'test',
            'messenger' => [
                'transports' => [
                    'async' => '%env(MESSENGER_TRANSPORT_DSN)%',
                ],
                'routing' => [
                    'WebhookLedger\Application\Worker\Message\ProcessWebhookEvent' => 'async',
                ],
            ],
        ]);

        $ormConfig = [
            'naming_strategy' => 'doctrine.orm.naming_strategy.underscore_number_aware',
            'auto_mapping' => false,
            'mappings' => [
                'WebhookLedger' => [
                    'type' => 'attribute',
                    'is_bundle' => false,
                    'dir' => __DIR__ . '/../../src/Infrastructure/Doctrine/Entity',
                    'prefix' => 'WebhookLedger\Infrastructure\Doctrine\Entity',
                    'alias' => 'WebhookLedger',
                ],
            ],
        ];

        // doctrine-bundle 3.x (Symfony 8) always enables lazy ghost objects for ORM 3
        // and dropped the toggle; doctrine-bundle 2.x (Symfony 7) still requires it explicit.
        $doctrineBundleVersion = InstalledVersions::getVersion('doctrine/doctrine-bundle') ?? '0';
        if (version_compare($doctrineBundleVersion, '3.0.0', '<')) {
            $ormConfig['auto_generate_proxy_classes'] = true;
            $ormConfig['enable_lazy_ghost_objects'] = true;
        }

        $container->extension('doctrine', [
            'dbal' => [
                'url' => '%env(resolve:DATABASE_URL)%',
            ],
            'orm' => $ormConfig,
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void {}

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/webhook-ledger-bundle/cache/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/webhook-ledger-bundle/log';
    }
}
