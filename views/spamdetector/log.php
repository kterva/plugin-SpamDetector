<?php
$this->layout = 'panel';
$this->import("
    mc-icon
    mc-modal
");
?>
<div class="panel-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>Spam Log (Revisión)</h1>
    <div class="actions" style="display: flex; gap: 10px; align-items: center;">
        <button class="btn btn-danger" onclick="purgeSpam()">
            <mc-icon name="trash"></mc-icon> Purgar Spam Antiguo (+30 días)
        </button>
    </div>
</div>

<div class="panel-content">
    <p>A continuación se muestran las entidades atrapadas por el Filtro Nivel 1 (advertencia).<br>
    Las entidades con Nivel -10 (Papelera) se purgarán automáticamente cuando cumplan 30 días.</p>

    <table class="table table-striped" style="width: 100%; border-collapse: collapse; margin-top: 15px;">
        <thead>
            <tr style="background-color: #f2f2f2;">
                <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Tipo</th>
                <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Nombre</th>
                <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Propietario</th>
                <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Spam Status</th>
                <th style="border: 1px solid #ddd; padding: 8px; text-align: left;">Acción</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="5" style="border: 1px solid #ddd; padding: 8px; text-align: center;">No hay entidades marcadas con spam_status=1 en este momento.</td></tr>
            <?php else: ?>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: left;"><?= htmlspecialchars(ucfirst($item['type'])) ?></td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: left;"><?= htmlspecialchars($item['name']) ?></td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: left;"><?= htmlspecialchars($item['owner_name']) ?></td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: left;"><span class="label label-warning" style="background-color: #f0ad4e; color: white; padding: 3px 6px; border-radius: 3px; font-size: 12px;">Advertencia (<?= $item['status'] ?>)</span></td>
                        <td style="border: 1px solid #ddd; padding: 8px; text-align: left;">
                            <a href="<?= $app->createUrl($item['type'], 'single', ['id' => $item['id']]) ?>" class="btn btn-primary btn-sm" target="_blank" style="padding: 5px 10px; background: #007bff; color: white; text-decoration: none; border-radius: 3px;">Revisar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function purgeSpam() {
    if (!confirm('¿Estás seguro de querer purgar todas las entidades enviadas a papelera hace más de 30 días? Esta acción no se puede deshacer.')) return;
    
    MapasCulturais.messages.info('Purgando base de datos...');
    
    $.post(MapasCulturais.createUrl('spamdetector', 'purge'), function(res) {
        if (res.success) {
            MapasCulturais.messages.success('Purgado exitoso: ' + res.output);
            setTimeout(() => window.location.reload(), 3000);
        } else {
            MapasCulturais.messages.error('Error al purgar spam.');
        }
    }).fail(function() {
        MapasCulturais.messages.error('Error de conexión.');
    });
}
</script>

<style>
.actions > * { margin-left: 10px; }
.config-btn-wrapper { display: inline-block; }
.config-btn-wrapper a { display: inline-block; padding: 8px 15px; background: #ffffff; border: 1px solid #ccc; border-radius: 4px; color: #333; text-decoration: none; cursor: pointer; display: flex; align-items: center; gap: 5px; font-weight: 500;}
.config-btn-wrapper a:hover { background: #eeeeee; }
.btn-danger { background-color: #d9534f; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 5px; font-weight: 500;}
.btn-danger:hover { background-color: #c9302c; }
</style>
