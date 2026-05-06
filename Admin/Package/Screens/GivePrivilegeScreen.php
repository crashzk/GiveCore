<?php

declare(strict_types = 1);

namespace Flute\Modules\GiveCore\Admin\Package\Screens;

use Flute\Admin\Platform\Actions\Button;
use Flute\Admin\Platform\Fields\Input;
use Flute\Admin\Platform\Fields\Select;
use Flute\Admin\Platform\Fields\TextArea;
use Flute\Admin\Platform\Layouts\LayoutFactory;
use Flute\Admin\Platform\Screen;
use Flute\Admin\Platform\Support\Color;
use Flute\Core\Database\Entities\Server;
use Flute\Core\Database\Entities\User;
use Flute\Modules\GiveCore\Contracts\DriverInterface;
use Flute\Modules\GiveCore\Give\GiveFactory;
use Throwable;

class GivePrivilegeScreen extends Screen
{
    public ?string $name = null;

    public ?string $description = null;

    public ?string $permission = 'admin.givecore';

    /** @var array<string, DriverInterface> */
    public array $allDrivers = [];

    public array $filteredServers = [];

    public ?string $selectedDriver = null;

    public ?int $selectedServer = null;

    public ?string $userMode = 'cms';

    public function mount(): void
    {
        $this->name = __('givecore.admin.title');
        $this->description = __('givecore.admin.description');

        /** @var GiveFactory $giveFactory */
        $giveFactory = app(GiveFactory::class);

        foreach ($giveFactory->getAll() as $alias => $class) {
            $this->allDrivers[$alias] = $giveFactory->getDriver($alias);
        }

        $this->selectedDriver = request()->input('selectedDriver', $this->selectedDriver);
        $this->selectedServer = request()->input('selectedServer')
            ? (int) request()->input('selectedServer')
            : $this->selectedServer;
        $this->userMode = request()->input('userMode', 'cms');

        $this->filteredServers = $this->buildServerOptions();
    }

    public function commandBar(): array
    {
        return [
            Button::make(__('givecore.admin.give_privilege'))
                ->icon('ph.bold.gift-bold')
                ->type(Color::PRIMARY)
                ->method('deliver'),
        ];
    }

    public function layout(): array
    {
        $driverOptions = [];
        foreach ($this->allDrivers as $alias => $driver) {
            $name = $driver->name();
            $cat = $driver->category();
            $catLabel = $cat && $cat !== 'other' ? $cat : '';
            $suffix = '';
            if (!$driver->isAvailable()) {
                $suffix = ' (' . ( $driver->unavailableReason() ?: __('givecore.custom_drivers.unavailable') ) . ')';
            }

            $optHtml =
                '<div style="display:flex;align-items:center;gap:6px;width:100%">'
                . '<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'
                . e($name)
                . e($suffix)
                . '</span>';
            if ($catLabel) {
                $optHtml .= '<span style="font-size:10px;opacity:.45;flex-shrink:0">' . e($catLabel) . '</span>';
            }
            $optHtml .= '</div>';

            $driverOptions[$alias] = [
                'text' => $name . ( $catLabel ? " — {$catLabel}" : '' ) . $suffix,
                'optionHtml' => $optHtml,
            ];
        }

        if ($this->userMode === 'steam') {
            $userField = LayoutFactory::field(
                Input::make('give.steam_id')
                    ->required()
                    ->placeholder(__('givecore.admin.steam_id_placeholder'))
                    ->value(request()->input('give.steam_id', '')),
            )
                ->label(__('givecore.admin.steam_id'))
                ->popover(__('givecore.admin.steam_id_help'));
        } elseif ($this->userMode === 'minecraft') {
            $userField = LayoutFactory::field(
                Input::make('give.minecraft_uuid')
                    ->required()
                    ->placeholder(__('givecore.admin.minecraft_uuid_placeholder'))
                    ->value(request()->input('give.minecraft_uuid', '')),
            )
                ->label(__('givecore.admin.minecraft_uuid'))
                ->popover(__('givecore.admin.minecraft_uuid_help'));
        } else {
            $userField = LayoutFactory::field(
                Select::make('give.user_id')
                    ->fromDatabase('users', 'name', 'id', ['name', 'email', 'login'])
                    ->aligned()
                    ->value(request()->input('give.user_id', ''))
                    ->required()
                    ->empty(__('givecore.admin.select_user')),
            )->label(__('givecore.admin.select_user'));
        }

        return [
            LayoutFactory::block([
                LayoutFactory::columns([
                    LayoutFactory::field(
                        Select::make('selectedDriver')
                            ->options($driverOptions)
                            ->aligned()
                            ->value($this->selectedDriver)
                            ->required()
                            ->yoyo()
                            ->empty(__('givecore.admin.select_driver')),
                    )->label(__('givecore.admin.select_driver')),

                    LayoutFactory::field(
                        Select::make('selectedServer')
                            ->options($this->filteredServers)
                            ->aligned()
                            ->value($this->selectedServer)
                            ->required()
                            ->empty(__('givecore.admin.select_server')),
                    )->label(__('givecore.fields.server')),
                ]),

                LayoutFactory::columns([
                    LayoutFactory::field(
                        Select::make('userMode')
                            ->options([
                                'cms' => __('givecore.admin.mode_cms'),
                                'steam' => __('givecore.admin.mode_steam'),
                                'minecraft' => __('givecore.admin.mode_minecraft'),
                            ])
                            ->aligned()
                            ->value($this->userMode)
                            ->yoyo(),
                    )->label(__('givecore.admin.user_mode')),

                    $userField,
                ]),

                LayoutFactory::columns([
                    LayoutFactory::field(
                        Input::make('give.time')
                            ->type('number')
                            ->value(request()->input('give.time', 0))
                            ->min(0),
                    )
                        ->label(__('givecore.admin.time'))
                        ->popover(__('givecore.admin.time_help')),
                ]),
            ])->title(__('givecore.admin.give_privilege')),

            $this->driverParamsLayout(),
        ];
    }

