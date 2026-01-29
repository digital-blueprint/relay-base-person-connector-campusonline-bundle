Relay-API Bundle README Template
================================

# DbpRelayBasePersonConnectorCampusonlineBundle

[GitHub](https://github.com/digital-blueprint/relay-base-person-connector-campusonline-bundle) |
[Packagist](https://packagist.org/packages/dbp/relay-base-person-connector-campusonline-bundle) |
[BasePersonConnectorCampusonline Website](https://mywebsite.org/site/software/base-person-connector-campusonline.html)

The base-person-connector-campusonline bundle provides an API for interacting with ...

## Bundle installation

You can install the bundle directly from [packagist.org](https://packagist.org/packages/dbp/relay-base-person-connector-campusonline-bundle).

```bash
composer require dbp/relay-base-person-connector-campusonline-bundle
```

## Integration into the Relay API Server

* Add the bundle to your `config/bundles.php` in front of `DbpRelayCoreBundle`:

```php
...
Dbp\Relay\BasePersonConnectorCampusonlineBundle\DbpRelayBasePersonConnectorCampusonlineBundle::class => ['all' => true],
Dbp\Relay\CoreBundle\DbpRelayCoreBundle::class => ['all' => true],
];
```

If you were using the [DBP API Server Template](https://packagist.org/packages/dbp/relay-server-template)
as template for your Symfony application, then this should have already been generated for you.

* Run `composer install` to clear caches

## Configuration

The bundle has a `database_url` configuration value that you can specify in your
app, either by hard-coding it, or by referencing an environment variable.

For this create `config/packages/dbp_relay_base-person-connector-campusonline.yaml` in the app with the following
content:

```yaml
dbp_relay_base-person-connector-campusonline:
  database_url: 'mysql://db:secret@mariadb:3306/db?serverVersion=mariadb-10.3.30'
  # database_url: %env({{NAME}}_DATABASE_URL)%
```

If you were using the [DBP API Server Template](https://packagist.org/packages/dbp/relay-server-template)
as template for your Symfony application, then the configuration file should have already been generated for you.

For more info on bundle configuration see <https://symfony.com/doc/current/bundles/configuration.html>.

## Development & Testing

* Install dependencies: `composer install`
* Run tests: `composer test`
* Run linters: `composer run lint`
* Run cs-fixer: `composer run cs-fix`

## Bundle dependencies

Don't forget you need to pull down your dependencies in your main application if you are installing packages in a bundle.

```bash
# updates and installs dependencies of dbp/relay-base-person-connector-campusonline-bundle
composer update dbp/relay-base-person-connector-campusonline-bundle
```

## Scripts

### Database migration

Run this script to migrate the database. Run this script after installation of the bundle and
after every update to adapt the database to the new source code.

```bash
php bin/console doctrine:migrations:migrate --em=dbp_relay_base-person-connector-campusonline_bundle
```