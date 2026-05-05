<?php

namespace Flute\Modules\GiveCore\Drivers;

use Flute\Admin\Packages\Server\Contracts\ModDriverInterface;

class CustomWeaponsModDriver implements ModDriverInterface
{
    public function getName(): string
    {
        return 'CustomWeapons';
    }

    public function getSettingsView(): string
    {
        return 'givecore::settings.vip';
    }

    public function prepareData(array $data): array
    {
        $data['sid'] ??= 0;

        return $data;
    }

    public function getValidationRules(): array
    {
        return [
            'sid' => 'required|integer',
        ];
    }
}
