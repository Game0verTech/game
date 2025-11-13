<?php

const ENV_PATH = __DIR__ . '/../config/.env.php';
const INSTALL_LOCK_PATH = __DIR__ . '/../config/.install_lock';

function env_config_exists(): bool
{
    return file_exists(ENV_PATH);
}

function load_config(): array
{
    if (!env_config_exists()) {
        return [];
    }

    $config = include ENV_PATH;
    if (!is_array($config)) {
        throw new RuntimeException('Invalid configuration file.');
    }
    return $config;
}

function save_config(array $config): void
{
    $export = var_export($config, true);
    $php = "<?php\nreturn {$export};\n";
    file_put_contents(ENV_PATH, $php);
    @chmod(ENV_PATH, 0440);
}

function install_is_locked(): bool
{
    return file_exists(INSTALL_LOCK_PATH);
}

function create_install_lock(): void
{
    file_put_contents(INSTALL_LOCK_PATH, date(DATE_ATOM));
    @chmod(INSTALL_LOCK_PATH, 0440);
}

function configured_timezone(array $config): string
{
    static $validTimezones;

    if ($validTimezones === null) {
        $validTimezones = timezone_identifiers_list();
    }

    $timezone = $config['app']['timezone'] ?? 'America/New_York';

    if (!in_array($timezone, $validTimezones, true)) {
        error_log(sprintf('Invalid timezone "%s" in configuration. Falling back to America/New_York.', $timezone));
        $timezone = 'America/New_York';
    }

    return $timezone;
}
