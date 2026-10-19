<?php

use MultiplayerGratis\Exception\ConflictException;
use MultiplayerGratis\Exception\NotFoundException;
use MultiplayerGratis\PowerSignal;
use MultiplayerGratis\ServerType;
use WHMCS\Module\Server\MultiplayerGratis\Module;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/vendor/autoload.php';

function multiplayergratis_MetaData(): array
{
    return [
        'DisplayName' => 'multiplayer.gratis',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultSSLPort' => '443',
    ];
}

function multiplayergratis_ConfigOptions(): array
{
    return [
        'Server-Art' => [
            'Type' => 'text',
            'Size' => '25',
            'Default' => 'gameserver',
            'Loader' => 'multiplayergratis_LoadTypes',
            'SimpleMode' => true,
            'Description' => 'gameserver, runtime oder software (webspace ist aktuell nicht bestellbar)',
        ],
        'Paket' => [
            'Type' => 'text',
            'Size' => '25',
            'Loader' => 'multiplayergratis_LoadPackages',
            'SimpleMode' => true,
            'Description' => 'Paket, das bei der Bestellung gebucht wird',
        ],
        'Spiel / Umgebung' => [
            'Type' => 'text',
            'Size' => '25',
            'Loader' => 'multiplayergratis_LoadEggs',
            'SimpleMode' => true,
            'Description' => 'Egg-ID des Spiels bzw. der Umgebung',
        ],
        'Rabattcode' => [
            'Type' => 'text',
            'Size' => '25',
            'SimpleMode' => true,
            'Description' => 'Optional, wird bei jeder Bestellung angerechnet',
        ],
        'API-Schlüssel' => [
            'Type' => 'password',
            'Size' => '50',
            'SimpleMode' => true,
            'Description' => 'Optional: eigener API-Schlüssel (mg_…) für dieses Produkt, sonst wird der Schlüssel vom Server genutzt',
        ],
        'Bei Sperrung' => [
            'Type' => 'dropdown',
            'Options' => 'Server stoppen,Sofort beenden,Nichts tun',
            'Default' => 'Server stoppen',
            'SimpleMode' => true,
            'Description' => 'Was mit dem Server passiert, wenn der Dienst gesperrt wird',
        ],
        'Bei Entsperrung starten' => [
            'Type' => 'yesno',
            'Default' => 'on',
            'SimpleMode' => true,
            'Description' => 'Server nach dem Entsperren automatisch starten',
        ],
        'Bei Kündigung' => [
            'Type' => 'dropdown',
            'Options' => 'Server löschen,Server stoppen,Sofort beenden,Nichts tun',
            'Default' => 'Server löschen',
            'SimpleMode' => true,
            'Description' => 'Server löschen ist endgültig, es gibt keine Coins zurück',
        ],
    ];
}

function multiplayergratis_LoadTypes(array $params): array
{
    $types = Module::dropdown(Module::client($params)->getTypes(), static function (array $type, string $value): string {
        $label = (string) ($type['name'] ?? $type['label'] ?? $value);

        return isset($type['orderable']) && !$type['orderable'] ? $label . ' (nicht bestellbar)' : $label;
    });

    $options = [];
    foreach ($types as $value => $label) {
        $options[ServerType::tryFromName((string) $value)?->value ?? (string) $value] = $label;
    }

    return $options;
}

function multiplayergratis_LoadPackages(array $params): array
{
    return Module::dropdown(Module::client($params)->getPackages(), static function (array $package, string $value): string {
        $label = (string) ($package['name'] ?? $value);
        $details = [];
        if (isset($package['price']) && is_scalar($package['price'])) {
            $details[] = $package['price'] . ' Coins';
        }
        if (isset($package['days']) && is_scalar($package['days'])) {
            $details[] = $package['days'] . ' Tage';
        }

        return $details === [] ? $label : $label . ' (' . implode(', ', $details) . ')';
    });
}

function multiplayergratis_LoadEggs(array $params): array
{
    return Module::dropdown(Module::client($params)->getEggs(), static function (array $egg, string $value): string {
        $label = (string) ($egg['name'] ?? $value);
        $type = $egg['type'] ?? null;

        return is_string($type) && $type !== '' ? $label . ' – ' . $type : $label;
    });
}

function multiplayergratis_TestConnection(array $params): array
{
    try {
        Module::call('TestConnection', $params, static fn () => Module::client($params)->getAccount());
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }

    return ['success' => true, 'error' => ''];
}

