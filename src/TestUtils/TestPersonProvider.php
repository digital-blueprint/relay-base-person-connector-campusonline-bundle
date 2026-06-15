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
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

class TestPersonProvider extends PersonProvider
{
    public const STAFF_USER_IDENTIFIER = 'staff-id';
    public const STUDENT_USER_IDENTIFIER = 'student-id';
    public const ALUMNUS_USER_IDENTIFIER = 'alumnus-id';
    public const EXTERNAL_USER_IDENTIFIER = 'external-id'; // is not added by default, because they don't have a user account

    public const EMAIL_ATTRIBUTE = 'email';
    public const EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE = 'employeePostalAddress';
    public const EMPLOYEE_WORK_ADDRESS_ATTRIBUTE = 'employeeWorkAddress';
    public const USERNAME_ATTRIBUTE = 'username';

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
                'source_attribute' => CachedPerson::EMAIL,
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
            [
                'local_data_attribute' => self::USERNAME_ATTRIBUTE,
                'source_attribute' => PersonEventSubscriber::USERNAME_SOURCE_ATTRIBUTE,
                'default_value' => '',
            ],
        ],
    ];

    private ?MockHandler $mockHandler = null;

    public static function createTestPersonProvider(
        ContainerInterface $container,
        array $personEventSubscribers = [],
        ?array $localDataMappingConfig = null
    ): self {
        $entityManager = TestEntityManager::setUpEntityManager(
            $container,
            DbpRelayBasePersonConnectorCampusonlineExtension::ENTITY_MANAGER_ID
        );

        $config = self::CONFIG;
        if ($localDataMappingConfig !== null) {
            $config['local_data_mapping'] = $localDataMappingConfig;
        }

        $eventDispatcher = new EventDispatcher();
        $personProvider = new self(
            $entityManager,
            new NullAdapter(),
            $eventDispatcher
        );
        $personProvider->setLogger(new NullLogger());
        $personProvider->setConfig($config);

        $personEventSubscriber = new PersonEventSubscriber($personProvider);
        $personEventSubscriber->setConfig($config);
        $personEventSubscribers[] = $personEventSubscriber;
        $personEventSubscribers[] = new TestPersonEventSubscriber($personProvider);
        foreach ($personEventSubscribers as $subscriber) {
            $eventDispatcher->addSubscriber($subscriber);
        }
        $personProvider->recreatePersonsCache();
        $personProvider->login();

        return $personProvider;
    }

    public function login(
        ?string $currentUserIdentifier = TestAuthorizationService::TEST_USER_IDENTIFIER,
        array $currentUserAttributes = []
    ): void {
        TestAuthorizationService::setUp(
            $this,
            currentUserIdentifier: $currentUserIdentifier,
            currentUserAttributes: $currentUserAttributes
        );
    }

    public function mockPersonClaimsApiResponse(bool $mockAuthServerResponses = true): void
    {
        $this->mockApiResponse(
            self::getPersonClaimsApiTestResponse(),
            mockAuthServerResponses: $mockAuthServerResponses
        );
    }

    public function mockUserApiResponse(): void
    {
        $this->mockApiResponse(
            self::getUserApiTestResponse()
        );
    }

    public function mockEmptyApiResponse(): void
    {
        $this->mockApiResponse(
            self::getEmptyApiTestResponse()
        );
    }

    public function mockApiResponse(
        string $content,
        int $status = \Symfony\Component\HttpFoundation\Response::HTTP_OK,
        bool $mockAuthServerResponses = true
    ): void {
        $this->mockApiResponses([
            new Response(
                $status,
                ['Content-Type' => 'application/json'],
                $content
            ),
        ], mockAuthServerResponses: $mockAuthServerResponses);
    }

    /**
     * @param Response[] $responses
     */
    public function mockApiResponses(array $responses, bool $mockAuthServerResponses = true): void
    {
        if ($mockAuthServerResponses) {
            $responses = array_merge(self::createMockAuthServerResponses(), $responses);
        }

        $this->mockHandler = new MockHandler($responses);
        $handlerStack = HandlerStack::create($this->mockHandler);
        $this->setClientHandler($handlerStack);
    }

    public function wereApiResponsesConsumed(): bool
    {
        return ($this->mockHandler?->count() ?? 0) === 0;
    }

    public static function createMockAuthServerResponses(): array
    {
        return [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], '{"authServerUrl": "https://auth-server.net/"}'),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], '{"token_endpoint": "https://token-endpoint.net/"}'),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], '{"access_token": "token", "expires_in": 3600, "token_type": "Bearer"}'),
        ];
    }

    public static function getPersonClaimsApiTestResponse(): string
    {
        return file_get_contents(__DIR__.'/person_claims_api_response.json');
    }

    public static function getUserApiTestResponse(): string
    {
        return file_get_contents(__DIR__.'/users_api_response.json');
    }

    public static function getEmptyApiTestResponse(): string
    {
        return file_get_contents(__DIR__.'/empty_api_response.json');
    }

    public function recreatePersonsCache(): void
    {
        $this->mockResponsesForPersonCacheRecreation();
        try {
            // this is expected to fail, since sqlite does not support some operations
            parent::recreatePersonsCache();
        } catch (\Throwable) {
            $personsLiveTable = CachedPerson::TABLE_NAME;
            $personsStagingTable = CachedPersonStaging::TABLE_NAME;
            $personsTempTable = CachedPerson::TABLE_NAME.'_temp';
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement("ALTER TABLE $personsLiveTable RENAME TO $personsTempTable;");
            $connection->executeStatement("ALTER TABLE $personsStagingTable RENAME TO $personsLiveTable;");
            $connection->executeStatement("ALTER TABLE $personsTempTable RENAME TO $personsStagingTable;");
        } finally {
            assert($this->wereApiResponsesConsumed());
            $this->reset(); // ensure new api connection is created on subsequent requests
        }
    }

    private function mockResponsesForPersonCacheRecreation(): void
    {
        $responses = [...self::createMockAuthServerResponses(),
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                $this->getUserApiTestResponse()
            ),
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                $this->getPersonClaimsApiTestResponse()
            ),
            // for the persons injected via PersonProvider::addPersonsToStagingTable
            new Response(
                200,
                ['Content-Type' => 'application/json'],
                json_encode(
                    [
                        'items' => [
                            [
                                'givenName' => 'External',
                                'surname' => 'Person',
                                'uid' => 'external-id',
                                'email' => 'external@person.com',
                            ],
                        ],
                    ]
                )
            ),
        ];

        $this->mockHandler = new MockHandler($responses);
        $handlerStack = HandlerStack::create($this->mockHandler);
        $this->setClientHandler($handlerStack);
    }
}
