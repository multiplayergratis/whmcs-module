<?php

declare(strict_types=1);

namespace WHMCS\Module\Server\MultiplayerGratis;

use MultiplayerGratis\Client;
use MultiplayerGratis\Exception\NotFoundException;
use MultiplayerGratis\PowerSignal;
use WHMCS\Database\Capsule;

final class Module
{
    public const NAME = 'multiplayergratis';

    public const SERVER_ID_FIELD = 'Server ID';

    public const SERVER_NAME_FIELD = 'Servername';

    private const OPTIONS = [
        'type' => 1,
        'package' => 2,
        'egg' => 3,
        'code' => 4,
        'api_key' => 5,
        'on_suspend' => 6,
        'unsuspend_start' => 7,
        'on_terminate' => 8,
    ];

    private const CONFIGURABLE_OPTIONS = [
        'type' => ['Server-Art', 'type'],
        'package' => ['Paket', 'package'],
        'egg' => ['Spiel', 'egg'],
    ];

    private const LABELS = [
        'id' => 'ID',
        'name' => 'Name',
        'status' => 'Status',
        'state' => 'Status',
        'game' => 'Spiel',
        'egg_id' => 'Egg-ID',
        'package' => 'Paket',
        'reference' => 'Referenz',
        'node' => 'Node',
        'cpu' => 'CPU',
        'ram' => 'RAM',
        'memory' => 'RAM',
        'disk' => 'Speicher',
        'storage' => 'Speicher',
        'backups' => 'Backups',
        'databases' => 'Datenbanken',
        'limits' => 'Limits',
        'uptime' => 'Laufzeit',
        'used' => 'Belegt',
        'limit' => 'Limit',
        'total' => 'Gesamt',
        'expires_at' => 'Läuft ab',
        'created_at' => 'Erstellt',
        'updated_at' => 'Aktualisiert',
        'coins' => 'Coins',
    ];

    public static function client(array $params): Client
    {
        $apiKey = self::apiKey($params);
        if ($apiKey === null) {
            throw new \RuntimeException('Es ist kein API-Schlüssel hinterlegt (Produkt → Module Settings oder Server → Password).');
        }

        return new Client($apiKey);
    }