function multiplayergratis_CreateAccount(array $params): string
{
    return Module::run('CreateAccount', $params, static function () use ($params): array {
        $client = Module::client($params);

        $existing = Module::findServerId($params);
        if ($existing !== null) {
            return $client->getServer($existing);
        }

        foreach (['type' => 'Server-Art', 'package' => 'Paket', 'egg' => 'Spiel / Umgebung'] as $option => $label) {
            if (Module::option($params, $option) === '') {
                throw new \RuntimeException('Im Produkt ist keine Option "' . $label . '" gesetzt.');
            }
        }

        $code = Module::option($params, 'code');
        $server = Module::unwrap($client->orderServer(
            Module::serverName($params),
            Module::option($params, 'type'),
            Module::option($params, 'package'),
            Module::option($params, 'egg'),
            $code !== '' ? $code : null,
            Module::reference($params),
        ));

        if (!isset($server['id'])) {
            throw new \RuntimeException('Die API hat keine Server-ID zurückgegeben.');
        }

        Module::storeServerId($params, (string) $server['id']);

        return $server;
    });
}

function multiplayergratis_Renew(array $params): string
{
    return Module::run('Renew', $params, static fn () => Module::client($params)->renewServer(Module::serverId($params)));
}

function multiplayergratis_ChangePackage(array $params): string
{
    return Module::run('ChangePackage', $params, static function () use ($params): ?array {
        $client = Module::client($params);
        $id = Module::serverId($params);
        $package = Module::option($params, 'package');
        $server = Module::unwrap($client->getServer($id));

        if ($package === '' || (string) ($server['package'] ?? '') === $package) {
            return null;
        }

        return $client->upgradeServer($id, $package);
    });
}

function multiplayergratis_SuspendAccount(array $params): string
{
    $signal = Module::suspendSignal($params);

    return $signal === null ? 'success' : Module::power($params, $signal);
}

function multiplayergratis_UnsuspendAccount(array $params): string
{
    return Module::startOnUnsuspend($params) ? Module::power($params, PowerSignal::Start) : 'success';
}

function multiplayergratis_TerminateAccount(array $params): string
{
    $action = Module::terminateAction($params);
    if ($action === null) {
        return 'success';
    }

    try {
        Module::call('TerminateAccount', $params, static function () use ($params, $action): void {
            if ($action === 'delete') {
                Module::deleteServer($params);

                return;
            }

            Module::client($params)->sendPowerSignal(Module::serverId($params), $action);
        });
    } catch (NotFoundException | ConflictException) {
        return 'success';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }

    return 'success';
}

function multiplayergratis_AdminCustomButtonArray(): array
{
    return [
        'Starten' => 'adminStart',
        'Stoppen' => 'adminStop',
        'Neu starten' => 'adminRestart',
        'Sofort beenden' => 'adminKill',
        'Verlängern' => 'adminRenew',
        'Backup erstellen' => 'adminCreateBackup',
    ];
}

function multiplayergratis_ClientAreaCustomButtonArray(): array
{
    return [
        'Starten' => 'start',
        'Stoppen' => 'stop',
        'Neu starten' => 'restart',
        'Sofort beenden' => 'kill',
        'Backup erstellen' => 'createBackup',
    ];
}

function multiplayergratis_ClientAreaAllowedFunctions(): array
{
    return ['restoreBackup', 'downloadBackup', 'deleteBackup'];
}

function multiplayergratis_adminStart(array $params): string
{
    return Module::power($params, PowerSignal::Start);
}

function multiplayergratis_adminStop(array $params): string
{
    return Module::power($params, PowerSignal::Stop);
}

function multiplayergratis_adminRestart(array $params): string
{
    return Module::power($params, PowerSignal::Restart);
}

function multiplayergratis_adminKill(array $params): string
{
    return Module::power($params, PowerSignal::Kill);
}

function multiplayergratis_adminRenew(array $params): string
{
    return multiplayergratis_Renew($params);
}

function multiplayergratis_adminCreateBackup(array $params): string
{
    return Module::run('AdminCreateBackup', $params, static fn () => Module::client($params)->createBackup(Module::serverId($params)));
}

function multiplayergratis_clientGuard(array $params): ?string
{
    return ($params['status'] ?? '') === 'Active' ? null : 'Dieser Dienst ist nicht aktiv.';
}

function multiplayergratis_clientPower(array $params, PowerSignal $signal): string
{
    return multiplayergratis_clientGuard($params) ?? Module::power($params, $signal);
}

function multiplayergratis_start(array $params): string
{
    return multiplayergratis_clientPower($params, PowerSignal::Start);
}

function multiplayergratis_stop(array $params): string
{
    return multiplayergratis_clientPower($params, PowerSignal::Stop);
}

function multiplayergratis_restart(array $params): string
{
    return multiplayergratis_clientPower($params, PowerSignal::Restart);
}

function multiplayergratis_kill(array $params): string
{
    return multiplayergratis_clientPower($params, PowerSignal::Kill);
}

