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
            LEFT JOIN agent p ON e.agent_id = p.id
            JOIN event_meta m ON e.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
              AND EXISTS (SELECT 1 FROM event_meta se WHERE se.object_id = m.object_id AND se.key = 'spam_sent_email')
            UNION ALL
            SELECT 'agent' as type, a.id, a.name, p.name as owner_name, m.value as status
            FROM agent a
            LEFT JOIN agent p ON a.parent_id = p.id
            JOIN agent_meta m ON a.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
              AND EXISTS (SELECT 1 FROM agent_meta se WHERE se.object_id = m.object_id AND se.key = 'spam_sent_email')
            UNION ALL
            SELECT 'space' as type, s.id, s.name, p.name as owner_name, m.value as status
            FROM space s
            LEFT JOIN agent p ON s.agent_id = p.id
            JOIN space_meta m ON s.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
              AND EXISTS (SELECT 1 FROM space_meta se WHERE se.object_id = m.object_id AND se.key = 'spam_sent_email')
            UNION ALL
            SELECT 'project' as type, pr.id, pr.name, p.name as owner_name, m.value as status
            FROM project pr
            LEFT JOIN agent p ON pr.agent_id = p.id
            JOIN project_meta m ON pr.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
              AND EXISTS (SELECT 1 FROM project_meta se WHERE se.object_id = m.object_id AND se.key = 'spam_sent_email')
            UNION ALL
            SELECT 'opportunity' as type, o.id, o.name, p.name as owner_name, m.value as status
            FROM opportunity o
            LEFT JOIN agent p ON o.agent_id = p.id
            JOIN opportunity_meta m ON o.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
              AND EXISTS (SELECT 1 FROM opportunity_meta se WHERE se.object_id = m.object_id AND se.key = 'spam_sent_email')
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

        // upstream plugin-SpamDetector dcd66b1: antes se guardaba $this->data tal cual
        // y un payload incompleto o concurrente podía borrar términos del archivo.
        $notification = $this->sanitizeTerms($this->data['notification'] ?? null);
        $blocked = $this->sanitizeTerms($this->data['blocked'] ?? null);

        if (null === $notification || null === $blocked) {
            $this->json(['error' => i::__('invalid payload: "notification" and "blocked" must be arrays', 'spamDetector')], 400);
            return;
        }

        $data = [
            'notification' => $notification,
            'blocked' => $blocked,
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if (!Plugin::writeFileTerms($json)) {
            $this->json(['error' => i::__('unable to persist the terms file', 'spamDetector')], 500);
            return;
        }

        $this->json($data);
    }

    /**
     * Limpia una lista de términos: solo strings, sin espacios en los extremos ni
     * etiquetas HTML, sin vacíos ni duplicados. Devuelve null si no es un array.
     * Los términos que empiezan con / (regex) no pasan por strip_tags para no romper patrones.
     */
    protected function sanitizeTerms($terms): ?array
    {
        if (!is_array($terms)) {
            return null;
        }

        $clean = [];
        foreach ($terms as $term) {
            if (!is_string($term)) {
                continue;
            }

            $term = trim($term);
            if (!str_starts_with($term, '/')) {
                $term = trim(strip_tags($term));
            }
            if ('' === $term) {
                continue;
            }

            $clean[] = $term;
        }

        return array_values(array_unique($clean));
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
            LEFT JOIN agent p ON e.agent_id = p.id
            JOIN event_meta m ON e.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
              AND EXISTS (SELECT 1 FROM event_meta se WHERE se.object_id = m.object_id AND se.key = 'spam_sent_email')
            UNION ALL
            SELECT 'agent' as type, a.id, a.name, p.name as owner_name, m.value as status
            FROM agent a
            LEFT JOIN agent p ON a.parent_id = p.id
            JOIN agent_meta m ON a.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
              AND EXISTS (SELECT 1 FROM agent_meta se WHERE se.object_id = m.object_id AND se.key = 'spam_sent_email')
            UNION ALL
            SELECT 'space' as type, s.id, s.name, p.name as owner_name, m.value as status
            FROM space s
            LEFT JOIN agent p ON s.agent_id = p.id
            JOIN space_meta m ON s.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
              AND EXISTS (SELECT 1 FROM space_meta se WHERE se.object_id = m.object_id AND se.key = 'spam_sent_email')
            UNION ALL
            SELECT 'project' as type, pr.id, pr.name, p.name as owner_name, m.value as status
            FROM project pr
            LEFT JOIN agent p ON pr.agent_id = p.id
            JOIN project_meta m ON pr.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
              AND EXISTS (SELECT 1 FROM project_meta se WHERE se.object_id = m.object_id AND se.key = 'spam_sent_email')
            UNION ALL
            SELECT 'opportunity' as type, o.id, o.name, p.name as owner_name, m.value as status
            FROM opportunity o
            LEFT JOIN agent p ON o.agent_id = p.id
            JOIN opportunity_meta m ON o.id = m.object_id
            WHERE m.key = 'spam_status' AND m.value = '1'
              AND EXISTS (SELECT 1 FROM opportunity_meta se WHERE se.object_id = m.object_id AND se.key = 'spam_sent_email')
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
