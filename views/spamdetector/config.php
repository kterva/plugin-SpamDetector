<?php
use MapasCulturais\i;

$this->layout = 'panel';
$this->import("
    mc-icon
    mc-tag-list
    spam-add-config
");
?>
<div class="panel-header" style="margin-bottom: 20px;">
    <h1><?= i::__('Control de SPAM', 'spamDetector') ?></h1>
</div>

<div class="panel-content">
    <p><?= i::__('Configure aqui as palavras reservadas que ativarão alertas ou bloqueios completos de perfis e entidades no sistema.', 'spamDetector') ?></p>
    
    <!-- Componente Vue Inyectado como Bloque Directo -->
    <spam-add-config></spam-add-config>

    <div class="panel-header" style="display: flex; justify-content: space-between; align-items: center; margin-top: 40px; margin-bottom: 20px; border-top: 1px solid #eee; padding-top: 20px;">
        <h2><?= i::__('Log de Spam (Revisão)', 'spamDetector') ?></h2>
        <div class="actions">
            <button class="btn btn-danger" onclick="purgeSpam()">
                <mc-icon name="trash"></mc-icon> <?= i::__('Purgar Spam Antigo (+30 dias)', 'spamDetector') ?>
            </button>
        </div>
    </div>

    <div class="panel-content">
        <p><?= i::__('Abaixo estão as entidades capturadas pelo Filtro Nível 1 (aviso).', 'spamDetector') ?><br>
        <?= i::__('Entidades com Nível -10 (Lixeira) serão limpas automaticamente após 30 dias.', 'spamDetector') ?></p>

        <table class="table table-striped" style="width: 100%; border-collapse: collapse; margin-top: 15px;">
            <thead>
                <tr style="background-color: #f2f2f2;">
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: left;"><?= i::__('Tipo', 'spamDetector') ?></th>
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: left;"><?= i::__('Nome', 'spamDetector') ?></th>
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: left;"><?= i::__('Proprietário', 'spamDetector') ?></th>
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: left;"><?= i::__('Status', 'spamDetector') ?></th>
                    <th style="border: 1px solid #ddd; padding: 8px; text-align: left;"><?= i::__('Ação', 'spamDetector') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="5" style="border: 1px solid #ddd; padding: 8px; text-align: center;"><?= i::__('Não há entidades marcadas com spam_status=1 no momento.', 'spamDetector') ?></td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td style="border: 1px solid #ddd; padding: 8px; text-align: left;"><?= htmlspecialchars(ucfirst($item['type'])) ?></td>
                            <td style="border: 1px solid #ddd; padding: 8px; text-align: left;"><?= htmlspecialchars($item['name']) ?></td>
                            <td style="border: 1px solid #ddd; padding: 8px; text-align: left;"><?= htmlspecialchars($item['owner_name']) ?></td>
                            <td style="border: 1px solid #ddd; padding: 8px; text-align: left;"><span class="label label-warning" style="background-color: #f0ad4e; color: white; padding: 3px 6px; border-radius: 3px; font-size: 12px;"><?= i::__('Aviso', 'spamDetector') ?> (<?= $item['status'] ?>)</span></td>
                            <td style="border: 1px solid #ddd; padding: 8px; text-align: left;">
                                <a href="<?= $app->createUrl($item['type'], 'single', ['id' => $item['id']]) ?>" class="btn btn-primary btn-sm" target="_blank" style="padding: 5px 10px; background: #007bff; color: white; text-decoration: none; border-radius: 3px;"><?= i::__('Revisar', 'spamDetector') ?></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script>
function purgeSpam() {
    if (!confirm('<?= i::__('Tem certeza que deseja excluir permanentemente todas as entidades enviadas para a lixeira há mais de 30 dias? Esta ação não pode ser desfeita.', 'spamDetector') ?>')) return;
    
    MapasCulturais.messages.info('<?= i::__('Limpando banco de dados...', 'spamDetector') ?>');
    
    $.post(MapasCulturais.createUrl('spamdetector', 'purge'), function(res) {
        if (res.success) {
            MapasCulturais.messages.success('<?= i::__('Limpeza realizada com sucesso:', 'spamDetector') ?> ' + res.output);
            setTimeout(() => window.location.reload(), 3000);
        } else {
            MapasCulturais.messages.error('<?= i::__('Erro ao limpar spam.', 'spamDetector') ?>');
        }
    }).fail(function() {
        MapasCulturais.messages.error('<?= i::__('Erro de conexão.', 'spamDetector') ?>');
    });
}
</script>

<style>
/* Ajustes menores si el componente lo requiriera al estar sin modal */
#spam-add-config {
    margin-top: 20px;
}
.btn-danger { background-color: #d9534f; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; display: flex; align-items: center; gap: 5px; font-weight: 500;}
.btn-danger:hover { background-color: #c9302c; }
.btn-primary.btn-sm { font-size: 12px; }
</style>
