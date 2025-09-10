<?php
namespace CityPaintsERP;

use CityPaintsERP\Helpers\Logger;
use CityPaintsERP\Admin\SettingsPage;

class Core
{
    private Logger $logger;

    public function __construct()
    {
        // Base plugin dir can be derived from __DIR__ relative to /src
        $pluginDir = dirname(__DIR__);
        $this->logger = new Logger($pluginDir);
    }

    public function init(): void
    {
        if (is_admin()) {
            (new SettingsPage($this->logger))->init();
        }

        // Later: add SyncManager, ApiClient etc. and pass $this->logger
        $this->logger->log('Core initialized');
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }
}
