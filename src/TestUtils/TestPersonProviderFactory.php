<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\TestUtils;

use Dbp\Relay\BasePersonConnectorCampusonlineBundle\DependencyInjection\Configuration;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\DependencyInjection\DbpRelayBasePersonConnectorCampusonlineExtension;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPerson;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPersonStaging;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\EventSubscriber\PersonEventSubscriber;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service\PersonProvider;
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
    public const ALUMNS_USER_IDENTIFIER = 'alumnus-id';

    private const EMAIL_ATTRIBUTE = 'email';
    private const EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE = 'employeeWorkAddress';

    public static function createTestPersonProvider(ContainerInterface $container, array $personEventSubscribers = []): PersonProvider
    {
        $entityManager = TestEntityManager::setUpEntityManager($container,
            DbpRelayBasePersonConnectorCampusonlineExtension::ENTITY_MANAGER_ID);

        $eventDispatcher = new EventDispatcher();
        $personProvider = new PersonProvider(
            $entityManager, new ArrayAdapter(), $eventDispatcher);
        $personProvider->setLogger(new NullLogger());
        $personProvider->setConfig(self::getConfig());

        $personEventSubscriber = new PersonEventSubscriber($personProvider);
        $personEventSubscriber->setConfig(self::getConfig());
        $eventDispatcher->addSubscriber($personEventSubscriber);
        foreach ($personEventSubscribers as $subscriber) {
            $eventDispatcher->addSubscriber($subscriber);
        }

        self::recreatePersonCache($personProvider, $entityManager);

        return $personProvider;
    }

    private static function getConfig(): array
    {
        return [
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
            ],
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
            $personsTempTable = 'organizations_old';
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

    public static function mockPersonClaimsApiResponse(PersonProvider $personProvider): void
    {
        $responses = [...self::createMockAuthServerResponses(),
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                file_get_contents(__DIR__.'/person_claims_api_response.json')),
        ];

        $stack = HandlerStack::create(new MockHandler($responses));
        $personProvider->setClientHandler($stack);
    }

    public static function mockUserApiResponse(PersonProvider $personProvider): void
    {
        self::mockUserApiResponseWithContent($personProvider,
            file_get_contents(__DIR__.'/users_api_response.json')
        );
    }

    public static function mockEmptyUserApiResponse(PersonProvider $personProvider): void
    {
        self::mockUserApiResponseWithContent($personProvider,
            file_get_contents(__DIR__.'/empty_api_response.json')
        );
    }

    public static function mockUserApiResponse404(PersonProvider $personProvider): void
    {
        self::mockUserApiResponseWithContent($personProvider,
            file_get_contents(__DIR__.'/empty_api_response.json'),
            404
        );
    }

    public static function mockUserApiResponseWithContent(PersonProvider $personProvider, string $content, int $status = 200): void
    {
        $responses = [...self::createMockAuthServerResponses(),
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
}
