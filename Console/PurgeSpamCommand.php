<?php
namespace SpamDetector\Console;

use MapasCulturais\App;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use PDO;

class PurgeSpamCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('spam:purge-old')
            ->setDescription('Limpeza automática: Exclui definitivamente entidades em rascunho/lixeira bloqueadas pelo SpamDetector há mais de 30 dias.');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $app = App::i();
        $conn = $app->em->getConnection();
        
        $output->writeln("Iniciando expurgo de spam antigo...");
        
        $entities = ['agent', 'space', 'event', 'project', 'opportunity'];
        $totalDeleted = 0;
        
        // Calculamos la fecha límite (hace 30 días)
        $dateLimit = new \DateTime();
        $dateLimit->sub(new \DateInterval('P30D'));
        $strDateLimit = $dateLimit->format('Y-m-d H:i:s');
        
        $output->writeln("Buscando spam criado antes de: " . $strDateLimit);

        foreach ($entities as $entityType) {
            $metaTable = $entityType . '_meta';
            
            // Buscamos entidades que: 
            // 1. Tengan estado negativo (papelera o borrador forzado)
            // 2. Estén marcadas como spam en el metadato
            // 3. Hayan sido enviadas al correo de spam hace más de 30 días
            $sql = "
                SELECT e.id 
                FROM {$entityType} e
                JOIN {$metaTable} m_status ON e.id = m_status.object_id AND m_status.key = 'spam_status' AND m_status.value = '1'
                JOIN {$metaTable} m_sent ON e.id = m_sent.object_id AND m_sent.key = 'spam_sent_email'
                WHERE e.status < 1 
                AND m_sent.value < :dateLimit
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':dateLimit', $strDateLimit, PDO::PARAM_STR);
            $stmt->execute();
            $candidates = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($candidates)) {
                $output->writeln("  - " . ucfirst($entityType) . ": 0 para excluir.");
                continue;
            }
            
            $count = count($candidates);
            $output->writeln("  - " . ucfirst($entityType) . ": Excluindo {$count} registros permanentemente...");
            
            try {
                $app->em->beginTransaction();
                
                foreach ($candidates as $id) {
                    // En Mapas Culturais, borrar la entidad principal por ORM asegura 
                    // que se disparen los hooks que eliminan relaciones e imágenes
                    $obj = $app->repo(ucfirst($entityType))->find($id);
                    if ($obj) {
                        $app->em->remove($obj);
                    }
                }
                
                $app->em->flush();
                $app->em->commit();
                
                $totalDeleted += $count;
                
            } catch (\Exception $e) {
                $app->em->rollback();
                $output->writeln("    [!] Erro ao excluir " . $entityType . ": " . $e->getMessage());
            }
        }
        
        $output->writeln("");
        $output->writeln("Processo de expurgo finalizado. Total excluídos: {$totalDeleted}");
        
        return Command::SUCCESS;
    }
}