    public function deliver(): void
    {
        $driverAlias = request()->input('selectedDriver', '');
        $serverId = (int) request()->input('selectedServer', 0);
        $mode = request()->input('userMode', 'cms');

        // Yoyo converts dots to underscores: give.user_id → give_user_id
        $r = request();
        $userId = (int) $r->get('give_user_id', 0);
        $steamId = trim($r->get('give_steam_id', ''));
        $minecraftUuid = trim($r->get('give_minecraft_uuid', ''));
        $time = (int) $r->get('give_time', 0);

        if (empty($driverAlias) || !$serverId) {
            toast()
                ->error(__('givecore.admin.deliver_error', ['error' => __('givecore.admin.missing_fields')]))
                ->push();

            return;
        }

        /** @var GiveFactory $giveFactory */
        $giveFactory = app(GiveFactory::class);

        if (!$giveFactory->exists($driverAlias)) {
            toast()->error(__('givecore.admin.deliver_error', ['error' => 'Driver not found']))->push();

            return;
        }

        $server = rep(Server::class)->findByPK($serverId);
        if (!$server) {
            toast()->error(__('givecore.admin.deliver_error', ['error' => 'Server not found']))->push();

            return;
        }

        $user = null;

        if ($mode === 'cms' && $userId) {
            $user = rep(User::class)->findByPK($userId);
        } elseif ($mode === 'steam' && !empty($steamId)) {
            $user = $this->findUserBySocialNetwork($steamId, ['Steam', 'HttpsSteam']);
        } elseif ($mode === 'minecraft' && !empty($minecraftUuid)) {
            $user = $this->findUserBySocialNetwork($minecraftUuid, ['Minecraft', 'minecraft']);
        }

        if (!$user) {
            toast()
                ->error(__('givecore.admin.deliver_error', ['error' => __('givecore.admin.user_not_found')]))
                ->push();

            return;
        }

        // Yoyo converts give.params.group → give_params_group
        $params = [];
        $allInput = $r->attributes->all();
        foreach ($allInput as $k => $v) {
            if (str_starts_with($k, 'give_params_')) {
                $params[substr($k, 12)] = $v;
            }
        }

        try {
            $giveFactory->make($driverAlias, $user, $server, $params, $time > 0 ? $time : null, true);
            toast()->success(__('givecore.admin.deliver_success'))->push();
        } catch (Throwable $e) {
            toast()->error(__('givecore.admin.deliver_error', ['error' => $e->getMessage()]))->push();
        }
    }

