<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Laravel\Facades;

use Hampel\BinaryLane\Api\Authentication\Authentication;
use Hampel\BinaryLane\Api\Client;
use Hampel\BinaryLane\Api\Config;
use Hampel\BinaryLane\Api\Connection;
use Hampel\BinaryLane\Api\Endpoint\Account;
use Hampel\BinaryLane\Api\Endpoint\Actions;
use Hampel\BinaryLane\Api\Endpoint\Billing;
use Hampel\BinaryLane\Api\Endpoint\DataUsages;
use Hampel\BinaryLane\Api\Endpoint\DomainRecords;
use Hampel\BinaryLane\Api\Endpoint\Domains;
use Hampel\BinaryLane\Api\Endpoint\Endpoint;
use Hampel\BinaryLane\Api\Endpoint\Images;
use Hampel\BinaryLane\Api\Endpoint\LoadBalancers;
use Hampel\BinaryLane\Api\Endpoint\Regions;
use Hampel\BinaryLane\Api\Endpoint\ReverseNames;
use Hampel\BinaryLane\Api\Endpoint\SampleSets;
use Hampel\BinaryLane\Api\Endpoint\ServerActions;
use Hampel\BinaryLane\Api\Endpoint\Servers;
use Hampel\BinaryLane\Api\Endpoint\Sizes;
use Hampel\BinaryLane\Api\Endpoint\SoftwareCatalogue;
use Hampel\BinaryLane\Api\Endpoint\SshKeys;
use Hampel\BinaryLane\Api\Endpoint\Vpcs;
use Hampel\BinaryLane\Api\Entity\Account as AccountEntity;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the BinaryLane API manager.
 *
 *     BinaryLane::servers()->list();                      // the default account
 *     BinaryLane::client('reseller')->servers()->list();  // a named one
 *
 * The operations are one hop further in, so the annotations below cover the hop and the
 * endpoint classes carry the typed signatures from there. Without them every call through the
 * facade is untyped to both the IDE and PHPStan, which is most of what a facade costs you.
 *
 * Everything after configuredAccounts() mirrors a method on the core package's Client, reached
 * through the manager's __call(). FacadeConformanceTest asserts that the two lists stay
 * identical, so a method added to the client in a later release shows up as a failing test
 * rather than as a call that silently loses its type.
 *
 * NOTE WHICH account() THIS IS. It is BinaryLane's account endpoint - `GET /v2/account` - and
 * not a way to reach a configured account, which is client(). The core package's own naming
 * decides this one; see BinaryLaneManager.
 *
 * endpoint() is the one annotation that gives something up: on the client it is generic,
 * returning the class it was handed, and a @method line cannot express that. Reach for
 * `BinaryLane::client()->endpoint(Foo::class)` where the generic return matters.
 *
 * @method static Client client(?string $name = null)
 * @method static string getDefaultAccount()
 * @method static list<string> configuredAccounts()
 * @method static Config config()
 * @method static Authentication authentication()
 * @method static AccountEntity verify()
 * @method static Client withCredential(Authentication $authentication)
 * @method static Client withConfig(Config $config)
 * @method static Endpoint endpoint(string $class)
 * @method static Account account()
 * @method static Actions actions()
 * @method static Domains domains()
 * @method static DomainRecords records()
 * @method static Servers servers()
 * @method static ServerActions serverActions()
 * @method static Images images()
 * @method static SshKeys sshKeys()
 * @method static Sizes sizes()
 * @method static Regions regions()
 * @method static SoftwareCatalogue software()
 * @method static LoadBalancers loadBalancers()
 * @method static Vpcs vpcs()
 * @method static Billing billing()
 * @method static DataUsages dataUsages()
 * @method static SampleSets sampleSets()
 * @method static ReverseNames reverseNames()
 * @method static Connection connection()
 *
 * @see \Hampel\BinaryLane\Api\Laravel\BinaryLaneManager
 * @see \Hampel\BinaryLane\Api\Client
 */
final class BinaryLane extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Hampel\BinaryLane\Api\Laravel\BinaryLaneManager::class;
    }
}
