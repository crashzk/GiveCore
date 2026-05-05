<?php

namespace Flute\Modules\GiveCore\Give;

use Flute\Core\Database\Entities\Server;
use Flute\Core\Database\Entities\User;
use Flute\Modules\GiveCore\Contracts\CheckableInterface;
use Flute\Modules\GiveCore\Contracts\DriverInterface;
use Flute\Modules\GiveCore\Exceptions\BadConfigurationException;
use Flute\Modules\GiveCore\Give\Drivers\AdminSystemDriver;
use Flute\Modules\GiveCore\Give\Drivers\AmxModDriver;
use Flute\Modules\GiveCore\Give\Drivers\AmxUnbanDriver;
use Flute\Modules\GiveCore\Give\Drivers\CustomWeaponsDriver;
use Flute\Modules\GiveCore\Give\Drivers\FabiusDriver;
use Flute\Modules\GiveCore\Give\Drivers\FreshBansAdminDriver;
use Flute\Modules\GiveCore\Give\Drivers\FreshBansUnbanDriver;
use Flute\Modules\GiveCore\Give\Drivers\IKSAdminDriver;
use Flute\Modules\GiveCore\Give\Drivers\K4SystemDriver;
use Flute\Modules\GiveCore\Give\Drivers\LiteBansDriver;
use Flute\Modules\GiveCore\Give\Drivers\LuckPermsDriver;
use Flute\Modules\GiveCore\Give\Drivers\PexDriver;
use Flute\Modules\GiveCore\Give\Drivers\RconDriver;
use Flute\Modules\GiveCore\Give\Drivers\SimpleAdminDriver;
use Flute\Modules\GiveCore\Give\Drivers\SourceBansDriver;
use Flute\Modules\GiveCore\Give\Drivers\VipDriver;

class GiveFactory
{
    protected array $drivers = [
        'vip' => VipDriver::class,
        'fabius' => FabiusDriver::class,
        'rcon' => RconDriver::class,
        'adminsystem' => AdminSystemDriver::class,
        'iks' => IKSAdminDriver::class,
        'simpleadmin' => SimpleAdminDriver::class,
        'sourcebans' => SourceBansDriver::class,
        'luckperms' => LuckPermsDriver::class,
        'pex' => PexDriver::class,
        'amxmod' => AmxModDriver::class,
        'amxunban' => AmxUnbanDriver::class,
        'freshbans' => FreshBansUnbanDriver::class,
        'freshbans_admin' => FreshBansAdminDriver::class,
        'k4system' => K4SystemDriver::class,
        'litebans' => LiteBansDriver::class,
        'custom_weapons' => CustomWeaponsDriver::class,
    ];

    /**
     * @var array<string, DriverInterface>
     */
    protected array $instances = [];

    /**
     * Get all registered driver classes.
     */
    public function getAll(): array
    {
        return $this->drivers;
    }

    /**
     * Execute delivery via a named driver.
     */
    public function make(
        string $name,
        User $user,
        Server $server,
        array $additional = [],
        ?int $timeId = null,
        bool $ignoreErrors = false,
    ): bool {
        if (!$this->exists($name)) {
            throw new BadConfigurationException(__('givecore.errors.driver_not_found', ['driver' => $name]));
        }

        return $this->getDriver($name)->deliver($user, $server, $additional, $timeId, $ignoreErrors);
    }

    /**
     * Register a new delivery driver.
     */
    public function add(string $class): self
    {
        /** @var DriverInterface $instance */
        $instance = new $class();

        $this->drivers[$instance->alias()] = $class;
        unset($this->instances[$instance->alias()]);

        return $this;
    }

    /**
     * Remove a driver by alias.
     */
    public function remove(string $alias): self
    {
        unset($this->drivers[$alias], $this->instances[$alias]);

        return $this;
    }

    public function exists(string $name): bool
    {
        return array_key_exists($name, $this->drivers);
    }

    /**
     * Get a cached driver instance by alias.
     */
    public function getDriver(string $name): ?DriverInterface
    {
        if (!$this->exists($name)) {
            return null;
        }

        if (!isset($this->instances[$name])) {
            $this->instances[$name] = new $this->drivers[$name]();
        }

        return $this->instances[$name];
    }

    /**
     * Get metadata for all registered drivers.
     *
     * @return array<string, array{alias: string, name: string, description: string, icon: string, category: string, isAvailable: bool, unavailableReason: ?string, canCheck: bool}>
     */
    public function getDriversMeta(): array
    {
        $meta = [];

        foreach ($this->drivers as $alias => $class) {
            $driver = $this->getDriver($alias);

            $meta[$alias] = [
                'alias' => $alias,
                'name' => $driver->name(),
                'description' => $driver->description(),
                'icon' => $driver->icon(),
                'category' => $driver->category(),
                'isAvailable' => $driver->isAvailable(),
                'unavailableReason' => $driver->unavailableReason(),
                'canCheck' => $driver instanceof CheckableInterface,
                'dbConnectionKey' => $driver->dbConnectionKey(),
                'sourceUrl' => $driver->sourceUrl(),
                'supportedGames' => $driver->supportedGames(),
            ];
        }

        return $meta;
    }

    /**
     * Get delivery drivers that also support condition checking.
     *
     * @return array<string, DriverInterface&CheckableInterface>
     */
    public function getCheckableDrivers(): array
    {
        $result = [];

        foreach ($this->drivers as $alias => $class) {
            $driver = $this->getDriver($alias);
            if ($driver instanceof CheckableInterface) {
                $result[$alias] = $driver;
            }
        }

        return $result;
    }
}
