<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Tests;

use Hampel\BinaryLane\Api\Authentication\ApiToken;
use Hampel\BinaryLane\Api\Client;
use Hampel\BinaryLane\Api\Exception\ExceptionInterface;
use Hampel\BinaryLane\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\BinaryLane\Api\Laravel\Exception\UnknownAccount;
use Hampel\BinaryLane\Api\Laravel\Facades\BinaryLane;
use Hampel\BinaryLane\Api\Laravel\BinaryLaneManager;
use Illuminate\Contracts\Config\Repository as Config;
use PHPUnit\Framework\Attributes\Test;

final class ManagerTest extends TestCase
{
    #[Test]
    public function it_resolves_a_client_for_a_named_account(): void
    {
        $client = $this->manager()->client('reseller');

        $this->assertInstanceOf(Client::class, $client);
        $this->assertInstanceOf(ApiToken::class, $client->authentication());
    }

    #[Test]
    public function it_defaults_to_the_configured_account(): void
    {
        $this->assertSame('main', $this->manager()->getDefaultAccount());
        $this->assertSame(
            $this->manager()->client('main'),
            $this->manager()->client(),
        );
    }

    #[Test]
    public function clients_are_memoised_per_account(): void
    {
        $manager = $this->manager();

        $this->assertSame($manager->client('main'), $manager->client('main'));
        $this->assertNotSame($manager->client('main'), $manager->client('reseller'));
    }

    #[Test]
    public function the_manager_is_a_singleton_and_the_facade_resolves_it(): void
    {
        $this->assertSame($this->manager(), $this->container()->make(BinaryLaneManager::class));
        $this->assertSame($this->manager(), BinaryLane::getFacadeRoot());
    }

    #[Test]
    public function it_lists_the_configured_accounts(): void
    {
        $this->assertSame(['main', 'reseller'], $this->manager()->configuredAccounts());
    }

    #[Test]
    public function an_unknown_account_names_the_ones_that_are_configured(): void
    {
        $this->expectException(UnknownAccount::class);
        $this->expectExceptionMessage('Configured accounts: main, reseller.');

        $this->manager()->client('nope');
    }

    #[Test]
    public function the_packages_exceptions_are_catchable_alongside_the_cores(): void
    {
        // Extending the core's base exception rather than declaring a hierarchy of our own, so
        // an application already catching ExceptionInterface catches misconfiguration too
        // rather than meeting it as an unhandled error.
        $this->expectException(ExceptionInterface::class);

        $this->manager()->client('nope');
    }

    #[Test]
    public function an_account_without_a_token_is_refused(): void
    {
        // Refused here rather than allowed to 401 on first use, which would read as a revoked
        // token rather than as an unset environment variable.
        $this->configure('nowhere', []);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('has no token');

        $this->manager()->client('nowhere');
    }

    #[Test]
    public function an_empty_token_is_treated_as_absent(): void
    {
        // An unset environment variable reaches config as "" as readily as it reaches it as
        // null, and the core package would refuse a blank token with a message about an
        // argument rather than about the environment variable nobody set.
        $this->configure('blank', ['token' => '  ']);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('has no token');

        $this->manager()->client('blank');
    }

    #[Test]
    public function the_api_settings_reach_the_cores_config(): void
    {
        $config = $this->container()->make(Config::class);
        $config->set('binarylane.base_uri', 'https://binarylane.example.test/');
        $config->set('binarylane.per_page', '150');

        $api = $this->manager()->client()->config();

        // Trailing slash trimmed by the core package, and the version is not part of the base.
        $this->assertSame('https://binarylane.example.test', $api->baseUri);
        $this->assertSame(150, $api->perPage);
        $this->assertSame('https://binarylane.example.test/v2/servers', $api->resolve('servers'));
    }

    #[Test]
    public function a_page_size_read_from_the_environment_as_a_string_is_still_a_number(): void
    {
        // env() answers with a string, so a config value that looks like an int is not one. A
        // page size that arrived as "200" and was passed through as a string would be a type
        // error at the core package's boundary rather than a setting that works.
        $this->container()->make(Config::class)->set('binarylane.per_page', '200');

        $this->assertSame(200, $this->manager()->client()->config()->perPage);
    }

