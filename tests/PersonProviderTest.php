<?php

declare(strict_types=1);

namespace Dbp\Relay\BasePersonConnectorCampusonlineBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BasePersonBundle\Entity\Person;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\Entity\CachedPerson;
use Dbp\Relay\BasePersonConnectorCampusonlineBundle\TestUtils\TestPersonProvider;
use Dbp\Relay\CoreBundle\Rest\Options;

/**
 * TODO: add more persons to responses for reasonable pagination, search and filter tests.
 */
class PersonProviderTest extends ApiTestCase
{
    private const STAFF_USER_IDENTIFIER = TestPersonProvider::STAFF_USER_IDENTIFIER;
    private const STUDENT_USER_IDENTIFIER = TestPersonProvider::STUDENT_USER_IDENTIFIER;
    private const ALUMNUS_USER_IDENTIFIER = TestPersonProvider::ALUMNUS_USER_IDENTIFIER;

    private const EMAIL_ATTRIBUTE = TestPersonProvider::EMAIL_ATTRIBUTE;
    private const EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE = TestPersonProvider::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE;
    private const EMPLOYEE_WORK_ADDRESS_ATTRIBUTE = TestPersonProvider::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE;
    private const USERNAME_ATTRIBUTE = TestPersonProvider::USERNAME_ATTRIBUTE;

    private ?TestPersonProvider $testPersonProvider = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testPersonProvider = TestPersonProvider::createTestPersonProvider(
            self::bootKernel()->getContainer()
        );