function multiplayergratis_createBackup(array $params): string
{
    $name = trim((string) ($_REQUEST['backup_name'] ?? ''));

    return multiplayergratis_clientGuard($params)
        ?? Module::run('CreateBackup', $params, static fn () => Module::client($params)->createBackup(
            Module::serverId($params),
            $name !== '' ? mb_substr($name, 0, 64) : null,
        ));
}

function multiplayergratis_restoreBackup(array $params): string
{
    return multiplayergratis_clientGuard($params)
        ?? Module::run('RestoreBackup', $params, static fn () => Module::client($params)->restoreBackup(Module::serverId($params), Module::requestBackupId()));
}

function multiplayergratis_deleteBackup(array $params): string
{
    return multiplayergratis_clientGuard($params)
        ?? Module::run('DeleteBackup', $params, static fn () => Module::client($params)->deleteBackup(Module::serverId($params), Module::requestBackupId()));
}

function multiplayergratis_downloadBackup(array $params): string
{
    $guard = multiplayergratis_clientGuard($params);
    if ($guard !== null) {
        return $guard;
    }

    try {
        $response = Module::call('DownloadBackup', $params, static fn () => Module::client($params)->getBackupDownload(Module::serverId($params), Module::requestBackupId()));
    } catch (\Throwable $e) {
        return $e->getMessage();
    }

    $url = Module::findUrl($response);
    if ($url === null) {
        return 'Die API hat keinen Download-Link geliefert.';
    }

    Module::redirect($url);
}

function multiplayergratis_AdminServicesTabFields(array $params): array
{
    try {
        $client = Module::client($params);
        $id = Module::findServerId($params);
        if ($id === null) {
            return ['multiplayer.gratis' => 'Noch kein Server angelegt (Referenz <code>' . htmlspecialchars(Module::reference($params)) . '</code>).'];
        }

        $server = Module::unwrap(Module::call('AdminServer', $params, static fn () => $client->getServer($id)));
        $resources = Module::unwrap(Module::call('AdminResources', $params, static fn () => $client->getServerResources($id)));
        $backups = Module::backups(Module::call('AdminBackups', $params, static fn () => $client->getBackups($id)));
    } catch (\Throwable $e) {
        return ['multiplayer.gratis' => '<span class="label label-danger">' . htmlspecialchars($e->getMessage()) . '</span>'];
    }

    $backupRows = [];
    foreach ($backups as $backup) {
        $backupRows[$backup['name']] = trim($backup['created'] . ' ' . $backup['size']);
    }

    return [
        'Gameserver' => multiplayergratis_adminTable(Module::rows($server)),
        'Auslastung' => multiplayergratis_adminTable(Module::rows($resources)),
        'Backups' => multiplayergratis_adminTable($backupRows),
    ];
}

function multiplayergratis_adminTable(array $rows): string
{
    if ($rows === []) {
        return '–';
    }

    $html = '<table class="table table-condensed" style="margin:0;max-width:600px">';
    foreach ($rows as $label => $value) {
        $html .= '<tr><th style="width:40%">' . htmlspecialchars((string) $label) . '</th><td>' . htmlspecialchars((string) $value) . '</td></tr>';
    }

    return $html . '</table>';
}

function multiplayergratis_ClientArea(array $params): array
{
    $vars = [
        'serviceId' => (int) $params['serviceid'],
        'active' => ($params['status'] ?? '') === 'Active',
        'server' => [],
        'resources' => [],
        'backups' => [],
        'backupLimit' => null,
        'panelUrl' => null,
        'serverStatus' => null,
        'mgError' => null,
    ];

    try {
        $client = Module::client($params);
        $id = Module::serverId($params);
        $server = Module::unwrap(Module::call('ClientServer', $params, static fn () => $client->getServer($id)));
        $resources = Module::unwrap(Module::call('ClientResources', $params, static fn () => $client->getServerResources($id)));
        $backups = Module::call('ClientBackups', $params, static fn () => $client->getBackups($id));

        $vars['server'] = Module::rows($server);
        $vars['resources'] = Module::rows($resources);
        $vars['backups'] = Module::backups($backups);
        $vars['backupLimit'] = $server['limits']['backups'] ?? null;
        $vars['panelUrl'] = Module::findUrl($server, 'panel');
        $vars['serverStatus'] = Module::status($server);
    } catch (\Throwable $e) {
        $vars['mgError'] = 'Die Serverdaten konnten gerade nicht geladen werden. Bitte versuche es später erneut.';
    }

    return [
        'tabOverviewModuleOutputTemplate' => 'templates/clientarea.tpl',
        'templateVariables' => $vars,
    ];
}