    protected function findUserBySocialNetwork(string $value, array $networkKeys): ?User
    {
        foreach (User::query()->fetchAll() as $user) {
            foreach ($networkKeys as $key) {
                $sn = $user->getSocialNetwork($key);
                if ($sn && $sn->value === $value) {
                    return $user;
                }
            }
        }

        return null;
    }

    protected function buildServerOptions(): array
    {
        $options = [];

        if (!$this->selectedDriver) {
            foreach (Server::query()->fetchAll() as $server) {
                $options[$server->id] = [
                    'text' => $server->name,
                    'optionHtml' => view('admin::partials.select.server-option', [
                        'item' => $server,
                        'text' => $server->name,
                        'value' => $server->id,
                    ])->render(),
                    'itemHtml' => view('admin::partials.select.server-item', [
                        'item' => $server,
                        'text' => $server->name,
                        'value' => $server->id,
                    ])->render(),
                ];
            }

            return $options;
        }

        $driver = $this->allDrivers[$this->selectedDriver] ?? null;
        if (!$driver) {
            return $options;
        }

        $dbKey = $driver->dbConnectionKey();

        foreach (Server::query()->fetchAll() as $server) {
            $include = false;
            if ($dbKey === null) {
                if ($this->selectedDriver === 'rcon' && empty($server->rcon)) {
                    continue;
                }
                $include = true;
            } else {
                $include = (bool) $server->getDbConnection($dbKey);
            }

            if ($include) {
                $options[$server->id] = [
                    'text' => $server->name,
                    'optionHtml' => view('admin::partials.select.server-option', [
                        'item' => $server,
                        'text' => $server->name,
                        'value' => $server->id,
                    ])->render(),
                    'itemHtml' => view('admin::partials.select.server-item', [
                        'item' => $server,
                        'text' => $server->name,
                        'value' => $server->id,
                    ])->render(),
                ];
            }
        }

        return $options;
    }

    protected function driverParamsLayout()
    {
        $currentDriver = $this->selectedDriver ?? '';

        if (empty($currentDriver)) {
            return LayoutFactory::blank([]);
        }

        $fields = [];

        foreach ($this->allDrivers as $alias => $driver) {
            if (!$driver->isAvailable()) {
                continue;
            }

            $driverFields = $driver->deliverFields();
            $driverFieldLayouts = [];

            foreach ($driverFields as $fieldName => $fieldConfig) {
                $fieldValue = request()->input("give.params.{$fieldName}", $fieldConfig['default'] ?? '');

                $field = match ($fieldConfig['type'] ?? 'text') {
                    'select' => Select::make("give.params.{$fieldName}")
                        ->options($fieldConfig['options'] ?? [])
                        ->aligned()
                        ->value($fieldValue)
                        ->empty($fieldConfig['placeholder'] ?? ''),
                    'number' => Input::make("give.params.{$fieldName}")
                        ->type('number')
                        ->value($fieldValue)
                        ->min($fieldConfig['min'] ?? null)
                        ->max($fieldConfig['max'] ?? null),
                    'textarea' => TextArea::make("give.params.{$fieldName}")
                        ->value($fieldValue)
                        ->rows($fieldConfig['rows'] ?? 3)
                        ->placeholder($fieldConfig['placeholder'] ?? ''),
                    default => Input::make("give.params.{$fieldName}")
                        ->value($fieldValue)
                        ->placeholder($fieldConfig['placeholder'] ?? ''),
                };

                if ($fieldConfig['required'] ?? false) {
                    $field->required();
                }

                $layout = LayoutFactory::field($field)->label($fieldConfig['label'] ?? $fieldName);

                if (!empty($fieldConfig['help'])) {
                    $layout->popover($fieldConfig['help']);
                }

                $driverFieldLayouts[] = $layout;
            }

            if (!empty($driverFieldLayouts)) {
                // Add driver description as info
                $desc = $driver->description();
                if (!empty($desc)) {
                    array_unshift($driverFieldLayouts, LayoutFactory::view('givecore::admin.driver-info', [
                        'driver' => $driver,
                    ]));
                }

                $fields[$alias] = LayoutFactory::block($driverFieldLayouts)
                    ->title(__('givecore.admin.driver_params', ['driver' => $driver->name()]))
                    ->setVisible($currentDriver === $alias);
            }
        }

        if (empty($fields)) {
            return LayoutFactory::blank([]);
        }

        return LayoutFactory::blank(array_values($fields));
    }
}
