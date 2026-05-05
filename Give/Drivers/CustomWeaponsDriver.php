<?php

namespace Flute\Modules\GiveCore\Give\Drivers;

use Flute\Core\Database\Entities\Server;
use Flute\Core\Database\Entities\User;
use Flute\Modules\GiveCore\Contracts\CheckableInterface;
use Flute\Modules\GiveCore\Exceptions\BadConfigurationException;
use Flute\Modules\GiveCore\Exceptions\UserSocialException;
use Flute\Modules\GiveCore\Support\AbstractDriver;
use Flute\Modules\GiveCore\Support\CheckableTrait;
use Nette\Utils\Json;

class CustomWeaponsDriver extends AbstractDriver implements CheckableInterface
{
    use CheckableTrait;

    protected const MOD_KEY = 'CustomWeapons';

    public function alias(): string
    {
        return 'custom_weapons';
    }

    public function name(): string
    {
        return __('givecore.drivers.custom_weapons.name');
    }

    public function description(): string
    {
        return __('givecore.drivers.custom_weapons.description');
    }

    public function icon(): string
    {
        return 'ph.bold.crosshair-bold';
    }

    public function category(): string
    {
        return 'CS2';
    }

    public function supportedGames(): array
    {
        return ['CS2'];
    }

    public function requiredSocial(array $config = []): ?string
    {
        return 'Steam';
    }

    public function deliverFields(): array
    {
        return [
            'models' => [
                'type' => 'textarea',
                'label' => __('givecore.fields.weapon_models'),
                'required' => true,
                'placeholder' => __('givecore.fields.weapon_models_placeholder'),
                'help' => __('givecore.fields.weapon_models_help'),
            ],
        ];
    }

    public function checkFields(): array
    {
        return [
            'server_id' => [
                'type' => 'select',
                'label' => __('givecore.fields.server'),
                'required' => true,
                'options' => $this->getServerOptions(static::MOD_KEY),
            ],
            'models' => [
                'type' => 'textarea',
                'label' => __('givecore.fields.weapon_models'),
                'required' => false,
                'placeholder' => __('givecore.fields.weapon_models_any_placeholder'),
                'help' => __('givecore.fields.weapon_models_help'),
            ],
        ];
    }

    public function check(User $user, array $params = []): bool
    {
        $serverId = $params['server_id'] ?? null;
        if (!$serverId) {
            return false;
        }

        $server = $this->getServerById((int) $serverId);
        if (!$server) {
            return false;
        }

        $steamId = $this->getUserSteamId($user);
        if (!$steamId) {
            return false;
        }

        $dbConnection = $server->getDbConnection(static::MOD_KEY);
        if (!$dbConnection) {
            return false;
        }

        $sid = $this->getSid($dbConnection, $server);
        $db = dbal()->database($dbConnection->dbname);
        $table = $this->getPrefix($dbConnection->dbname, '') . 'cw_access';
        $steamId64 = (string) steam()->steamid($steamId)->ConvertToUInt64();
        $models = $this->parseModels((string) ( $params['models'] ?? '' ), false);

        $query = $db->select()->from($table)->where('steamid64', $steamId64)->andWhere('sid', $sid);

        $records = $query->fetchAll();
        $now = time();
        $modelFilter = !empty($models) ? array_fill_keys($models, true) : [];

        foreach ($records as $record) {
            if (!empty($modelFilter) && !isset($modelFilter[(string) ( $record['model'] ?? '' )])) {
                continue;
            }

            $expires = (int) ( $record['expires'] ?? 0 );
            if ($expires === 0 || $expires >= $now) {
                return true;
            }
        }

        return false;
    }

