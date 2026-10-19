{if $mgError}
    <div class="alert alert-warning">{$mgError|escape}</div>
{else}
    {assign var=actionUrl value="clientarea.php?action=productdetails&id={$serviceId}&modop=custom"}

    <div class="card panel panel-default mb-3">
        <div class="card-header panel-heading d-flex justify-content-between align-items-center">
            <strong>Server</strong>
            {if $serverStatus}
                <span class="label badge {if $serverStatus|lower == 'running' || $serverStatus|lower == 'online'}label-success badge-success{elseif $serverStatus|lower == 'expired'}label-danger badge-danger{elseif $serverStatus|lower == 'offline' || $serverStatus|lower == 'stopped'}label-default badge-secondary{else}label-info badge-info{/if}">{$serverStatus|escape}</span>
            {/if}
        </div>
        <div class="card-body panel-body">
            <table class="table table-sm table-condensed mb-0">
                {foreach $server as $label => $value}
                    <tr>
                        <th style="width:40%">{$label|escape}</th>
                        <td>{$value|escape}</td>
                    </tr>
                {/foreach}
            </table>
        </div>
    </div>

    {if $active}
        <div class="mb-3">
            <div class="btn-group" role="group">
                <a href="{$actionUrl|escape}&amp;a=start" class="btn btn-success">Starten</a>
                <a href="{$actionUrl|escape}&amp;a=restart" class="btn btn-primary">Neu starten</a>
                <a href="{$actionUrl|escape}&amp;a=stop" class="btn btn-warning">Stoppen</a>
                <a href="{$actionUrl|escape}&amp;a=kill" class="btn btn-danger" onclick="return confirm('Server wirklich sofort beenden? Nicht gespeicherte Daten gehen verloren.');">Sofort beenden</a>
            </div>
            {if $panelUrl}
                <a href="{$panelUrl|escape}" class="btn btn-default btn-outline-secondary" target="_blank" rel="noopener">Zum Panel</a>
            {/if}
        </div>
    {/if}

    {if $resources}
        <div class="card panel panel-default mb-3">
            <div class="card-header panel-heading"><strong>Auslastung</strong></div>
            <div class="card-body panel-body">
                <table class="table table-sm table-condensed mb-0">
                    {foreach $resources as $label => $value}
                        <tr>
                            <th style="width:40%">{$label|escape}</th>
                            <td>{$value|escape}</td>
                        </tr>
                    {/foreach}
                </table>
            </div>
        </div>
    {/if}

    <div class="card panel panel-default mb-3">
        <div class="card-header panel-heading">
            <strong>Backups</strong>
            {if $backupLimit}<small class="text-muted">({$backups|count} von {$backupLimit|escape})</small>{/if}
        </div>
        <div class="card-body panel-body">
            {if $backups}
                <table class="table table-sm table-condensed">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Erstellt</th>
                            <th>Größe</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $backups as $backup}
                            <tr>
                                <td>{$backup.name|escape}</td>
                                <td>{$backup.created|escape}</td>
                                <td>{$backup.size|escape}</td>
                                <td class="text-right text-end">
                                    {if $active && $backup.completed}
                                        <a href="{$actionUrl|escape}&amp;a=downloadBackup&amp;backup={$backup.uuid|escape:'url'}" class="btn btn-sm btn-default btn-outline-secondary" target="_blank" rel="noopener">Download</a>
                                        <form method="post" action="{$actionUrl|escape}&amp;a=restoreBackup" style="display:inline" onsubmit="return confirm('Backup wiederherstellen? Der aktuelle Stand des Servers wird überschrieben.');">
                                            <input type="hidden" name="token" value="{$token}">
                                            <input type="hidden" name="backup" value="{$backup.uuid|escape}">
                                            <button type="submit" class="btn btn-sm btn-warning">Wiederherstellen</button>
                                        </form>
                                        <form method="post" action="{$actionUrl|escape}&amp;a=deleteBackup" style="display:inline" onsubmit="return confirm('Backup endgültig löschen?');">
                                            <input type="hidden" name="token" value="{$token}">
                                            <input type="hidden" name="backup" value="{$backup.uuid|escape}">
                                            <button type="submit" class="btn btn-sm btn-danger">Löschen</button>
                                        </form>
                                    {/if}
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            {else}
                <p class="text-muted">Noch keine Backups vorhanden.</p>
            {/if}

            {if $active && (!$backupLimit || $backups|count < $backupLimit)}
                <form method="post" action="{$actionUrl|escape}&amp;a=createBackup" class="form-inline d-flex" style="gap:.5rem">
                    <input type="hidden" name="token" value="{$token}">
                    <input type="text" name="backup_name" maxlength="64" class="form-control" placeholder="Name (optional)">
                    <button type="submit" class="btn btn-primary">Backup erstellen</button>
                </form>
            {/if}
        </div>
    </div>
{/if}
