<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Service\PersonProvider;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\TestUtils\TestPersonProviderFactory;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\TestUtils\TestAuthorizationService;
use Symfony\Component\HttpFoundation\Response;

/**
 * TODO: add more persons to responses for reasonable pagination, search and filter tests.
 */
class PersonProviderTest extends ApiTestCase
{
    private const STAFF_USER_IDENTIFIER = TestPersonProviderFactory::STAFF_USER_IDENTIFIER;
    private const STUDENT_USER_IDENTIFIER = TestPersonProviderFactory::STUDENT_USER_IDENTIFIER;

    private const EMAIL_ATTRIBUTE = TestPersonProviderFactory::EMAIL_ATTRIBUTE;
    private const EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE = TestPersonProviderFactory::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE;
    private const EMPLOYEE_WORK_ADDRESS_ATTRIBUTE = TestPersonProviderFactory::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE;

    private ?PersonProvider $personProvider = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->personProvider = TestPersonProviderFactory::createTestPersonProvider(self::bootKernel()->getContainer());
        $this->login();
    }

    public function testGetCurrentPerson(): void
    {
        $currentPerson1 = $this->personProvider->getCurrentPerson();
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $currentPerson1->getIdentifier());
        $this->assertNull($currentPerson1->getLocalData());

        $currentPerson2 = $this->personProvider->getCurrentPerson();
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $currentPerson2->getIdentifier());
        $this->assertNull($currentPerson2->getLocalData());
        $this->assertSame($currentPerson1, $currentPerson2);

        // same request, but with local data requested
        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE]);
        $currentPerson3 = $this->personProvider->getCurrentPerson($options);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $currentPerson3->getIdentifier());
        $this->assertCount(1, $currentPerson3->getLocalData());
        $this->assertSame('eleanora.quill@someuni.example', $currentPerson3->getLocalData()[self::EMAIL_ATTRIBUTE]);
        $this->assertNotSame($currentPerson1, $currentPerson3);

        // same request, but with different local data requested
        TestPersonProviderFactory::mockPersonClaimsApiResponse($this->personProvider); // getting employee address should trigger a new api request
        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]);
        $currentPerson4 = $this->personProvider->getCurrentPerson($options);
        $this->assertCount(1, $currentPerson4->getLocalData());
        $address = $currentPerson4->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE];
        $this->assertEquals('Graz', $address['city']);
        $this->assertEquals('AT', $address['country']);
        $this->assertEquals('8010', $address['postalCode']);
        $this->assertEquals('Street 123', $address['street']);
        $this->assertEquals('PA', $address['addressTypeKey']);
        $this->assertNotSame($currentPerson1, $currentPerson4);
    }

    public function testGetCurrentPersonWithLocalData(): void
    {
        TestPersonProviderFactory::mockPersonClaimsApiResponse($this->personProvider); // getting employee address should trigger a new api request

        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE, self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]);
        $currentPerson1 = $this->personProvider->getCurrentPerson($options);
        $this->assertCount(2, $currentPerson1->getLocalData());
        $this->assertSame('eleanora.quill@someuni.example', $currentPerson1->getLocalData()[self::EMAIL_ATTRIBUTE]);

        $address = $currentPerson1->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE];
        $this->assertEquals('Graz', $address['city']);
        $this->assertEquals('AT', $address['country']);
        $this->assertEquals('8010', $address['postalCode']);
        $this->assertEquals('Street 123', $address['street']);
        $this->assertEquals('PA', $address['addressTypeKey']);

        // same local data attributes -> same instance should be returned and no new api request should be made
        $currentPerson2 = $this->personProvider->getCurrentPerson($options);
        $this->assertCount(2, $currentPerson2->getLocalData());
        $this->assertSame($currentPerson1, $currentPerson2);
    }

    public function testGetCurrentPersonNotFound(): void
    {
        $this->login('non-existing-user');
        $this->assertNull($this->personProvider->getCurrentPerson());
    }

    public function testGetPerson(): void
    {
        $person = $this->personProvider->getPerson(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $person->getIdentifier());
        $this->assertNull($person->getLocalData());
    }

    public function testGetPersonWithLocalData(): void
    {
        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE]);
        $person = $this->personProvider->getPerson(self::STAFF_USER_IDENTIFIER, $options);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $person->getIdentifier());
        $this->assertCount(1, $person->getLocalData());
        $this->assertSame('eleanora.quill@someuni.example', $person->getLocalData()[self::EMAIL_ATTRIBUTE]);
    }

    public function testGetPersonWithLocalDataNewRequest(): void
    {
        TestPersonProviderFactory::mockPersonClaimsApiResponse($this->personProvider); // getting employee address should trigger a new api request

        $options = [];
        Options::requestLocalDataAttributes($options, [
            self::EMAIL_ATTRIBUTE,
            self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE,
            self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE]);
        $person = $this->personProvider->getPerson(self::STAFF_USER_IDENTIFIER, $options);
        $this->assertCount(3, $person->getLocalData());
        $this->assertSame('eleanora.quill@someuni.example', $person->getLocalData()[self::EMAIL_ATTRIBUTE]);

        $address = $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE];
        $this->assertEquals('Graz', $address['city']);
        $this->assertEquals('AT', $address['country']);
        $this->assertEquals('8010', $address['postalCode']);
        $this->assertEquals('Street 123', $address['street']);
        $this->assertEquals('PA', $address['addressTypeKey']);

        $address = $person->getLocalData()[self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE];
        $this->assertEquals('Wien', $address['city']);
        $this->assertEquals('AT', $address['country']);
        $this->assertEquals('1010', $address['postalCode']);
        $this->assertEquals('Hohenplatz 3, Institut für Phantastik', $address['street']);
        $this->assertEquals('DO', $address['addressTypeKey']);
        $this->assertEquals('44', $address['roomIdentifier']);
        $this->assertEquals('37', $address['contactOrganizationIdentifier']);
    }

    public function testGetPersons(): void
    {
        $persons = $this->personProvider->getPersons(1, 10);
        $this->assertCount(3, $persons);
        $this->assertTrue(self::containsExactlyOneWhere($persons,
            fn (Person $person) => self::STAFF_USER_IDENTIFIER === $person->getIdentifier()
                && 'Eleanora' === $person->getGivenName()
                && 'Quill-Weatherby' === $person->getFamilyName()
                && null === $person->getLocalData()
        ));

        $this->assertTrue(self::containsExactlyOneWhere($persons,
            fn (Person $person) => 'student-id' === $person->getIdentifier()
                && 'Luna' === $person->getGivenName()
                && 'Pérez-Altamirano' === $person->getFamilyName()
                && null === $person->getLocalData()
        ));

        $this->assertTrue(self::containsExactlyOneWhere($persons,
            fn (Person $person) => 'alumnus-id' === $person->getIdentifier()
                && 'Aksel' === $person->getGivenName()
                && 'Østergaard' === $person->getFamilyName()
                && null === $person->getLocalData()
        ));
    }

    protected static function containsExactlyOneWhere(array $results, callable $where): bool
    {
        return 1 === count(array_filter($results, $where));
    }

    public function testGetPersonsPagination(): void
    {
        $personPage1 = $this->personProvider->getPersons(1, 2);
        $this->assertCount(2, $personPage1);

        $personPage2 = $this->personProvider->getPersons(2, 2);
        $this->assertCount(1, $personPage2);

        $persons = array_merge($personPage1, $personPage2);
        $this->assertCount(3, $persons);
        $this->assertTrue(self::containsExactlyOneWhere($persons,
            fn (Person $person) => self::STAFF_USER_IDENTIFIER === $person->getIdentifier()
                && 'Eleanora' === $person->getGivenName()
                && 'Quill-Weatherby' === $person->getFamilyName()
                && null === $person->getLocalData()
        ));

        $this->assertTrue(self::containsExactlyOneWhere($persons,
            fn (Person $person) => 'student-id' === $person->getIdentifier()
                && 'Luna' === $person->getGivenName()
                && 'Pérez-Altamirano' === $person->getFamilyName()
                && null === $person->getLocalData()
        ));

        $this->assertTrue(self::containsExactlyOneWhere($persons,
            fn (Person $person) => 'alumnus-id' === $person->getIdentifier()
                && 'Aksel' === $person->getGivenName()
                && 'Østergaard' === $person->getFamilyName()
                && null === $person->getLocalData()
        ));
    }

    public function testGetPersonsWithLocalData(): void
    {
        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE]);
        $persons = $this->personProvider->getPersons(1, 10, $options);
        $this->assertCount(3, $persons);
        $this->assertTrue(self::containsExactlyOneWhere($persons,
            fn (Person $person) => self::STAFF_USER_IDENTIFIER === $person->getIdentifier()
                && 'Eleanora' === $person->getGivenName()
                && 'Quill-Weatherby' === $person->getFamilyName()
                && 'eleanora.quill@someuni.example' === $person->getLocalData()[self::EMAIL_ATTRIBUTE])
        );
        $this->assertTrue(self::containsExactlyOneWhere($persons,
            fn (Person $person) => 'student-id' === $person->getIdentifier()
                && 'Luna' === $person->getGivenName()
                && 'Pérez-Altamirano' === $person->getFamilyName()
                && 'luna.perez@someuni.edu' === $person->getLocalData()[self::EMAIL_ATTRIBUTE])
        );
        $this->assertTrue(self::containsExactlyOneWhere($persons,
            fn (Person $person) => 'alumnus-id' === $person->getIdentifier()
                && 'Aksel' === $person->getGivenName()
                && 'Østergaard' === $person->getFamilyName()
            && 'aksel.ostergaard@alumni.someuni.at' === $person->getLocalData()[self::EMAIL_ATTRIBUTE])
        );
    }

    public function testGetPersonsWithLocalDataNewRequest(): void
    {
        TestPersonProviderFactory::mockPersonClaimsApiResponse($this->personProvider); // getting employee address should trigger a new api request

        $options = [];
        Options::requestLocalDataAttributes($options, [
            self::EMAIL_ATTRIBUTE,
            self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE,
            self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE]);
        $persons = $this->personProvider->getPersons(1, 10, $options);
        $this->assertCount(3, $persons);
        $this->assertTrue(self::containsExactlyOneWhere($persons,
            fn (Person $person) => self::STAFF_USER_IDENTIFIER === $person->getIdentifier()
                && 'Eleanora' === $person->getGivenName()
                && 'Quill-Weatherby' === $person->getFamilyName()
                && 'eleanora.quill@someuni.example' === $person->getLocalData()[self::EMAIL_ATTRIBUTE]
                && 'Graz' === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]['city']
                && 'AT' === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]['country']
                && '8010' === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]['postalCode']
                && 'Street 123' === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]['street']
                && 'PA' === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]['addressTypeKey']
                && 'Wien' === $person->getLocalData()[self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE]['city']
                && 'AT' === $person->getLocalData()[self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE]['country']
                && '1010' === $person->getLocalData()[self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE]['postalCode']
                && 'Hohenplatz 3, Institut für Phantastik' === $person->getLocalData()[self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE]['street']
                && 'DO' === $person->getLocalData()[self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE]['addressTypeKey']
                && '44' === $person->getLocalData()[self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE]['roomIdentifier']
                && '37' === $person->getLocalData()[self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE]['contactOrganizationIdentifier'])
        );

        $this->assertTrue(self::containsExactlyOneWhere($persons,
            fn (Person $person) => 'student-id' === $person->getIdentifier()
                && 'Luna' === $person->getGivenName()
                && 'Pérez-Altamirano' === $person->getFamilyName()
                && 'luna.perez@someuni.edu' === $person->getLocalData()[self::EMAIL_ATTRIBUTE]
                && null === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]
                && null === $person->getLocalData()[self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE])
        );
        $this->assertTrue(self::containsExactlyOneWhere($persons,
            fn (Person $person) => 'alumnus-id' === $person->getIdentifier()
                && 'Aksel' === $person->getGivenName()
                && 'Østergaard' === $person->getFamilyName()
                && 'aksel.ostergaard@alumni.someuni.at' === $person->getLocalData()[self::EMAIL_ATTRIBUTE]
                && null === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]
                && null === $person->getLocalData()[self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE])
        );
    }

    public function testGetPersonsWithSearchParameter(): void
    {
        $options = [
            Person::SEARCH_PARAMETER_NAME => 'altamir',
        ];
        $persons = $this->personProvider->getPersons(1, 10, $options);
        $this->assertCount(1, $persons);
        $person = $persons[0];
        $this->assertSame(self::STUDENT_USER_IDENTIFIER, $person->getIdentifier());

        $options = [
            Person::SEARCH_PARAMETER_NAME => 'altamir luna',
        ];
        $persons = $this->personProvider->getPersons(1, 10, $options);
        $this->assertCount(1, $persons);
        $person = $persons[0];
        $this->assertSame(self::STUDENT_USER_IDENTIFIER, $person->getIdentifier());

        $options = [
            Person::SEARCH_PARAMETER_NAME => 'alamir foo',
        ];
        $persons = $this->personProvider->getPersons(1, 10, $options);
        $this->assertCount(0, $persons);

        $options = [
            Person::SEARCH_PARAMETER_NAME => 'foo',
        ];
        $persons = $this->personProvider->getPersons(1, 10, $options);
        $this->assertCount(0, $persons);
    }

    public function testGetPersonIdentifierByUsername(): void
    {
        TestPersonProviderFactory::mockUserApiResponse($this->personProvider);

        $personIdentifier = $this->personProvider->getPersonIdentifierByUsername('maxm');
        $this->assertEquals('staff-id', $personIdentifier);

        TestPersonProviderFactory::mockEmptyApiResponse($this->personProvider);

        $this->assertNull($this->personProvider->getPersonIdentifierByUsername('foo'));
    }

    public function testGetPersonIdentifierByEmail(): void
    {
        TestPersonProviderFactory::mockUserApiResponse($this->personProvider);

        $personIdentifier = $this->personProvider->getPersonIdentifierByEmail('eleanora.quill@someuni.example');
        $this->assertEquals('staff-id', $personIdentifier);

        TestPersonProviderFactory::mockEmptyApiResponse($this->personProvider);

        $this->assertNull($this->personProvider->getPersonIdentifierByEmail('foo'));
    }

    public function testGetUserFromApiCached(): void
    {
        TestPersonProviderFactory::mockUserApiResponse($this->personProvider);

        $user = $this->personProvider->getUserFromApiCached(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $user->getPersonUid());
        $this->assertSame('eleanora.quill@someuni.example', $user->getEmail(0));
        $this->assertSame('maxm', $user->getUsername(0));
    }

    public function testGetUserFromApiCachedNotFound(): void
    {
        TestPersonProviderFactory::mockEmptyApiResponse($this->personProvider);

        try {
            $this->personProvider->getUserFromApiCached('foo');
            $this->fail('expected ApiError not thrown');
        } catch (ApiError $apiError) {
            $this->assertSame(Response::HTTP_NOT_FOUND, $apiError->getStatusCode());
        }
    }

    public function testRequestCacheCurrentResultUsers(): void
    {
        $this->personProvider->getPerson(self::STAFF_USER_IDENTIFIER); // caches the person ids of current request result

        TestPersonProviderFactory::mockUserApiResponse($this->personProvider);
        $this->personProvider->requestCacheCurrentResultUsers();

        // NOTE: no more requests must be made
        $user = $this->personProvider->getUserFromApiCached(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $user->getPersonUid());
        $this->assertSame('eleanora.quill@someuni.example', $user->getEmail(0));
        $this->assertSame('maxm', $user->getUsername(0));
    }

    public function testIsCurrentUserAnEmployee(): void
    {
        $this->assertTrue($this->personProvider->isCurrentUserAnEmployee());
    }

    public function testIsCurrentUserAnEmployeeUndefined(): void
    {
        $this->login('non-existing-user');
        $this->assertNull($this->personProvider->isCurrentUserAnEmployee());
    }

    public function testIsCurrentUserAStudent(): void
    {
        $this->assertFalse($this->personProvider->isCurrentUserAStudent());
    }

    public function testIsCurrentUserAStudentUndefined(): void
    {
        $this->login('non-existing-user');
        $this->assertNull($this->personProvider->isCurrentUserAStudent());
    }

    public function testIsCurrentUserAnAlumni(): void
    {
        $this->assertFalse($this->personProvider->isCurrentUserAnAlumni());
    }

    public function testIsCurrentUserAnAlumniUndefined(): void
    {
        $this->login('non-existing-user');
        $this->assertNull($this->personProvider->isCurrentUserAnAlumni());
    }

    private function login(?string $userIdentifier = self::STAFF_USER_IDENTIFIER, array $userAttributes = []): void
    {
        TestAuthorizationService::setUp($this->personProvider, $userIdentifier, $userAttributes);
    }
}
