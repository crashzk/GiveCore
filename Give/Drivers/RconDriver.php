<?php

namespace Flute\Modules\GiveCore\Give\Drivers;

use Exception;
use Flute\Core\Database\Entities\Server;
use Flute\Core\Database\Entities\User;
use Flute\Core\Rcon\RconService;
use Flute\Modules\GiveCore\Exceptions\BadConfigurationException;
use Flute\Modules\GiveCore\Exceptions\GiveDriverException;
use Flute\Modules\GiveCore\Exceptions\UserSocialException;
use Flute\Modules\GiveCore\Support\AbstractDriver;

class RconDriver extends AbstractDriver
{
    protected $time;

    // ── Metadata ───────────────────────────────────────────────────

    public function alias(): string
    {
        return 'rcon';
    }

    public function name(): string
    {
        return __('givecore.drivers.rcon.name');
    }

    public function description(): string
    {
        return __('givecore.drivers.rcon.description');
    }

    public function icon(): string
    {
        return 'ph.bold.terminal-bold';
    }

    public function category(): string
    {
        return 'rcon';
    }

    public function supportedGames(): array
    {
        return ['CS2', 'CS:GO', 'CS 1.6', 'Minecraft', 'TF2', 'Garry\'s Mod'];
    }

    public function requiredSocial(array $config = []): ?string
    {
        $cmd = (string) ( $config['command'] ?? '' );
        if ($cmd === '') {
            return null;
        }

        if (preg_match('/\{steam32\}|\{steam64\}|\{accountId\}/i', $cmd)) {
            $steamInput = $config['steam_input'] ?? 'auto';
            if ($steamInput === 'manual') {
                return null;
            }

            return 'Steam';
        }

        if (preg_match('/\{nick\}|\{username\}|\{minecraft\}/i', $cmd)) {
            return 'Minecraft';
        }

        return null;
    }

    public function deliverFields(): array
    {
        return [
            'command' => [
                'type' => 'textarea',
                'label' => __('givecore.fields.command'),
                'required' => true,
                'placeholder' => __('givecore.fields.command_placeholder'),
                'help' => __('givecore.fields.command_help'),
            ],
            'steam_input' => [
                'type' => 'select',
                'label' => __('givecore.fields.rcon_steam_input'),
                'required' => false,
                'default' => 'auto',
                'options' => [
                    'auto' => __('givecore.fields.rcon_steam_input_auto'),
                    'manual' => __('givecore.fields.rcon_steam_input_manual'),
                ],
                'help' => __('givecore.fields.rcon_steam_input_help'),
            ],
        ];
    }

    public function purchaseFields(array $config = []): array
    {
        $steamInput = $config['steam_input'] ?? 'auto';
        $cmd = (string) ( $config['command'] ?? '' );
        $usesSteam = (bool) preg_match('/\{steam32\}|\{steam64\}|\{accountId\}/i', $cmd);

        if ($steamInput !== 'manual' || !$usesSteam) {
            return [];
        }

        return [
            'rcon_steamid' => [
                'type' => 'text',
                'label' => __('givecore.fields.amx_steamid'),
                'required' => true,
                'placeholder' => __('givecore.fields.amx_steamid_placeholder'),
            ],
        ];
    }

    // ── Delivery ───────────────────────────────────────────────────

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

        $this->validateServerAndCommand($server, $additional);

        $commandLines = preg_split('/\r\n|\r|\n/', $additional['command']);
        $commands = [];

        foreach ($commandLines as $line) {
            if (str_contains($line, ';')) {
                $lineCommands = explode(';', $line);
                foreach ($lineCommands as $cmd) {
                    if (trim($cmd) !== '') {
                        $commands[] = trim($cmd);
                    }
                }
            } else {
                if (trim($line) !== '') {
                    $commands[] = trim($line);
                }
            }
        }

        $steam = $this->getSteamIdForCommand($user, $additional['command'], $additional);

        $this->time = (int) ( $timeId ?? 0 );

        if ($simulate) {
            return false;
        }

