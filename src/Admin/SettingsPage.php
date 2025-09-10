<?php

namespace CityPaintsERP\Admin;

class SettingsPage
{
    private string $option_group = 'citypaints_erp_options';
    private string $option_name  = 'citypaints_erp_settings';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'registerMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function registerMenu(): void
    {
        add_options_page(
            'CityPaints ERP',
            'CityPaints ERP',
            'manage_options',
            'citypaints-erp',
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting($this->option_group, $this->option_name);

        add_settings_section(
            'citypaints_erp_main',
            'API Settings',
            null,
            'citypaints-erp'
        );

        $fields = [
            'base_url' => 'API Base URL',
            'username' => 'Username',
            'password' => 'Password',
            'api_key'  => 'API Key',
        ];

        foreach ($fields as $key => $label) {
            add_settings_field(
                $key,
                $label,
                function () use ($key) {
                    $options = get_option($this->option_name);
                    $value   = $options[$key] ?? '';
                    $type    = $key === 'password' ? 'password' : 'text';
                    printf(
                        '<input type="%s" name="%s[%s]" value="%s" class="regular-text">',
                        esc_attr($type),
                        esc_attr($this->option_name),
                        esc_attr($key),
                        esc_attr($value)
                    );
                },
                'citypaints-erp',
                'citypaints_erp_main'
            );
        }
    }

    public function renderPage(): void
    {
        ?>
        <div class="wrap">
            <h1>CityPaints ERP Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields($this->option_group);
                do_settings_sections('citypaints-erp');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function getSettings(): array
    {
        return get_option($this->option_name, []);
    }
}
