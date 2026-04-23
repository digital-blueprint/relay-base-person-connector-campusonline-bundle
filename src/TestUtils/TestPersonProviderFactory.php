<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\TestUtils;

use Dbp\Relay\BasePersonConnectorCampusonlineBundle\DependencyInjection\Configuration;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\DependencyInjection\DbpRelayBasePersonConnectorCampusonlineExtension;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPerson;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPersonStaging;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\EventSubscriber\PersonEventSubscriber;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service\PersonProvider;
use Dbp\Relay\CoreBundle\TestUtils\TestAuthorizationService;
use Dbp\Relay\CoreBundle\TestUtils\TestEntityManager;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

class TestPersonProviderFactory
{
    public const STAFF_USER_IDENTIFIER = 'staff-id';
    public const STUDENT_USER_IDENTIFIER = 'student-id';
    public const ALUMNUS_USER_IDENTIFIER = 'alumnus-id';

    public const EMAIL_ATTRIBUTE = 'email';
    public const EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE = 'employeePostalAddress';
    public const EMPLOYEE_WORK_ADDRESS_ATTRIBUTE = 'employeeWorkAddress';

    private const CONFIG = [
        Configuration::CURRENT_PERSON_IDENTIFIER_EXPRESSION_ATTRIBUTE => Configuration::CURRENT_PERSON_IDENTIFIER_EXPRESSION_ATTRIBUTE_DEFAULT,
        Configuration::CAMPUS_ONLINE_NODE => [
            Configuration::BASE_URL_NODE => 'https://campusonline.at/campusonline/ws/public/rest/',
            Configuration::CLIENT_ID_NODE => 'client',
            Configuration::CLIENT_SECRET_NODE => 'secret',
        ],
        'local_data_mapping' => [
            [
                'local_data_attribute' => self::EMAIL_ATTRIBUTE,
                'source_attribute' => CachedPerson::EMAIL_COLUMN_NAME,
                'default_value' => '',
            ],
            [
                'local_data_attribute' => self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE,
                'source_attribute' => PersonEventSubscriber::EMPLOYEE_POSTAL_ADDRESS_SOURCE_ATTRIBUTE,
                'default_value' => '',
            ],
            [
                'local_data_attribute' => self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE,
                'source_attribute' => PersonEventSubscriber::EMPLOYEE_WORK_ADDRESS_SOURCE_ATTRIBUTE,
                'default_value' => '',
            ],
        ],
    ];

    public static function createTestPersonProvider(ContainerInterface $container,
        array $personEventSubscribers = [], ?array $localDataMappingConfig = null): PersonProvider
    {
        $entityManager = TestEntityManager::setUpEntityManager($container,
            DbpRelayBasePersonConnectorCampusonlineExtension::ENTITY_MANAGER_ID);

        $config = self::CONFIG;
        if ($localDataMappingConfig !== null) {
            $config['local_data_mapping'] = $localDataMappingConfig;
        }

        $eventDispatcher = new EventDispatcher();
        $personProvider = new PersonProvider(
            $entityManager, new ArrayAdapter(), $eventDispatcher);
        $personProvider->setLogger(new NullLogger());
        $personProvider->setConfig($config);

        $personEventSubscriber = new PersonEventSubscriber($personProvider);
        $personEventSubscriber->setConfig($config);
        $eventDispatcher->addSubscriber($personEventSubscriber);
        foreach ($personEventSubscribers as $subscriber) {
            $eventDispatcher->addSubscriber($subscriber);
        }

        self::recreatePersonCache($personProvider, $entityManager);
        self::login($personProvider);

        return $personProvider;
    }

    public static function login(PersonProvider $personProvider,
        ?string $currentUserIdentifier = TestAuthorizationService::TEST_USER_IDENTIFIER,
        array $currentUserAttributes = []): void
    {
        TestAuthorizationService::setUp($personProvider,
            currentUserIdentifier: $currentUserIdentifier,
            currentUserAttributes: $currentUserAttributes);
    }

    public static function mockPersonClaimsApiResponse(PersonProvider $personProvider): void
    {
        self::mockApiResponse($personProvider,
            file_get_contents(__DIR__.'/person_claims_api_response.json'));
    }

    public static function mockUserApiResponse(PersonProvider $personProvider): void
    {
        self::mockApiResponse($personProvider,
            file_get_contents(__DIR__.'/users_api_response.json')
        );
    }

    public static function mockEmptyApiResponse(PersonProvider $personProvider): void
    {
        self::mockApiResponse($personProvider,
            file_get_contents(__DIR__.'/empty_api_response.json')
        );
    }

    public static function mockApiResponse(PersonProvider $personProvider,
        string $content,
        int $status = \Symfony\Component\HttpFoundation\Response::HTTP_OK,
        bool $mockAuthServerResponses = true): void
    {
        $responses = [...($mockAuthServerResponses ? self::createMockAuthServerResponses() : []),
            new Response(
                $status,
                ['Content-Type' => 'application/json'],
                $content),
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $personProvider->setClientHandler($stack);
    }

    public static function createMockAuthServerResponses(): array
    {
        return [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], '{"authServerUrl": "https://auth-server.net/"}'),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], '{"token_endpoint": "https://token-endpoint.net/"}'),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], '{"access_token": "token", "expires_in": 3600, "token_type": "Bearer"}'),
        ];
    }

    private static function recreatePersonCache(PersonProvider $personProvider, EntityManagerInterface $entityManager): void
    {
        self::mockResponsesForPersonCacheRecreation($personProvider);
        try {
            // this is expected to fail, since sqlite does not support some operations
            $personProvider->recreatePersonsCache();
        } catch (\Throwable) {
            $personsLiveTable = CachedPerson::TABLE_NAME;
            $personsStagingTable = CachedPersonStaging::TABLE_NAME;
            $personsTempTable = CachedPerson::TABLE_NAME.'_temp';
            $connection = $entityManager->getConnection();
            $connection->executeStatement("ALTER TABLE $personsLiveTable RENAME TO $personsTempTable;");
            $connection->executeStatement("ALTER TABLE $personsStagingTable RENAME TO $personsLiveTable;");
            $connection->executeStatement("ALTER TABLE $personsTempTable RENAME TO $personsStagingTable;");
        } finally {
            $personProvider->reset(); // ensure new api connection is created on subsequent requests
        }
    }

    private static function mockResponsesForPersonCacheRecreation(PersonProvider $personProvider): void
    {
        $responses = [...self::createMockAuthServerResponses(),
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__.'/users_api_response.json')),
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__.'/person_claims_api_response.json')),
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $personProvider->setClientHandler($stack);
    }
}
