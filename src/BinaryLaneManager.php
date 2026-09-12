<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel;

use BadMethodCallException;
use Hampel\BinaryLane\Api\Authentication\ApiToken;
use Hampel\BinaryLane\Api\Client;
use Hampel\BinaryLane\Api\Config as ApiConfig;
use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;
use Hampel\BinaryLane\Api\Laravel\Exception\InvalidConfiguration;
use Hampel\BinaryLane\Api\Laravel\Exception\UnknownAccount;
use Illuminate\Contracts\Config\Repository as Config;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * One BinaryLane client per configured account.
 *
 *     BinaryLane::servers()->list();                      // the default account
 *     BinaryLane::client('reseller')->servers()->list();  // a named one
 *
 * IT IS NAMED client() AND NOT account(), which looks like a worse word and is the only one
 * available: `Client::account()` is BinaryLane's own account endpoint, and the manager
 * forwards unknown calls to the default client, so an account() here would shadow it. A facade
 * where `BinaryLane::account()` sometimes means a client and sometimes means `GET /v2/account`
 * would be worse than an imperfect name. connection() is taken for the same reason.
 *
 * Clients are memoised per name. The transport underneath them is not - see
 * PendingRequestClient, which resolves Laravel's HTTP factory at the moment of sending so that
 * `Http::fake()` works whenever it is called.
 *
 * The @mixin is what makes $manager->servers() analysable: __call() forwards anything the
 * client answers to, and one line that cannot drift says so. The facade repeats the list
 * explicitly because @method static is the only form __callStatic() can carry, and a test
 * keeps that copy honest.
 *
 * @mixin Client
 */
final class BinaryLaneManager
{
    /** @var array<string, Client> */
    private array $clients = [];

    /**
     * Which API, and how its URLs are built.
     *
     * ONE INSTANCE, SHARED BY EVERY ACCOUNT'S CLIENT, because there is exactly one BinaryLane -
     * the base URI and page size describe the API rather than an account, so they are top-level
     * settings rather than per-account ones. An application wanting one client configured
     * differently asks a client for it: `withConfig()` returns a second client rather than
     * mutating this.
     */
    private ?ApiConfig $apiConfig = null;

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The client for a configured account, or for the default when no name is given.
     */
    public function client(?string $name = null): Client
    {
        $name ??= $this->getDefaultAccount();

        return $this->clients[$name] ??= $this->build($name);
    }

    public function getDefaultAccount(): string
    {
        $default = $this->config->get('binarylane.default');

        return is_string($default) && trim($default) !== '' ? trim($default) : 'main';
    }

    /**
     * The configured account names, in the order they were declared.
     *
     * @return list<string>
     */
    public function configuredAccounts(): array
    {
        $accounts = $this->config->get('binarylane.accounts');

        return is_array($accounts) ? array_values(array_filter(array_keys($accounts), 'is_string')) : [];
    }

    private function build(string $name): Client
    {
        $settings = $this->config->get('binarylane.accounts.' . $name);

        if (! is_array($settings)) {
            throw UnknownAccount::named($name, $this->configuredAccounts());
        }

        $token = $this->string($settings, 'token');

        if ($token === null) {
            throw InvalidConfiguration::missingToken($name);
        }

        return new Client(
            $this->api(),
            new ApiToken($token),
            $this->client,
            $this->requestFactory,
            $this->streamFactory,
            $this->logger,
        );
    }

    /**
     * The core package's Config, built from the top-level settings and memoised.
     *
     * Its constructor validates: a page size above BinaryLane's 200 and a base URI with no
     * scheme are both refused here rather than on the first request. The refusal is re-raised
     * as InvalidConfiguration so the message names the configuration and not an argument.
     *
     * A PAGE SIZE BELOW 1 IS REFUSED HERE, although the core package accepts 0. `per_page=0`
     * is BinaryLane's documented "count only" request - a meaningful thing to ask for once, and
     * never a meaningful default: as a setting it would make every list() answer an empty page
     * that does not say it is not the end of the data.
     */
    private function api(): ApiConfig
    {
        if ($this->apiConfig !== null) {
            return $this->apiConfig;
        }

        $baseUri = $this->config->get('binarylane.base_uri');
        $perPage = $this->config->get('binarylane.per_page');

        if (is_numeric($perPage) && (int) $perPage < 1) {
            throw InvalidConfiguration::pageSizeBelowOne((int) $perPage);
        }

        try {
            return $this->apiConfig = new ApiConfig(
                is_string($baseUri) && trim($baseUri) !== '' ? trim($baseUri) : null,
                is_numeric($perPage) ? (int) $perPage : null,
            );
        } catch (InvalidArgumentException $e) {
            throw InvalidConfiguration::api($e);
        }
    }

    /**
     * One setting, as a non-empty string.
     *
     * Empty is treated as absent throughout, because an unset environment variable reaches
     * config as an empty string as readily as it reaches it as null, and "" is never a
     * meaningful token.
     *
     * @param  array<mixed>  $settings
     */
    private function string(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Anything else goes to the default account's client, so a single-account application
     * never has to name one: `BinaryLane::servers()` rather than
     * `BinaryLane::client('main')->servers()`.
     *
     * The facade carries a @method line for each of these, which is where the types come from -
     * see Facades\BinaryLane, and the test that keeps the two in step.
     *
     * @param  array<mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $client = $this->client();

        if (! method_exists($client, $method)) {
            throw new BadMethodCallException(sprintf(
                'Call to undefined method %s::%s(). The manager forwards to %s.',
                self::class,
                $method,
                Client::class
            ));
        }

        return $client->{$method}(...$arguments);
    }
}