    public static function apiKey(array $params): ?string
    {
        foreach (['configoption' . self::OPTIONS['api_key'], 'serverpassword', 'serveraccesshash'] as $key) {
            $value = trim((string) ($params[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public static function option(array $params, string $name): string
    {
        foreach (self::CONFIGURABLE_OPTIONS[$name] ?? [] as $configurable) {
            $value = trim((string) ($params['configoptions'][$configurable] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return trim((string) ($params['configoption' . self::OPTIONS[$name]] ?? ''));
    }

    public static function reference(array $params): string
    {
        return 'whmcs-' . (int) $params['serviceid'];
    }

    public static function serverName(array $params): string
    {
        $name = trim((string) ($params['customfields'][self::SERVER_NAME_FIELD] ?? ''));
        if ($name === '') {
            $name = trim((string) ($params['domain'] ?? ''));
        }

        return $name !== '' ? $name : 'Server ' . (int) $params['serviceid'];
    }

    public static function findServerId(array $params): ?string
    {
        $id = trim((string) ($params['customfields'][self::SERVER_ID_FIELD] ?? ''));
        if ($id !== '') {
            return $id;
        }

        try {
            $result = self::unwrap(self::call('FindServer', $params, static fn () => self::client($params)->getServersByReference(self::reference($params))));
        } catch (NotFoundException) {
            return null;
        }

        foreach (isset($result['id']) && is_scalar($result['id']) ? [$result] : self::items($result) as $server) {
            if (is_array($server) && isset($server['id'])) {
                $id = (string) $server['id'];
                self::storeServerId($params, $id);

                return $id;
            }
        }

        return null;
    }

    public static function serverId(array $params): string
    {
        return self::findServerId($params)
            ?? throw new \RuntimeException('Für diesen Dienst wurde noch kein multiplayer.gratis Server angelegt.');
    }

    public static function storeServerId(array $params, string $id): void
    {
        if (!class_exists(Capsule::class) || empty($params['pid'])) {
            return;
        }

        $fieldId = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('relid', (int) $params['pid'])
            ->where(static fn ($query) => $query
                ->where('fieldname', self::SERVER_ID_FIELD)
                ->orWhere('fieldname', 'like', self::SERVER_ID_FIELD . '|%'))
            ->value('id');

        if ($fieldId === null) {
            return;
        }

        Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
            ['fieldid' => $fieldId, 'relid' => (int) $params['serviceid']],
            ['value' => $id],
        );
    }

    public static function call(string $action, array $params, callable $callback): mixed
    {
        $request = ['serviceid' => $params['serviceid'] ?? null, 'serverid' => $params['customfields'][self::SERVER_ID_FIELD] ?? null];

        try {
            $result = $callback();
            self::log($action, $request, $result, $params);

            return $result;
        } catch (\Throwable $e) {
            self::log($action, $request, $e->getMessage(), $params);
            throw $e;
        }
    }

    public static function run(string $action, array $params, callable $callback): string
    {
        try {
            self::call($action, $params, $callback);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        return 'success';
    }

    public static function power(array $params, PowerSignal $signal): string
    {
        return self::run('Power:' . $signal->value, $params, static fn () => self::client($params)->sendPowerSignal(self::serverId($params), $signal));
    }

    public static function deleteServer(array $params): void
    {
        $client = self::client($params);
        $id = self::findServerId($params);
        if ($id === null) {
            return;
        }

        try {
            $server = self::unwrap($client->getServer($id));
            $client->deleteServer($id, (string) ($server['name'] ?? ''));
        } catch (NotFoundException) {
        }
    }

    public static function terminateAction(array $params): ?string
    {
        return match (trim((string) ($params['configoption' . self::OPTIONS['on_terminate']] ?? ''))) {
            'Nichts tun' => null,
            'Server stoppen' => PowerSignal::Stop->value,
            'Sofort beenden' => PowerSignal::Kill->value,
            default => 'delete',
        };
    }

    public static function suspendSignal(array $params): ?PowerSignal
    {
        return match (trim((string) ($params['configoption' . self::OPTIONS['on_suspend']] ?? ''))) {
            'Nichts tun' => null,
            'Sofort beenden' => PowerSignal::Kill,
            default => PowerSignal::Stop,
        };
    }

    public static function startOnUnsuspend(array $params): bool
    {
        return ($params['configoption' . self::OPTIONS['unsuspend_start']] ?? '') === 'on';
    }

    public static function requestBackupId(): string
    {
        $uuid = trim((string) ($_REQUEST['backup'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $uuid)) {
            throw new \RuntimeException('Ungültiges Backup.');
        }

        return $uuid;
    }

    public static function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }

    public static function findUrl(array $data, string $hint = ''): ?string
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && preg_match('#^https://#i', $value) && ($hint === '' || str_contains(strtolower((string) $key), $hint))) {
                return $value;
            }
            if (is_array($value) && ($url = self::findUrl($value, $hint)) !== null) {
                return $url;
            }
        }

        return null;
    }

    public static function unwrap(array $data): array
    {
        return isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
    }

    public static function items(array $data): array
    {
        $data = self::unwrap($data);
        if (array_is_list($data)) {
            return $data;
        }

        foreach ($data as $value) {
            if (is_array($value) && array_is_list($value)) {
                return $value;
            }
        }

        $items = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $items[] = $value + ['id' => $key];
            }
        }

        return $items;
    }

    public static function dropdown(array $data, callable $label): array
    {
        $options = [];
        foreach (self::items($data) as $item) {
            if (is_scalar($item)) {
                $options[(string) $item] = (string) $item;
                continue;
            }
            if (!is_array($item)) {
                continue;
            }

            $value = $item['id'] ?? $item['key'] ?? $item['slug'] ?? $item['type'] ?? $item['name'] ?? null;
            if ($value === null || !is_scalar($value)) {
                continue;
            }

            $options[(string) $value] = $label($item, (string) $value);
        }

        if ($options === []) {
            throw new \RuntimeException('Die API hat keine Einträge geliefert.');
        }

        return $options;
    }

    public static function backups(array $data): array
    {
        $backups = [];
        foreach (self::items($data) as $backup) {
            if (!is_array($backup)) {
                continue;
            }

            $uuid = (string) ($backup['uuid'] ?? $backup['id'] ?? '');
            if ($uuid === '') {
                continue;
            }

            $backups[] = [
                'uuid' => $uuid,
                'name' => (string) ($backup['name'] ?? $uuid),
                'created' => (string) ($backup['created_at'] ?? ''),
                'size' => self::size($backup['size'] ?? $backup['bytes'] ?? null),
                'completed' => !array_key_exists('completed_at', $backup) || $backup['completed_at'] !== null,
            ];
        }

        return $backups;
    }

    public static function rows(array $data, string $prefix = ''): array
    {
        $rows = [];
        foreach ($data as $key => $value) {
            $label = $prefix . self::label((string) $key);
            if (is_array($value)) {
                if (array_is_list($value)) {
                    $value = implode(', ', array_map(self::format(...), array_filter($value, 'is_scalar')));
                } else {
                    $rows += self::rows($value, $label . ' › ');
                    continue;
                }
            }
            $rows[$label] = self::format($value);
        }

        return $rows;
    }

    public static function status(array $server): ?string
    {
        foreach (['status', 'state'] as $key) {
            if (isset($server[$key]) && is_scalar($server[$key])) {
                return (string) $server[$key];
            }
        }

        return null;
    }

    private static function size(mixed $bytes): string
    {
        if (!is_numeric($bytes)) {
            return '';
        }

        $bytes = (float) $bytes;
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1) . ' ' . $unit;
            }
            $bytes /= 1024;
        }

        return '';
    }

    private static function label(string $key): string
    {
        return self::LABELS[strtolower($key)] ?? ucfirst(str_replace(['_', '-'], ' ', $key));
    }

    private static function format(mixed $value): string
    {
        return match (true) {
            $value === null => '–',
            is_bool($value) => $value ? 'Ja' : 'Nein',
            default => (string) $value,
        };
    }

    private static function log(string $action, array $request, mixed $response, array $params): void
    {
        if (!function_exists('logModuleCall')) {
            return;
        }

        $secrets = array_values(array_filter([self::apiKey($params), $params['serverpassword'] ?? null, $params['serveraccesshash'] ?? null]));
        logModuleCall(self::NAME, $action, $request, $response, is_string($response) ? $response : json_encode($response), $secrets);
    }
}