        try {
            $rconService = app(RconService::class);
            $this->executeCommands($rconService, $server, $commands, $steam, $user, $additional);

            return true;
        } catch (Exception $e) {
            logs('modules')->error('RCON delivery failed', ['server' => $server->name, 'error' => $e->getMessage()]);
            throw new GiveDriverException(__('givecore.errors.rcon_failed'));
        }
    }

    // ── Private helpers ────────────────────────────────────────────

    private function validateServerAndCommand(Server $server, array $additional): void
    {
        if (!$server->rcon) {
            throw BadConfigurationException::noRcon($server->name);
        }

        if (!isset($additional['command'])) {
            throw BadConfigurationException::noCommand();
        }
    }

    private function getSteamIdForCommand(User $user, string $command, array $additional = []): ?string
    {
        if (!preg_match('/{steam32}|{steam64}|{accountId}/i', $command)) {
            return null;
        }

        $steamInput = $additional['steam_input'] ?? 'auto';

        if ($steamInput === 'manual') {
            $rawSteamId = trim($additional['rcon_steamid'] ?? '');

            if ($rawSteamId === '') {
                throw new \RuntimeException(__('givecore.fields.amx_steamid_required'));
            }

            try {
                $steamClass = steam()->steamid($rawSteamId);

                return $steamClass->ConvertToUInt64();
            } catch (\Throwable) {
                throw new \RuntimeException(__('givecore.fields.amx_steamid_invalid'));
            }
        }

        $steam = $user->getSocialNetwork('Steam') ?? $user->getSocialNetwork('HttpsSteam');

        if (!$steam) {
            throw new UserSocialException('Steam');
        }

        return $steam->value;
    }

    private function executeCommands(
        RconService $rconService,
        Server $server,
        array $commands,
        ?string $steam,
        User $user,
        array $additional,
    ): void {
        foreach ($commands as $command) {
            try {
                $rconService->execute($server, $this->replacePlaceholders($command, $steam, $user, $additional));
            } catch (Exception $e) {
                if (is_debug()) {
                    throw $e;
                }

                logs()->error($e);
            }
        }
    }

    private function replacePlaceholders(string $command, ?string $steam, User $user, array $additional): string
    {
        $steamDetails = $this->getSteamDetails($steam);

        if (!empty($this->time) && $this->time > 0) {
            $totalSeconds = (int) $this->time;

            $days = intdiv($totalSeconds, 86400);
            $hours = intdiv($totalSeconds % 86400, 3600);
            $minutes = intdiv($totalSeconds % 3600, 60);
            $seconds = $totalSeconds;
            $secondsRemainder = $totalSeconds % 60;

            $unix = time() + $totalSeconds;
        } else {
            $days = $hours = $minutes = $seconds = $secondsRemainder = $unix = 0;
        }

        $sanitize = static fn($v): string => str_replace([';', "\n", "\r", '"', "'"], '', (string) $v);

        $command = str_replace(
            [
                '{steam32}',
                '{steam64}',
                '{accountId}',
                '{login}',
                '{name}',
                '{email}',
                '{uri}',
                '{days}',
                '{hours}',
                '{minutes}',
                '{seconds}',
                '{duration}',
                '{totalSeconds}',
                '{secondsRemainder}',
                '{unix}',
                '{nickname}',
            ],
            [
                $steamDetails['steam32'],
                $steamDetails['steam64'],
                $steamDetails['accountId'],
                $sanitize($user->login),
                $sanitize($user->name),
                $sanitize($user->email),
                $sanitize($user->uri),
                $days,
                $hours,
                $minutes,
                $seconds,
                $seconds,
                $seconds,
                $secondsRemainder,
                $unix,
                $sanitize($additional['mc_nick'] ?? ''),
            ],
            $command,
        );

        foreach ($additional as $key => $value) {
            if (str_starts_with($key, 'cf_')) {
                $command = str_replace('{' . $key . '}', $sanitize((string) $value), $command);
            }
        }

        return $command;
    }

    private function getSteamDetails(?string $steam): array
    {
        if ($steam) {
            $steamClass = steam()->steamid($steam);

            return [
                'steam32' => $steamClass->RenderSteam2(),
                'steam64' => $steamClass->ConvertToUInt64(),
                'accountId' => $steamClass->GetAccountID(),
            ];
        }

        return ['steam32' => '', 'steam64' => '', 'accountId' => ''];
    }
}
