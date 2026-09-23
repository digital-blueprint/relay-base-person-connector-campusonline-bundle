<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Tests;

use Dbp\Relay\BasePersonConnectorCampusonlineBundle\DbpRelayBasePersonConnectorCampusonlineBundle;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\DependencyInjection\Configuration;
use Dbp\Relay\CoreBundle\TestUtils\CoreTestKernelTrait;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Doctrine\Bundle\MigrationsBundle\DoctrineMigrationsBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use CoreTestKernelTrait;

    protected function registerAdditionalBundles(): iterable
    {
        yield new DoctrineBundle();
        yield new DoctrineMigrationsBundle();
        yield new DbpRelayBasePersonConnectorCampusonlineBundle();
    }

    protected function configureAdditionalContainer(ContainerConfigurator $container): void
    {
        $container->extension('dbp_relay_base_person_connector_campusonline', [
            Configuration::DATABASE_URL => 'sqlite:///:memory:',
        ]);
    }
}