        $this->login(self::STAFF_USER_IDENTIFIER);
    }

    public function testGetCurrentPersonIdentifier(): void
    {
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $this->testPersonProvider->getCurrentPersonIdentifier());
    }

    public function testGetCurrentPerson(): void
    {
        $currentPerson1 = $this->testPersonProvider->getCurrentPerson();
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $currentPerson1->getIdentifier());
        $this->assertNull($currentPerson1->getLocalData());

        $currentPerson2 = $this->testPersonProvider->getCurrentPerson();
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $currentPerson2->getIdentifier());
        $this->assertNull($currentPerson2->getLocalData());
        $this->assertSame($currentPerson1, $currentPerson2);

        // same request, but with local data requested
        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE]);
        $currentPerson3 = $this->testPersonProvider->getCurrentPerson($options);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $currentPerson3->getIdentifier());
        $this->assertCount(1, $currentPerson3->getLocalData());
        $this->assertSame('eleanora.quill@someuni.example', $currentPerson3->getLocalData()[self::EMAIL_ATTRIBUTE]);
        $this->assertNotSame($currentPerson1, $currentPerson3);

        // same request, but with different local data requested
        $this->testPersonProvider->mockPersonClaimsApiResponse(); // getting employee address should trigger a new api request
        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]);
        $currentPerson4 = $this->testPersonProvider->getCurrentPerson($options);
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
        $this->testPersonProvider->mockPersonClaimsApiResponse(); // getting employee address should trigger a new api request

        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE, self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]);
        $currentPerson1 = $this->testPersonProvider->getCurrentPerson($options);
        $this->assertCount(2, $currentPerson1->getLocalData());
        $this->assertSame('eleanora.quill@someuni.example', $currentPerson1->getLocalData()[self::EMAIL_ATTRIBUTE]);

        $address = $currentPerson1->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE];
        $this->assertEquals('Graz', $address['city']);
        $this->assertEquals('AT', $address['country']);
        $this->assertEquals('8010', $address['postalCode']);
        $this->assertEquals('Street 123', $address['street']);
        $this->assertEquals('PA', $address['addressTypeKey']);

        // same local data attributes -> same instance should be returned and no new api request should be made
        $currentPerson2 = $this->testPersonProvider->getCurrentPerson($options);
        $this->assertCount(2, $currentPerson2->getLocalData());
        $this->assertSame($currentPerson1, $currentPerson2);
    }

    public function testGetCurrentPersonNotFound(): void
    {
        $this->login('non-existing-user');
        $this->assertNull($this->testPersonProvider->getCurrentPerson());
    }

    public function testGetPerson(): void
    {
        $person = $this->testPersonProvider->getPerson(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $person->getIdentifier());
        $this->assertNull($person->getLocalData());
    }

    public function testGetExternalPerson(): void
    {
        $person = $this->testPersonProvider->getPerson(TestPersonProvider::EXTERNAL_USER_IDENTIFIER);
        $this->assertEquals(TestPersonProvider::EXTERNAL_USER_IDENTIFIER, $person->getIdentifier());
        $this->assertEquals('External', $person->getGivenName());
        $this->assertEquals('Person', $person->getFamilyName());
    }

    public function testGetPersonWithLocalData(): void
    {
        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE]);
        $person = $this->testPersonProvider->getPerson(self::STAFF_USER_IDENTIFIER, $options);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $person->getIdentifier());
        $this->assertCount(1, $person->getLocalData());
        $this->assertSame('eleanora.quill@someuni.example', $person->getLocalData()[self::EMAIL_ATTRIBUTE]);
    }

    public function testGetPersonWithLocalDataNewPersonClaimsApiRequest(): void
    {
        // getting employee address should trigger a new person api request
        $this->testPersonProvider->mockPersonClaimsApiResponse();

        $options = [];
        Options::requestLocalDataAttributes($options, [
            self::EMAIL_ATTRIBUTE,
            self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE,
            self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE]);
        $person = $this->testPersonProvider->getPerson(self::STAFF_USER_IDENTIFIER, $options);
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

    public function testGetPersonWithLocalDataNewPersonClaimsApiRequestNotFound(): void
    {
        // getting employee address should trigger a new person api request
        // -> however, the person is not found anymore and thus the address cannot be retrieved
        $this->testPersonProvider->mockEmptyApiResponse();

        $options = [];
        Options::requestLocalDataAttributes($options, [
            self::EMAIL_ATTRIBUTE,
            self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]);
        $person = $this->testPersonProvider->getPerson(self::STAFF_USER_IDENTIFIER, $options);
        $this->assertCount(2, $person->getLocalData());
        $this->assertSame('eleanora.quill@someuni.example', $person->getLocalData()[self::EMAIL_ATTRIBUTE]);
        $this->assertNull($person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]);
    }

    public function testGetPersonWithLocalDataNewUserApiRequest(): void
    {
        // getting username should trigger a new user api request
        $this->testPersonProvider->mockUserApiResponse();

        $options = [];
        Options::requestLocalDataAttributes($options, [
            self::USERNAME_ATTRIBUTE,
        ]);
        $person = $this->testPersonProvider->getPerson(self::STAFF_USER_IDENTIFIER, $options);
        $this->assertCount(1, $person->getLocalData());
        $this->assertSame('maxm', $person->getLocalData()[self::USERNAME_ATTRIBUTE]);
    }

    public function testGetPersonWithLocalDataNewUserApiRequestNotFound(): void
    {
        // getting username should trigger a new user api request
        // however, the user is not found (anymore) and thus the username cannot be retrieved
        $this->testPersonProvider->mockEmptyApiResponse();

        $options = [];
        Options::requestLocalDataAttributes($options, [
            self::USERNAME_ATTRIBUTE,
        ]);
        $person = $this->testPersonProvider->getPerson(self::STAFF_USER_IDENTIFIER, $options);
        $this->assertCount(1, $person->getLocalData());
        $this->assertNull($person->getLocalData()[self::USERNAME_ATTRIBUTE]);
    }

    public function testGetPersonWithLocalDataNewUserApiAndPersonClaimsApiRequest(): void
    {
        // getting username/address should trigger a new user/person claims api request
        $this->testPersonProvider->mockApiResponses([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], $this->testPersonProvider->getPersonClaimsApiTestResponse()),
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], $this->testPersonProvider->getUserApiTestResponse()),
        ]);

        $options = [];
        Options::requestLocalDataAttributes($options, [
            self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE,
            self::USERNAME_ATTRIBUTE,
        ]);
        $person = $this->testPersonProvider->getPerson(self::STAFF_USER_IDENTIFIER, $options);
        $this->assertCount(2, $person->getLocalData());
        $this->assertSame('maxm', $person->getLocalData()[self::USERNAME_ATTRIBUTE]);
        $address = $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE];
        $this->assertEquals('Graz', $address['city']);
        $this->assertEquals('AT', $address['country']);
        $this->assertEquals('8010', $address['postalCode']);
        $this->assertEquals('Street 123', $address['street']);
        $this->assertEquals('PA', $address['addressTypeKey']);
    }

    public function testGetPersons(): void
    {
        $persons = $this->testPersonProvider->getPersons(1, 10);
        $this->assertCount(4, $persons);
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => self::STAFF_USER_IDENTIFIER === $person->getIdentifier()
                && 'Eleanora' === $person->getGivenName()
                && 'Quill-Weatherby' === $person->getFamilyName()
                && null === $person->getLocalData()
        ));
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => 'student-id' === $person->getIdentifier()
                && 'Luna' === $person->getGivenName()
                && 'Pérez-Altamirano' === $person->getFamilyName()
                && null === $person->getLocalData()
        ));
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => 'alumnus-id' === $person->getIdentifier()
                && 'Aksel' === $person->getGivenName()
                && 'Østergaard' === $person->getFamilyName()
                && null === $person->getLocalData()
        ));
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => TestPersonProvider::EXTERNAL_USER_IDENTIFIER === $person->getIdentifier()
                && 'External' === $person->getGivenName()
                && 'Person' === $person->getFamilyName()
                && null === $person->getLocalData()
        ));
    }

    public function testGetPersonsPagination(): void
    {
        $personPage1 = $this->testPersonProvider->getPersons(1, 3);
        $this->assertCount(3, $personPage1);

        $personPage2 = $this->testPersonProvider->getPersons(2, 3);
        $this->assertCount(1, $personPage2);

        $persons = array_merge($personPage1, $personPage2);
        $this->assertCount(4, $persons);
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => self::STAFF_USER_IDENTIFIER === $person->getIdentifier()
                && 'Eleanora' === $person->getGivenName()
                && 'Quill-Weatherby' === $person->getFamilyName()
                && null === $person->getLocalData()
        ));
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => 'student-id' === $person->getIdentifier()
                && 'Luna' === $person->getGivenName()
                && 'Pérez-Altamirano' === $person->getFamilyName()
                && null === $person->getLocalData()
        ));
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => 'alumnus-id' === $person->getIdentifier()
                && 'Aksel' === $person->getGivenName()
                && 'Østergaard' === $person->getFamilyName()
                && null === $person->getLocalData()
        ));
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => TestPersonProvider::EXTERNAL_USER_IDENTIFIER === $person->getIdentifier()
                && 'External' === $person->getGivenName()
                && 'Person' === $person->getFamilyName()
                && null === $person->getLocalData()
        ));
    }

    public function testGetPersonsWithLocalData(): void
    {
        $options = [];
        Options::requestLocalDataAttributes($options, [self::EMAIL_ATTRIBUTE]);
        $persons = $this->testPersonProvider->getPersons(1, 10, $options);
        $this->assertCount(4, $persons);
        $this->assertTrue(
            self::containsExactlyOneWhere(
                $persons,
                fn (Person $person) => self::STAFF_USER_IDENTIFIER === $person->getIdentifier()
                    && 'Eleanora' === $person->getGivenName()
                    && 'Quill-Weatherby' === $person->getFamilyName()
                    && 'eleanora.quill@someuni.example' === $person->getLocalData()[self::EMAIL_ATTRIBUTE]
            )
        );
        $this->assertTrue(
            self::containsExactlyOneWhere(
                $persons,
                fn (Person $person) => 'student-id' === $person->getIdentifier()
                    && 'Luna' === $person->getGivenName()
                    && 'Pérez-Altamirano' === $person->getFamilyName()
                    && 'luna.perez@someuni.edu' === $person->getLocalData()[self::EMAIL_ATTRIBUTE]
            )
        );
        $this->assertTrue(
            self::containsExactlyOneWhere(
                $persons,
                fn (Person $person) => 'alumnus-id' === $person->getIdentifier()
                    && 'Aksel' === $person->getGivenName()
                    && 'Østergaard' === $person->getFamilyName()
                    && 'aksel.ostergaard@alumni.someuni.at' === $person->getLocalData()[self::EMAIL_ATTRIBUTE]
            )
        );
        $this->assertTrue(
            self::containsExactlyOneWhere(
                $persons,
                fn (Person $person) => TestPersonProvider::EXTERNAL_USER_IDENTIFIER === $person->getIdentifier()
                    && 'External' === $person->getGivenName()
                    && 'Person' === $person->getFamilyName()
                    && 'external@person.com' === $person->getLocalData()[self::EMAIL_ATTRIBUTE]
            )
        );
    }

    public function testGetPersonsWithLocalDataNewPersonClaimsApiRequest(): void
    {
        // getting employee address should trigger a new api request
        $this->testPersonProvider->mockPersonClaimsApiResponse();

        $options = [];
        Options::requestLocalDataAttributes($options, [
            self::EMAIL_ATTRIBUTE,
            self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE,
            self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE]);
        $persons = $this->testPersonProvider->getPersons(1, 10, $options);
        $this->assertCount(4, $persons);
        $this->assertTrue(
            self::containsExactlyOneWhere(
                $persons,
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
                    && '37' === $person->getLocalData()[self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE]['contactOrganizationIdentifier']
            )
        );
        $this->assertTrue(
            self::containsExactlyOneWhere(
                $persons,
                fn (Person $person) => 'student-id' === $person->getIdentifier()
                    && 'Luna' === $person->getGivenName()
                    && 'Pérez-Altamirano' === $person->getFamilyName()
                    && 'luna.perez@someuni.edu' === $person->getLocalData()[self::EMAIL_ATTRIBUTE]
                    && null === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]
                    && null === $person->getLocalData()[self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE]
            )
        );
        $this->assertTrue(
            self::containsExactlyOneWhere(
                $persons,
                fn (Person $person) => 'alumnus-id' === $person->getIdentifier()
                    && 'Aksel' === $person->getGivenName()
                    && 'Østergaard' === $person->getFamilyName()
                    && 'aksel.ostergaard@alumni.someuni.at' === $person->getLocalData()[self::EMAIL_ATTRIBUTE]
                    && null === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]
                    && null === $person->getLocalData()[self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE]
            )
        );
        $this->assertTrue(
            self::containsExactlyOneWhere(
                $persons,
                fn (Person $person) => TestPersonProvider::EXTERNAL_USER_IDENTIFIER === $person->getIdentifier()
                    && 'External' === $person->getGivenName()
                    && 'Person' === $person->getFamilyName()
                    && 'external@person.com' === $person->getLocalData()[self::EMAIL_ATTRIBUTE]
                    && null === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]
                    && null === $person->getLocalData()[self::EMPLOYEE_WORK_ADDRESS_ATTRIBUTE]
            )
        );
    }

    public function testGetPersonsWithLocalDataNewPersonClaimsApiRequestNotFound(): void
    {
        // getting employee address should trigger a new api request
        // however, the persons are not found anymore and thus the address cannot be retrieved
        $this->testPersonProvider->mockEmptyApiResponse();

        $options = [];
        Options::requestLocalDataAttributes($options, [
            self::EMAIL_ATTRIBUTE,
            self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE,
        ]);
        $persons = $this->testPersonProvider->getPersons(1, 10, $options);
        $this->assertCount(4, $persons);
        $this->assertTrue(
            self::containsExactlyOneWhere(
                $persons,
                fn (Person $person) => self::STAFF_USER_IDENTIFIER === $person->getIdentifier()
                    && 'Eleanora' === $person->getGivenName()
                    && 'Quill-Weatherby' === $person->getFamilyName()
                    && 'eleanora.quill@someuni.example' === $person->getLocalData()[self::EMAIL_ATTRIBUTE]
                    && null === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]
            )
        );
        $this->assertTrue(
            self::containsExactlyOneWhere(
                $persons,
                fn (Person $person) => 'student-id' === $person->getIdentifier()
                    && 'Luna' === $person->getGivenName()
                    && 'Pérez-Altamirano' === $person->getFamilyName()
                    && 'luna.perez@someuni.edu' === $person->getLocalData()[self::EMAIL_ATTRIBUTE]
                    && null === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]
            )
        );
        $this->assertTrue(
            self::containsExactlyOneWhere(
                $persons,
                fn (Person $person) => 'alumnus-id' === $person->getIdentifier()
                    && 'Aksel' === $person->getGivenName()
                    && 'Østergaard' === $person->getFamilyName()
                    && 'aksel.ostergaard@alumni.someuni.at' === $person->getLocalData()[self::EMAIL_ATTRIBUTE]
                    && null === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]
            )
        );
        $this->assertTrue(
            self::containsExactlyOneWhere(
                $persons,
                fn (Person $person) => TestPersonProvider::EXTERNAL_USER_IDENTIFIER === $person->getIdentifier()
                    && 'External' === $person->getGivenName()
                    && 'Person' === $person->getFamilyName()
                    && 'external@person.com' === $person->getLocalData()[self::EMAIL_ATTRIBUTE]
                    && null === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]
            )
        );
    }

    public function testGetPersonsWithLocalDataNewUserApiRequest(): void
    {
        // getting username should trigger a new user api request
        $this->testPersonProvider->mockUserApiResponse();

        $options = [];
        Options::requestLocalDataAttributes($options, [
            self::USERNAME_ATTRIBUTE,
        ]);
        $persons = $this->testPersonProvider->getPersons(1, 30, $options);
        $this->assertCount(4, $persons);
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => self::STAFF_USER_IDENTIFIER === $person->getIdentifier()
                && 'Eleanora' === $person->getGivenName()
                && 'Quill-Weatherby' === $person->getFamilyName()
                && 1 === count($person->getLocalData())
                && 'maxm' === $person->getLocalData()[self::USERNAME_ATTRIBUTE]
        ));
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => 'student-id' === $person->getIdentifier()
                && 'Luna' === $person->getGivenName()
                && 'Pérez-Altamirano' === $person->getFamilyName()
                && 1 === count($person->getLocalData())
                && 'maxs' === $person->getLocalData()[self::USERNAME_ATTRIBUTE]
        ));
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => 'alumnus-id' === $person->getIdentifier()
                && 'Aksel' === $person->getGivenName()
                && 'Østergaard' === $person->getFamilyName()
                && 1 === count($person->getLocalData())
                && 'maxa' === $person->getLocalData()[self::USERNAME_ATTRIBUTE]
        ));
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => TestPersonProvider::EXTERNAL_USER_IDENTIFIER === $person->getIdentifier()
                && 'External' === $person->getGivenName()
                && 'Person' === $person->getFamilyName()
                && 1 === count($person->getLocalData())
                && null === $person->getLocalData()[self::USERNAME_ATTRIBUTE]
        ));
    }

    public function testGetPersonsWithLocalDataNewUserApiRequestNotFound(): void
    {
        // getting username should trigger a new user api request
        $this->testPersonProvider->mockEmptyApiResponse();

        $options = [];
        Options::requestLocalDataAttributes($options, [
            self::USERNAME_ATTRIBUTE,
        ]);
        $persons = $this->testPersonProvider->getPersons(1, 30, $options);
        $this->assertCount(4, $persons);
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => self::STAFF_USER_IDENTIFIER === $person->getIdentifier()
                && 'Eleanora' === $person->getGivenName()
                && 'Quill-Weatherby' === $person->getFamilyName()
                && 1 === count($person->getLocalData())
                && null === $person->getLocalData()[self::USERNAME_ATTRIBUTE]
        ));
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => 'student-id' === $person->getIdentifier()
                && 'Luna' === $person->getGivenName()
                && 'Pérez-Altamirano' === $person->getFamilyName()
                && 1 === count($person->getLocalData())
                && null === $person->getLocalData()[self::USERNAME_ATTRIBUTE]
        ));
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => 'alumnus-id' === $person->getIdentifier()
                && 'Aksel' === $person->getGivenName()
                && 'Østergaard' === $person->getFamilyName()
                && 1 === count($person->getLocalData())
                && null === $person->getLocalData()[self::USERNAME_ATTRIBUTE]
        ));
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => TestPersonProvider::EXTERNAL_USER_IDENTIFIER === $person->getIdentifier()
                && 'External' === $person->getGivenName()
                && 'Person' === $person->getFamilyName()
                && null === $person->getLocalData()[self::USERNAME_ATTRIBUTE]
        ));
    }

    public function testGetPersonsWithLocalDataNewUserApiAndPersonClaimsApiRequest(): void
    {
        // getting username/address should trigger a new user/person claims api request
        $this->testPersonProvider->mockApiResponses([
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], $this->testPersonProvider->getPersonClaimsApiTestResponse()),
            new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], $this->testPersonProvider->getUserApiTestResponse()),
        ]);

        $options = [];
        Options::requestLocalDataAttributes($options, [
            self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE,
            self::USERNAME_ATTRIBUTE,
        ]);
        $persons = $this->testPersonProvider->getPersons(1, 30, $options);
        $this->assertCount(4, $persons);
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => self::STAFF_USER_IDENTIFIER === $person->getIdentifier()
                && 'Eleanora' === $person->getGivenName()
                && 'Quill-Weatherby' === $person->getFamilyName()
                && 2 === count($person->getLocalData())
                && 'maxm' === $person->getLocalData()[self::USERNAME_ATTRIBUTE]
                && 'Graz' === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]['city']
                && 'AT' === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]['country']
                && '8010' === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]['postalCode']
                && 'Street 123' === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]['street']
                && 'PA' === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]['addressTypeKey']
        ));
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => 'student-id' === $person->getIdentifier()
                && 'Luna' === $person->getGivenName()
                && 'Pérez-Altamirano' === $person->getFamilyName()
                && 2 === count($person->getLocalData())
                && 'maxs' === $person->getLocalData()[self::USERNAME_ATTRIBUTE]
                && null === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]
        ));
        $this->assertTrue(self::containsExactlyOneWhere(
            $persons,
            fn (Person $person) => 'alumnus-id' === $person->getIdentifier()
                && 'Aksel' === $person->getGivenName()
                && 'Østergaard' === $person->getFamilyName()
                && 2 === count($person->getLocalData())
                && 'maxa' === $person->getLocalData()[self::USERNAME_ATTRIBUTE]
                && null === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]
        ));
        $this->assertTrue(
            self::containsExactlyOneWhere(
                $persons,
                fn (Person $person) => TestPersonProvider::EXTERNAL_USER_IDENTIFIER === $person->getIdentifier()
                    && 'External' === $person->getGivenName()
                    && 'Person' === $person->getFamilyName()
                    && null === $person->getLocalData()[self::USERNAME_ATTRIBUTE]
                    && null === $person->getLocalData()[self::EMPLOYEE_POSTAL_ADDRESS_ATTRIBUTE]
            )
        );
    }

    public function testGetPersonsWithSearchParameter(): void
    {
        $options = [
            Person::SEARCH_PARAMETER_NAME => 'altamir',
        ];
        $persons = $this->testPersonProvider->getPersons(1, 10, $options);
        $this->assertCount(1, $persons);
        $person = $persons[0];
        $this->assertSame(self::STUDENT_USER_IDENTIFIER, $person->getIdentifier());

        $options = [
            Person::SEARCH_PARAMETER_NAME => 'altamir luna',
        ];
        $persons = $this->testPersonProvider->getPersons(1, 10, $options);
        $this->assertCount(1, $persons);
        $person = $persons[0];
        $this->assertSame(self::STUDENT_USER_IDENTIFIER, $person->getIdentifier());

        $options = [
            Person::SEARCH_PARAMETER_NAME => 'alamir foo',
        ];
        $persons = $this->testPersonProvider->getPersons(1, 10, $options);
        $this->assertCount(0, $persons);

        $options = [
            Person::SEARCH_PARAMETER_NAME => 'foo',
        ];
        $persons = $this->testPersonProvider->getPersons(1, 10, $options);
        $this->assertCount(0, $persons);
    }

    public function testGetPersonIdentifierByUsername(): void
    {
        $this->testPersonProvider->mockUserApiResponse();

        $personIdentifier = $this->testPersonProvider->getPersonIdentifierByUsername('maxm');
        $this->assertEquals('staff-id', $personIdentifier);

        $this->testPersonProvider->mockEmptyApiResponse();

        $this->assertNull($this->testPersonProvider->getPersonIdentifierByUsername('foo'));
    }

    public function testGetPersonIdentifierByEmail(): void
    {
        $this->testPersonProvider->mockUserApiResponse();

        $personIdentifier = $this->testPersonProvider->getPersonIdentifierByEmail('eleanora.quill@someuni.example');
        $this->assertEquals('staff-id', $personIdentifier);

        $this->testPersonProvider->mockEmptyApiResponse();

        $this->assertNull($this->testPersonProvider->getPersonIdentifierByEmail('foo'));
    }

    public function testGetCurrentResultPersonIdentifiersItem(): void
    {
        $this->testPersonProvider->getPerson(self::STAFF_USER_IDENTIFIER);

        $identifiers = $this->testPersonProvider->getCurrentResultPersonIdentifiers();
        $this->assertCount(1, $identifiers);
        $this->assertContains(self::STAFF_USER_IDENTIFIER, $identifiers);
    }

    public function testGetCurrentResultPersonIdentifiersCollection(): void
    {
        $this->testPersonProvider->getPersons(1, 10); // caches the person ids of current request result

        $identifiers = $this->testPersonProvider->getCurrentResultPersonIdentifiers();
        $this->assertCount(4, $identifiers);
        $this->assertContains(self::STAFF_USER_IDENTIFIER, $identifiers);
        $this->assertContains(self::STUDENT_USER_IDENTIFIER, $identifiers);
        $this->assertContains(self::ALUMNUS_USER_IDENTIFIER, $identifiers);
        $this->assertContains(TestPersonProvider::EXTERNAL_USER_IDENTIFIER, $identifiers);
    }

    public function testGetPersonClaimsResourceFromApiCached(): void
    {
        $this->testPersonProvider->mockPersonClaimsApiResponse();

        $personClaims = $this->testPersonProvider->getPersonClaimsResourceFromApiCached(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $personClaims->getUid());
        $this->assertSame('eleanora.quill@someuni.example', $personClaims->getEmail());

        // NOTE: no more requests must be made
        $personClaims = $this->testPersonProvider->getPersonClaimsResourceFromApiCached(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $personClaims->getUid());
        $this->assertSame('eleanora.quill@someuni.example', $personClaims->getEmail());
    }

    public function testGetPersonClaimsResourceFromApiCachedNotFound(): void
    {
        $this->testPersonProvider->mockEmptyApiResponse();

        $this->assertNull($this->testPersonProvider->getPersonClaimsResourceFromApiCached('foo'));
        // NOTE: no more requests must be made
        $this->assertNull($this->testPersonProvider->getPersonClaimsResourceFromApiCached('foo'));
    }

    public function testGetPersonClaimsResourceFromApiCachedAfterGetPerson(): void
    {
        $this->testPersonProvider->getPerson(self::STAFF_USER_IDENTIFIER);

        $this->testPersonProvider->mockPersonClaimsApiResponse();

        $personClaims = $this->testPersonProvider->getPersonClaimsResourceFromApiCached(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $personClaims->getUid());
        $this->assertSame('eleanora.quill@someuni.example', $personClaims->getEmail());

        // NOTE: no more requests must be made
        $personClaims = $this->testPersonProvider->getPersonClaimsResourceFromApiCached(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $personClaims->getUid());
        $this->assertSame('eleanora.quill@someuni.example', $personClaims->getEmail());
    }

    public function testGetPersonClaimsResourceFromApiCachedAfterGetPersons(): void
    {
        $this->testPersonProvider->getPersons(1, 10); // caches the person ids of current request result

        $this->testPersonProvider->mockPersonClaimsApiResponse();

        $personClaims = $this->testPersonProvider->getPersonClaimsResourceFromApiCached(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $personClaims->getUid());
        $this->assertSame('eleanora.quill@someuni.example', $personClaims->getEmail());

        // NOTE: no more requests must be made
        $personClaims = $this->testPersonProvider->getPersonClaimsResourceFromApiCached(self::STUDENT_USER_IDENTIFIER);
        $this->assertSame(self::STUDENT_USER_IDENTIFIER, $personClaims->getUid());
        $this->assertSame('luna.perez@someuni.edu', $personClaims->getEmail());

        $user = $this->testPersonProvider->getPersonClaimsResourceFromApiCached(self::ALUMNUS_USER_IDENTIFIER);
        $this->assertSame(self::ALUMNUS_USER_IDENTIFIER, $user->getUid());
        $this->assertSame('aksel.ostergaard@alumni.someuni.at', $user->getEmail());
    }

    public function testGetUserResourceFromApiCached(): void
    {
        $this->testPersonProvider->mockUserApiResponse();

        $user = $this->testPersonProvider->getUserResourceFromApiCached(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $user->getPersonUid());
        $this->assertSame('eleanora.quill@someuni.example', $user->getEmail(0));
        $this->assertSame('maxm', $user->getUsername(0));

        // NOTE: no more requests must be made
        $user = $this->testPersonProvider->getUserResourceFromApiCached(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $user->getPersonUid());
        $this->assertSame('eleanora.quill@someuni.example', $user->getEmail(0));
        $this->assertSame('maxm', $user->getUsername(0));
    }

    public function testGetUserFromApiCachedNotFound(): void
    {
        $this->testPersonProvider->mockEmptyApiResponse();

        $this->assertNull($this->testPersonProvider->getUserResourceFromApiCached('foo'));
        // NOTE: no more requests must be made
        $this->assertNull($this->testPersonProvider->getUserResourceFromApiCached('foo'));
    }

    public function testGetUserResourceFromApiCachedAfterGetPerson(): void
    {
        $this->testPersonProvider->getPerson(self::STAFF_USER_IDENTIFIER); // caches the person ids of current request result

        $this->testPersonProvider->mockUserApiResponse();

        $user = $this->testPersonProvider->getUserResourceFromApiCached(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $user->getPersonUid());
        $this->assertSame('eleanora.quill@someuni.example', $user->getEmail(0));
        $this->assertSame('maxm', $user->getUsername(0));

        // NOTE: no more requests must be made
        $user = $this->testPersonProvider->getUserResourceFromApiCached(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $user->getPersonUid());
        $this->assertSame('eleanora.quill@someuni.example', $user->getEmail(0));
        $this->assertSame('maxm', $user->getUsername(0));
    }

    public function testGetUserResourceFromApiCachedAfterGetPersons(): void
    {
        $this->testPersonProvider->getPersons(1, 10); // caches the person ids of current request result

        $this->testPersonProvider->mockUserApiResponse();

        $user = $this->testPersonProvider->getUserResourceFromApiCached(self::STAFF_USER_IDENTIFIER);
        $this->assertSame(self::STAFF_USER_IDENTIFIER, $user->getPersonUid());
        $this->assertSame('eleanora.quill@someuni.example', $user->getEmail(0));
        $this->assertSame('maxm', $user->getUsername(0));

        // NOTE: no more requests must be made
        $user = $this->testPersonProvider->getUserResourceFromApiCached(self::STUDENT_USER_IDENTIFIER);
        $this->assertSame(self::STUDENT_USER_IDENTIFIER, $user->getPersonUid());
        $this->assertSame('luna.perez@someuni.edu', $user->getEmail(0));
        $this->assertSame('maxs', $user->getUsername(0));

        $user = $this->testPersonProvider->getUserResourceFromApiCached(self::ALUMNUS_USER_IDENTIFIER);
        $this->assertSame(self::ALUMNUS_USER_IDENTIFIER, $user->getPersonUid());
        $this->assertSame('aksel.ostergaard@alumni.someuni.at', $user->getEmail(0));
        $this->assertSame('maxa', $user->getUsername(0));
    }

    public function testGetEmployeePostalAddress(): void
    {
        $this->testPersonProvider->mockPersonClaimsApiResponse();

        $address = $this->testPersonProvider->getEmployeePostalAddress(self::STAFF_USER_IDENTIFIER);
        $this->assertEquals('Graz', $address['city']);
        $this->assertEquals('AT', $address['country']);
        $this->assertEquals('8010', $address['postalCode']);
        $this->assertEquals('Street 123', $address['street']);
        $this->assertEquals('PA', $address['addressTypeKey']);
    }

    public function testGetEmployeePostalAddressNotFound(): void
    {
        $this->testPersonProvider->mockEmptyApiResponse();

        $this->assertNull($this->testPersonProvider->getEmployeePostalAddress(self::STAFF_USER_IDENTIFIER));
    }

    public function testGetEmployeeWorkAddress(): void
    {
        $this->testPersonProvider->mockPersonClaimsApiResponse();

        $address = $this->testPersonProvider->getEmployeeWorkAddress(self::STAFF_USER_IDENTIFIER);
        $this->assertEquals('Wien', $address['city']);
        $this->assertEquals('AT', $address['country']);
        $this->assertEquals('1010', $address['postalCode']);
        $this->assertEquals('Hohenplatz 3, Institut für Phantastik', $address['street']);
        $this->assertEquals('DO', $address['addressTypeKey']);
        $this->assertEquals('44', $address['roomIdentifier']);
        $this->assertEquals('37', $address['contactOrganizationIdentifier']);
    }

    public function testGetEmployeeWorkAddressNotFound(): void
    {
        $this->testPersonProvider->mockEmptyApiResponse();

        $this->assertNull($this->testPersonProvider->getEmployeeWorkAddress(self::STAFF_USER_IDENTIFIER));
    }

    public function testIsCurrentUserAnEmployeeTrue(): void
    {
        $this->assertEquals(CachedPerson::YES_WITH_ACCOUNT, $this->testPersonProvider->isCurrentUserAnEmployee());
    }

    public function testIsCurrentUserAnEmployeeFalse(): void
    {
        $this->login(self::STUDENT_USER_IDENTIFIER);
        $this->assertEquals(CachedPerson::NO, $this->testPersonProvider->isCurrentUserAnEmployee());
    }

    public function testIsCurrentUserAnEmployeeUndefined(): void
    {
        $this->login('non-existing-user');
        $this->assertNull($this->testPersonProvider->isCurrentUserAnEmployee());
    }

    public function testIsCurrentUserAStudentTrue(): void
    {
        $this->login(self::STUDENT_USER_IDENTIFIER);
        $this->assertEquals(CachedPerson::YES_WITH_ACCOUNT, $this->testPersonProvider->isCurrentUserAStudent());
    }

    public function testIsCurrentUserAStudentFalse(): void
    {
        $this->login(self::STAFF_USER_IDENTIFIER);
        $this->assertEquals(CachedPerson::NO, $this->testPersonProvider->isCurrentUserAStudent());
    }

    public function testIsCurrentUserAStudentUndefined(): void
    {
        $this->login('non-existing-user');
        $this->assertNull($this->testPersonProvider->isCurrentUserAStudent());
    }

    public function testIsCurrentUserAnAlumniTrue(): void
    {
        $this->login(self::ALUMNUS_USER_IDENTIFIER);
        $this->assertEquals(CachedPerson::YES_WITH_ACCOUNT, $this->testPersonProvider->isCurrentUserAnAlumni());
    }

    public function testIsCurrentUserAnAlumniFalse(): void
    {
        $this->login(self::STAFF_USER_IDENTIFIER);
        $this->assertEquals(CachedPerson::NO, $this->testPersonProvider->isCurrentUserAnAlumni());
    }

    public function testIsCurrentUserAnAlumniUndefined(): void
    {
        $this->login('non-existing-user');
        $this->assertNull($this->testPersonProvider->isCurrentUserAnAlumni());
    }

    public function testIsCurrentUserExternalTrue(): void
    {
        $this->login(TestPersonProvider::EXTERNAL_USER_IDENTIFIER);
        $this->assertEquals(CachedPerson::YES_WITHOUT_ACCOUNT, $this->testPersonProvider->isCurrentUserExternal());
    }

    public function testIsCurrentUserExternalFalse(): void
    {
        $this->login(self::STAFF_USER_IDENTIFIER);
        $this->assertEquals(CachedPerson::NO, $this->testPersonProvider->isCurrentUserExternal());
    }

    public function testIsCurrentUserExternalUndefined(): void
    {
        $this->login('non-existing-user');
        $this->assertNull($this->testPersonProvider->isCurrentUserExternal());
    }

    private function login(?string $userIdentifier, array $userAttributes = []): void
    {
        $this->testPersonProvider->login($userIdentifier, $userAttributes);
    }

    protected static function containsExactlyOneWhere(array $results, callable $where): bool
    {
        return 1 === count(array_filter($results, $where));
    }
}