    public function deliver(
        User $user,
        Server $server,
        array $additional = [],
        ?int $timeId = null,
        bool $ignoreErrors = false,
    ): bool {
        $simulate = false;
        if (array_key_exists('__simulate', $additional)) {
            $simulate = (bool) $additional['__simulate'];
            unset($additional['__simulate']);
        }

        $steamId = $this->getUserSteamId($user);
        if (!$steamId) {
            throw new UserSocialException('Steam');
        }

        $models = $this->parseModels((string) ( $additional['models'] ?? '' ));
        if (empty($models)) {
            throw BadConfigurationException::missingParam('models', $server->name);
        }

        $dbConnection = $server->getDbConnection(static::MOD_KEY);
        if (!$dbConnection) {
            throw BadConfigurationException::noDbConnection(static::MOD_KEY, $server->name);
        }

        $sid = $this->getSid($dbConnection, $server);
        $db = dbal()->database($dbConnection->dbname);
        $table = $this->getPrefix($dbConnection->dbname, '') . 'cw_access';
        $steamId64 = (string) steam()->steamid($steamId)->ConvertToUInt64();
        $time = (int) ( $timeId ?? $additional['time'] ?? 0 );

        $activeModels = [];
        $recordsByModel = [];

        foreach ($models as $model) {
            $records = $this->findAccessRows($db, $table, $steamId64, $sid, $model);
            $recordsByModel[$model] = $records;

            if ($this->hasActiveRecord($records)) {
                $activeModels[] = $model;
            }
        }

        if ($simulate) {
            if (!empty($activeModels) && !$ignoreErrors) {
                $this->confirm(
                    __('givecore.drivers.custom_weapons.already_has_models', [
                        'models' => implode(', ', $activeModels),
                    ]),
                    null,
                    [
                        'type' => 'custom_weapons_extend',
                        'models' => $activeModels,
                        'server' => $server->name,
                    ],
                );
            }

            return false;
        }

        foreach ($models as $model) {
            $this->upsertAccess($db, $table, $recordsByModel[$model] ?? [], $steamId64, $sid, $model, $time);
        }

        return true;
    }

    private function parseModels(string $models, bool $strict = true): array
    {
        $result = [];
        foreach (preg_split('/[\r\n,;]+/', $models) ?: [] as $model) {
            $model = trim($model);
            if ($model === '') {
                continue;
            }

            if (strlen($model) > 64) {
                if ($strict) {
                    throw BadConfigurationException::missingParam('models', '');
                }

                continue;
            }

            $result[$model] = $model;
        }

        return array_values($result);
    }

    private function getSid($dbConnection, Server $server): int
    {
        $params = Json::decode($dbConnection->additional, true);
        if (!isset($params['sid']) || $params['sid'] === '' || $params['sid'] === null) {
            throw BadConfigurationException::noDbConnection(static::MOD_KEY . ' SID', $server->name);
        }

        return (int) $params['sid'];
    }

    private function findAccessRows($db, string $table, string $steamId64, int $sid, string $model): array
    {
        return $db
            ->select()
            ->from($table)
            ->where('steamid64', $steamId64)
            ->andWhere('sid', $sid)
            ->andWhere('model', $model)
            ->fetchAll();
    }

    private function hasActiveRecord(array $records): bool
    {
        $now = time();
        foreach ($records as $record) {
            $expires = (int) ( $record['expires'] ?? 0 );
            if ($expires === 0 || $expires >= $now) {
                return true;
            }
        }

        return false;
    }

    private function upsertAccess(
        $db,
        string $table,
        array $records,
        string $steamId64,
        int $sid,
        string $model,
        int $time,
    ): void {
        $expires = $this->calculateExpires($records, $time);

        if (!empty($records)) {
            $db
                ->table($table)
                ->update(['expires' => $expires])
                ->where('steamid64', $steamId64)
                ->andWhere('sid', $sid)
                ->andWhere('model', $model)
                ->run();

            return;
        }

        $db
            ->insert($table)
            ->values([
                'steamid64' => $steamId64,
                'model' => $model,
                'sid' => $sid,
                'expires' => $expires,
            ])
            ->run();
    }

    private function calculateExpires(array $records, int $time): int
    {
        if ($time <= 0) {
            return 0;
        }

        $base = time();
        foreach ($records as $record) {
            $expires = (int) ( $record['expires'] ?? 0 );
            if ($expires === 0) {
                return 0;
            }

            $base = max($base, $expires);
        }

        return $base + $time;
    }
}
