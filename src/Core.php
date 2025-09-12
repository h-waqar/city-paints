<?php
namespace CityPaintsERP;

use CityPaintsERP\Admin\SettingsPage;

class Core
{

    public function init(): void
    {
        global $CLOGGER;

        if (is_admin()) {
            $CLOGGER->log('Init Settings Page', [1,2,3,4,5]);
            wp_die();
            new SettingsPage();
        }

        // Later: add SyncManager, ApiClient etc. and pass $this->logger

    }

}
