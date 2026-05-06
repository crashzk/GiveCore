<?php

namespace Flute\Modules\GiveCore\ServiceProviders;

use Flute\Admin\Packages\Server\Factories\ModDriverFactory;
use Flute\Core\Support\ModuleServiceProvider;
use Flute\Modules\GiveCore\Admin\Package\GiveCorePackage;
use Flute\Modules\GiveCore\Check\CheckRegistry;
use Flute\Modules\GiveCore\Check\Drivers\Admin\AmxAdminConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Admin\GMBansAdminConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Admin\IKSAdminConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Admin\SimpleAdminAdminConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Bans\AdminSystemConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Bans\AdvancedBanConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Bans\AmxConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Bans\GMBansConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Bans\IKSConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Bans\LiteBansConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Bans\SimpleAdminConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Bans\SourceBansConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Bans\ZenithBansConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Stats\ArmyRanksUltimateConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Stats\CsStatsConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Stats\CsStatsxSqlConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Stats\FpsStatsConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Stats\HlStatsxCeConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Stats\K4SystemConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Stats\LevelRanksConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Stats\QRanksConditionDriver;
use Flute\Modules\GiveCore\Check\Drivers\Stats\RankMeConditionDriver;
use Flute\Modules\GiveCore\Drivers\CustomWeaponsModDriver;
use Flute\Modules\GiveCore\Drivers\FabiusVIPModDriver;
use Flute\Modules\GiveCore\Drivers\VIPModDriver;
use Flute\Modules\GiveCore\Give\GiveFactory;
use Flute\Modules\GiveCore\Services\AmxExpiredCleanupService;
use Flute\Modules\GiveCore\Services\CustomDriverService;

class GiveCoreServiceProvider extends ModuleServiceProvider
{
    public array $extensions = [];

    public function boot(\DI\Container $container): void
    {
        $this->loadTranslations();

        $this->loadViews('Resources/views', 'givecore');

        // GiveFactory — delivery driver registry
        if (!$container->has(GiveFactory::class)) {
            $container->set(GiveFactory::class, new GiveFactory());
        }

        // CheckRegistry — condition check driver registry
        $checkRegistry = new CheckRegistry();
        $checkRegistry->setGiveFactory($container->get(GiveFactory::class));

        // Ban drivers
        $checkRegistry->register('sourcebans_ban', SourceBansConditionDriver::class);
        $checkRegistry->register('iks_ban', IKSConditionDriver::class);
        $checkRegistry->register('adminsystem_ban', AdminSystemConditionDriver::class);
        $checkRegistry->register('simpleadmin_ban', SimpleAdminConditionDriver::class);
        $checkRegistry->register('gmbans_ban', GMBansConditionDriver::class);
        $checkRegistry->register('amx_ban', AmxConditionDriver::class);
        $checkRegistry->register('litebans_ban', LiteBansConditionDriver::class);
        $checkRegistry->register('advancedban_ban', AdvancedBanConditionDriver::class);
        $checkRegistry->register('zenithbans_ban', ZenithBansConditionDriver::class);

        // Admin drivers (systems not covered by delivery drivers)
        $checkRegistry->register('iks_admin', IKSAdminConditionDriver::class);
        $checkRegistry->register('simpleadmin_admin', SimpleAdminAdminConditionDriver::class);
        $checkRegistry->register('gmbans_admin', GMBansAdminConditionDriver::class);
        $checkRegistry->register('amx_admin', AmxAdminConditionDriver::class);

        // Stats drivers
        $checkRegistry->register('levelranks_stats', LevelRanksConditionDriver::class);
        $checkRegistry->register('rankme_stats', RankMeConditionDriver::class);
        $checkRegistry->register('fpsstats_stats', FpsStatsConditionDriver::class);
        $checkRegistry->register('csstats_stats', CsStatsConditionDriver::class);
        $checkRegistry->register('csstatsxsql_stats', CsStatsxSqlConditionDriver::class);
        $checkRegistry->register('hlstatsce_stats', HlStatsxCeConditionDriver::class);
        $checkRegistry->register('aru_stats', ArmyRanksUltimateConditionDriver::class);
        $checkRegistry->register('qranks_stats', QRanksConditionDriver::class);
        $checkRegistry->register('k4system_stats', K4SystemConditionDriver::class);

        $container->set(CheckRegistry::class, $checkRegistry);

        // ModDriverFactory for server admin panel
        if ($container->has(ModDriverFactory::class)) {
            $modDriverFactory = $container->get(ModDriverFactory::class);
            $modDriverFactory->register('VIP', VIPModDriver::class);
            $modDriverFactory->register('FabiusVIP', FabiusVIPModDriver::class);
            $modDriverFactory->register('CustomWeapons', CustomWeaponsModDriver::class);
        }

        // Register custom SQL drivers (user-defined)
        $customService = new CustomDriverService();
        $container->set(CustomDriverService::class, $customService);
        $customService->registerAll();

        if (config('app.cron_mode')) {
            scheduler()->call(static function (): void {
                ( new AmxExpiredCleanupService() )->cleanup();
            })->daily('04:00');
        }

        if (is_admin_path()) {
            $this->loadPackage(new GiveCorePackage());
        }
    }

    public function register(\DI\Container $container): void
    {
    }
}
