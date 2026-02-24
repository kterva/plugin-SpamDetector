<?php
$this->layout('panel');
?>
<div class="panel-header">
    <h1>Log de Spam em Moderação</h1>
    <p>Lista de entidades marcadas como possível spam (Nível 1: Notificação) aguardando revisão.</p>
</div>

<div class="panel-content">
    <?php if(empty($results)): ?>
        <div class="alert alert-success">Não há entidades em moderação por spam no momento.</div>
    <?php else: ?>
        <table class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>Data de Detecção</th>
                    <th>Tipo</th>
                    <th>ID</th>
                    <th>Nome da Entidade</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($results as $row): ?>
                    <tr>
                        <td><?php echo date('d/m/Y H:i:s', strtotime($row['spam_sent'])); ?></td>
                        <td><?php echo ucfirst($row['type']); ?></td>
                        <td><?php echo $row['id']; ?></td>
                        <td><?php echo htmlentities($row['name']); ?></td>
                        <td>
                            <a href="<?php echo \MapasCulturais\App::i()->createUrl($row['type'], 'single', ['id' => $row['id']]); ?>" class="btn btn-primary btn-sm" target="_blank">Revisar</a>
                            <a href="<?php echo \MapasCulturais\App::i()->createUrl($row['type'], 'edit', ['id' => $row['id']]); ?>" class="btn btn-default btn-sm" target="_blank">Editar</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    
    <div style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center;" class="alert alert-info">
        <div>
            <strong>Nota:</strong> Para remover um alerta de Falso Positivo, acesse "Editar" a entidade, certifique-se de que não contenha textos proibidos e salve as alterações. Ou remova o termo da lista do SpamDetector.
        </div>
        <button id="btn-purge-spam" class="btn btn-danger">🗑️ Expurgar Spam Antigo (+30 dias)</button>
    </div>
</div>

<script>
document.getElementById('btn-purge-spam').addEventListener('click', function(e) {
    e.preventDefault();
    if (!confirm('Tem certeza de que deseja EXCLUIR PERMANENTEMENTE todas as entidades bloqueadas por spam que estão na lixeira há mais de 30 dias? Esta ação não pode ser desfeita.')) {
        return;
    }

    var btn = this;
    var originalText = btn.innerHTML;
    btn.innerHTML = 'Expurgando... Aguarde...';
    btn.disabled = true;

    fetch('<?php echo \MapasCulturais\App::i()->createUrl("spamdetector", "purge"); ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if(data.success) {
            alert('Expurgo concluído com sucesso. Foram excluídos ' + data.deleted + ' registros permanentemente do banco de dados.');
            window.location.reload();
        } else {
            alert('Ocorreu um erro ao expurgar o spam.');
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    })
    .catch(error => {
        alert('Erro de rede ao tentar expurgar.');
        btn.innerHTML = originalText;
        btn.disabled = false;
    });
});
</script>
