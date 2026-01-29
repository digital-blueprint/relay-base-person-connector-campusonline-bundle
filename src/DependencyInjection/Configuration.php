<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\DependencyInjection;

use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Cron\CacheRefreshCronJob;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\EventSubscriber\PersonEventSubscriber;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public const ROOT_NODE = 'dbp_relay_base_person_connector_campusonline';
    public const DATABASE_URL = 'database_url';
    public const CAMPUS_ONLINE_NODE = 'campus_online';
    public const BASE_URL_NODE = 'base_url';
    public const CLIENT_ID_NODE = 'client_id';
    public const CLIENT_SECRET_NODE = 'client_secret';
    public const CACHE_REFRESH_INTERVAL_NODE = 'cache_refresh_interval';

    private const DATABASE_URL_DEFAULT = 'sqlite:///%kernel.project_dir%/var/persons_cache.db';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT_NODE);
        $treeBuilder->getRootNode()
            ->children()
            ->scalarNode(self::DATABASE_URL)
            ->defaultValue(self::DATABASE_URL_DEFAULT)
            ->end()
            ->arrayNode(self::CAMPUS_ONLINE_NODE)
            ->children()
            ->scalarNode(self::BASE_URL_NODE)->end()
            ->scalarNode(self::CLIENT_ID_NODE)->end()
            ->scalarNode(self::CLIENT_SECRET_NODE)->end()
            ->end()
            ->end()
            ->scalarNode(self::CACHE_REFRESH_INTERVAL_NODE)
            ->defaultValue(CacheRefreshCronJob::DEFAULT_INTERVAL)
            ->end()
            ->append(PersonEventSubscriber::getLocalDataMappingConfigNodeDefinition())
            ->end();

        return $treeBuilder;
    }
}
