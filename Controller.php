<?php

namespace SpamDetector;

use MapasCulturais\App;
use MapasCulturais\i;
use MapasCulturais\Controller as SpamDetectorController;

class Controller extends SpamDetectorController
{

    public function __construct() {}

    public function GET_config()
    {
        $app = App::i();
        $this->requireAuthentication();

        if (!$app->user->is("admin")) {
            $app->pass();
        }

        // Cargar los items mandados a papelera por SpamDetector (Consolidado)
        $conn = $app->em->getConnection();
        
        $query = "
            SELECT 'event' as type, e.id, e.name, p.name as owner_name, m.value as status
            FROM event e
            JOIN agent p ON e.owner_id = p.id
            JOIN event_meta m ON e.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
            UNION ALL
            SELECT 'agent' as type, a.id, a.name, p.name as owner_name, m.value as status
            FROM agent a
            JOIN agent p ON a.owner_id = p.id
            JOIN agent_meta m ON a.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
            UNION ALL
            SELECT 'space' as type, s.id, s.name, p.name as owner_name, m.value as status
            FROM space s
            JOIN agent p ON s.owner_id = p.id
            JOIN space_meta m ON s.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
            UNION ALL
            SELECT 'project' as type, pr.id, pr.name, p.name as owner_name, m.value as status
            FROM project pr
            JOIN agent p ON pr.owner_id = p.id
            JOIN project_meta m ON pr.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
            UNION ALL
            SELECT 'opportunity' as type, o.id, o.name, p.name as owner_name, m.value as status
            FROM opportunity o
            JOIN agent p ON o.owner_id = p.id
            JOIN opportunity_meta m ON o.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
        ";
        
        try {
            $items = $conn->fetchAll($query);
        } catch (\Exception $e) {
            $items = [];
        }

        $this->render('config', ['items' => $items]);
    }

    public function POST_saveterms()
    {
        $app = App::i();

        $this->requireAuthentication();

        if (!$app->user->is("admin")) {
            $app->pass();
        }

        $path = Plugin::getPathFile();

        if (file_exists($path)) {
            $data = json_encode($this->data, JSON_PRETTY_PRINT);
            file_put_contents($path, $data);
        }

        $this->json($this->data);
    }
    
    public function GET_log()
    {
        $app = App::i();
        $this->requireAuthentication();

        if (!$app->user->is("admin")) {
            $app->pass();
        }
        
        // Cargar los items mandados a papelera por SpamDetector
        $conn = $app->em->getConnection();
        
        $query = "
            SELECT 'event' as type, e.id, e.name, p.name as owner_name, m.value as status
            FROM event e
            JOIN agent p ON e.owner_id = p.id
            JOIN event_meta m ON e.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
            UNION ALL
            SELECT 'agent' as type, a.id, a.name, p.name as owner_name, m.value as status
            FROM agent a
            JOIN agent p ON a.owner_id = p.id
            JOIN agent_meta m ON a.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
            UNION ALL
            SELECT 'space' as type, s.id, s.name, p.name as owner_name, m.value as status
            FROM space s
            JOIN agent p ON s.owner_id = p.id
            JOIN space_meta m ON s.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
            UNION ALL
            SELECT 'project' as type, pr.id, pr.name, p.name as owner_name, m.value as status
            FROM project pr
            JOIN agent p ON pr.owner_id = p.id
            JOIN project_meta m ON pr.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
            UNION ALL
            SELECT 'opportunity' as type, o.id, o.name, p.name as owner_name, m.value as status
            FROM opportunity o
            JOIN agent p ON o.owner_id = p.id
            JOIN opportunity_meta m ON o.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
        ";
        
        $items = $conn->fetchAll($query);
        
        $this->render('log', ['items' => $items]);
    }

    public function POST_purge()
    {
        $app = App::i();
        $this->requireAuthentication();

        if (!$app->user->is("admin")) {
            $app->pass();
        }
        
        // Eliminar completamente entidades con status de spam mayor a 30 días
        $output = '';
        try {
            $output = \trim(\shell_exec('php /var/www/console.php spam:purge-old'));
        } catch (\Exception $e) {
            $output = "Error: " . $e->getMessage();
        }
        
        $this->json(['success' => true, 'output' => $output]);
    }
}