    #[Test]
    public function one_config_is_shared_by_every_account(): void
    {
        // There is exactly one BinaryLane, so the page size and base URI describe the API
        // rather than an account. Asserted because the alternative - a Config per client - is
        // what a reader would assume from the constructor.
        $manager = $this->manager();

        $this->assertSame($manager->client('main')->config(), $manager->client('reseller')->config());
    }

    #[Test]
    public function a_page_size_above_the_apis_maximum_names_the_configuration(): void
    {
        $this->container()->make(Config::class)->set('binarylane.per_page', 500);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('binarylane.per_page');

        $this->manager()->client();
    }

    #[Test]
    public function a_page_size_of_zero_is_refused_although_the_core_accepts_it(): void
    {
        // per_page=0 is BinaryLane's "count only" request: a total and no items. The core
        // package allows it because it is a legitimate thing to ask for once. As a default for
        // every list it would make each one answer an empty page, which reads as an account
        // with nothing on it.
        $this->container()->make(Config::class)->set('binarylane.per_page', '0');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('binarylane.per_page is 0');

        $this->manager()->client();
    }

    #[Test]
    public function an_unset_page_size_leaves_the_apis_own_default(): void
    {
        $this->container()->make(Config::class)->set('binarylane.per_page', '');

        $this->assertNull($this->manager()->client()->config()->perPage);
    }

    #[Test]
    public function a_base_uri_without_a_scheme_is_refused_before_a_request_is_spent(): void
    {
        $this->container()->make(Config::class)->set('binarylane.base_uri', 'api.binarylane.com.au');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('binarylane.base_uri');

        $this->manager()->client();
    }

    #[Test]
    public function the_original_reason_is_kept_as_the_previous_exception(): void
    {
        $this->container()->make(Config::class)->set('binarylane.base_uri', 'api.binarylane.com.au');

        try {
            $this->manager()->client();
            $this->fail('Expected an InvalidConfiguration.');
        } catch (InvalidConfiguration $e) {
            $previous = $e->getPrevious();

            $this->assertNotNull($previous);
            $this->assertStringContainsString('must be absolute', $previous->getMessage());
        }
    }

    #[Test]
    public function an_unset_default_falls_back_to_main(): void
    {
        $this->container()->make(Config::class)->set('binarylane.default', '');

        $this->assertSame('main', $this->manager()->getDefaultAccount());
    }

    #[Test]
    public function unnamed_calls_are_forwarded_to_the_default_account(): void
    {
        $this->assertSame(
            $this->manager()->client()->servers(),
            $this->manager()->servers(),
        );
    }

    #[Test]
    public function a_method_the_client_does_not_have_is_a_bad_method_call(): void
    {
        $this->expectException(\BadMethodCallException::class);
        $this->expectExceptionMessage('Call to undefined method');

        // Through __call() explicitly. That is the method under test - the guard that turns a
        // typo into a named error instead of a fatal one hop further in.
        $this->manager()->__call('noSuchEndpoint', []);
    }

    #[Test]
    public function an_empty_inventory_is_inspectable_rather_than_fatal(): void
    {
        // An application whose account list comes from a file rather than from config needs to
        // report WHY it is empty - a missing or unparseable inventory - rather than have every
        // command die. Nothing here throws until a specific account is named, so a diagnostic
        // command can resolve the manager and describe the situation.
        $this->container()->make(Config::class)->set('binarylane.accounts', []);

        $manager = $this->manager();

        $this->assertSame([], $manager->configuredAccounts());
        $this->assertSame('main', $manager->getDefaultAccount());

        $this->expectException(UnknownAccount::class);
        $this->expectExceptionMessage('No accounts are configured');

        $manager->client('somewhere');
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function configure(string $name, array $settings): void
    {
        $config = $this->container()->make(Config::class);

        $accounts = $config->get('binarylane.accounts');
        $config->set('binarylane.accounts', array_merge(is_array($accounts) ? $accounts : [], [$name => $settings]));
    }

    private function manager(): BinaryLaneManager
    {
        return $this->container()->make(BinaryLaneManager::class);
    }
}
