<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use PrestaShop\Module\GitHubUpgradeManager\UpgradeManager;

class GhUpgradeManager extends Module
{
    public function __construct()
    {
        $this->name = 'ghupgrademanager';
        $this->displayName = $this->trans('GitHub : upgrade manager', [], 'Modules.Ghupgrademanager.Admin');
        $this->description = $this->trans('Manage your module upgrade from GitHub.', [], 'Modules.Ghupgrademanager.Admin');
        $this->author = 'PrestaEdit';
        $this->version = '1.0.0';
        $this->tab = 'market_place';
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];
        parent::__construct();
    }

    public function install(): bool
    {
        return parent::install()
            && $this->registerHook('actionListModules')
            && $this->registerHook('actionBeforeUpgradeModule')
        ;
    }

    /**
     * @return array<array<string, string>>
     */
    public function hookActionListModules(): array
    {
        return $this->getUpgradeManager()->getModulesList();
    }

    /**
     * @param string[] $params
     *
     * @return void
     */
    public function hookActionBeforeUpgradeModule(array $params): void
    {
        if (!isset($params['moduleName']) || !empty($params['source'])) {
            return;
        }

        $this->getUpgradeManager()->downloadModule($params['moduleName']);
    }

    private function getUpgradeManager(): UpgradeManager
    {
        /** @var UpgradeManager $upgradeManager */
        $upgradeManager = $this->get('ghupgrademanager.upgrade_manager');

        return $upgradeManager;
    }
}
