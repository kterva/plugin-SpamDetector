<?php

namespace SpamDetector;

use DateTime;
use Mustache;
use MapasCulturais\i;
use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\Space;
use MapasCulturais\Entities\Project;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\Notification;
use MapasCulturais\Entity;
use SpamDetector\Controller;

class Plugin extends \MapasCulturais\Plugin
{
    protected static $instance;

    public function __construct($config = [])
    {
        $spam_terms = [];
        $default_terms = [];
        $terms_block = [];
        
        $spam_terms = Plugin::getFileTerms();
        $default_terms = $spam_terms['notification'];
        $terms_block = $spam_terms['blocked'];

        if(isset($config['termsBlock'])) {
            $terms_block = $terms_block += $config['termsBlock'];
            $config['termsBlock'] = $terms_block;
        }
        
        if(isset($config['terms'])) {
            $default_terms = $default_terms += $config['terms'];
            $config['terms'] = $default_terms;
        }

        $default_fields = [
            'name', 
            'shortDescription', 
            'longDescription', 
            'nomeSocial', 
            'nomeCompleto', 
            'comunidadesTradicionalOutros',
            'facebook',
            'twitter',
            'instagram',
            'linkedin',
            'vimeo',
            'spotify',
            'youtube',
            'pinterest',
            'tiktok'
        ];

        $config += [
            'terms' => env('SPAM_DETECTOR_TERMS', $default_terms),
            'entities' => env('SPAM_DETECTOR_ENTITIES', ['Agent', 'Opportunity', 'Project', 'Space', 'Event']),
            'fields' => env('SPAM_DETECTOR_FIELDS', $default_fields),
            'termsBlock' => env('SPAM_DETECTOR_TERMS_BLOCK', $terms_block),
        ];

        parent::__construct($config);
        self::$instance = $this;
    }

    public function _init()
    {
        $app = App::i();

        if(php_sapi_name() == "cli" && !defined('SPAMDETECTOR_TEST_MODE')) {
            return;
        }

        $plugin = $this;
        $hooks = implode('|', $plugin->config['entities']);
        $last_spam_sent = null;

        $app->hook('GET(<<auth|panel>>.<<*>>):before', function() use ($app) {
            $app->view->enqueueStyle('app-v2', 'SpamDetector-v2', 'css/plugin-SpamDetector.css');
        });

        // Modificación LibreCoop Uruguay: Inyectar Campo Honeypot Oculto (Anti-Spam V2)
        $app->hook("template(<<{$hooks}>>.<<edit|single>>.scripts):begin", function() {
            // Se inyecta un input invisible en todos los formularios y se interceptan las llamadas al API base (window.fetch)
            // para enviar este input escondido en el POST payload.
            echo <<<JS
<script>
    document.addEventListener("DOMContentLoaded", function() {
        // Intercept API POST requests from Mapas Culturais (usually goes through mapasculturais jQuery/fetch or native)
        var honeypotField = document.createElement("input");
        honeypotField.type = "text";
        honeypotField.name = "__mc_email_verify";
        honeypotField.autocomplete = "off";
        honeypotField.tabIndex = "-1";
        honeypotField.setAttribute("aria-hidden", "true");
        honeypotField.style.opacity = "0";
        honeypotField.style.position = "absolute";
        honeypotField.style.top = "-1000px";
        honeypotField.style.left = "-1000px";
        
        // Agregar campo al body general; cuando un bot rellena el DOM, buscará text inputs.
        document.body.appendChild(honeypotField);

        // Mapas Culturais core overrides for MapasCulturais.api.post or fetch interception, but we will do it simpler:
        // Intercept native FormData appends
        var originalAppend = FormData.prototype.append;
        FormData.prototype.append = function() {
            var inputVal = honeypotField.value;
            if(inputVal) {
               // Append the honeypot value to simulate bot action if they filled it
               originalAppend.call(this, "__mc_email_verify", inputVal);
            }
            originalAppend.apply(this, arguments);
        };
        
        // Also intercept Object based payloads (like $.post or JSON payloads if any)
        if(window.MapasCulturais && MapasCulturais.api) {
            var origPost = MapasCulturais.api.post;
            MapasCulturais.api.post = function(action, data, cb) {
               if(data && typeof data === 'object') {
                   // only insert if there is a bot value
                   if(honeypotField.value) {
                       data['__mc_email_verify'] = honeypotField.value;
                   }
               }
               return origPost.apply(this, arguments);
            };
        }
    });
</script>
JS;
        });

        // Modificación LibreCoop Uruguay: Bypass de Detecção de Spam para Administradores
        $app->hook("entity(<<{$hooks}>>).save:before", function () use ($plugin, $app) {
            /** @var Entity $this */
            
            // Se o usuário logado existe e for admin, ignoramos a trava
            if ($app->user && $app->user->is('admin')) {
                return;
            }

            // --- DEFENSA NIVEL 2: RATE LIMITING Y HONEYPOT (LibreCoop Uruguay) ---
            $isPost = false;
            try {
                $isPost = isset($_SERVER['REQUEST_METHOD']) && in_array(strtoupper($_SERVER['REQUEST_METHOD']), ['POST', 'PUT', 'PATCH']);
            } catch (\Throwable $e) {}

            $postData = [];
            if ($isPost) {
                try {
                    $controller = $app->_currentController ?? null;
                    if ($controller && property_exists($controller, 'postData')) {
                        $postData = $controller->postData;
                    } elseif ($controller && property_exists($controller, 'putData')) {
                        $postData = $controller->putData;
                    } else {
                        // fallback JSON brute
                        $json = file_get_contents('php://input');
                        if($json) $postData = json_decode($json, true) ?: [];
                        else $postData = $_POST; // fallback normal 
                    }
                } catch (\Throwable $e) {}
            }

            // 1. Validar HONEYPOT
            if (isset($postData['__mc_email_verify']) && !empty($postData['__mc_email_verify'])) {
                error_log("DEBUG SPAM: HONEYPOT TRIGGERED! Entity: " . $this->getClassName() . " IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'));
                
                $msg = i::__("Petición sospechosa detectada. Si eres humano, por favor intenta refrescar la página.");
                if(isset($app->response)) {
                    $app->response = $app->response->withHeader('Content-Type', 'application/json');
                }
                
                $app->halt(400, json_encode([
                    'error' => true,
                    'data'  => [
                        'message' => $msg
                    ]
                ]));
                return;
            }

            // 2. RATE LIMITING DE CREACIONES (Solo para entidades nuevas)
            if (!$this->id) { // Solo si es una creación nueva
                $cache = $app->cache;
                $userId = $app->user ? $app->user->id : 'guest';
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                // Calculamos límite: 5 por hora (la hora del servidor)
                $rateKey = "spam_ratelimit_create_" . $userId . "_" . $ip . "_" . date('Y-m-d-H');
                
                $count = (int) $cache->fetch($rateKey);
                
                if ($count >= 5) {
                    error_log("DEBUG SPAM: RATE LIMIT EXCEEDED! User: $userId IP: $ip");
                    
                    $msg = i::__("Has superado el límite de 5 creaciones por hora permitidas. Por favor, intenta más tarde para continuar.");
                    if(isset($app->response)) {
                        $app->response = $app->response->withHeader('Content-Type', 'application/json');
                    }
                    
                    $app->halt(400, json_encode([
                        'error' => true,
                        'data'  => [
                            'message' => $msg
                        ]
                    ]));
                    return;
                }
                
                // Incrementar contador local. 
                // En Redis, guardar con 1 hora de TTL:
                $cache->save($rateKey, $count + 1, 3600);
            }
            // --- FIN DEFENSA NIVEL 2 ---

            // Verificamos tanto o objeto quanto os dados do POST (importante para criações via Vue/API)
            $spam_terms = $plugin->getSpamTerms($this, $plugin->config['termsBlock']);
            
            if($spam_terms && $this->spam_status != 2) {
                error_log("DEBUG SPAM: BLOCK TRIGGERED! Entity: " . $this->getClassName() . " Terms: " . json_encode($spam_terms));
                $this->spamBlock = true;
                
                // Modificação LibreCoop Uruguay: Usar status 400 em vez de 500
                $msg = i::__("Contenido inadecuado detectado. Por favor, revise los campos completados.");
                
                $isPost = false;
                try {
                    $isPost = isset($_SERVER['REQUEST_METHOD']) && in_array(strtoupper($_SERVER['REQUEST_METHOD']), ['POST', 'PUT', 'PATCH']);
                } catch (\Throwable $e) {}

                if($isPost){
                    // Mapas Culturais frontend (Entity.js) espera {error: true, data: {campo: ["erro"], message: "Erro Global"}}
                    $formatted_errors = [];
                    foreach ($spam_terms as $field => $terms) {
                        $formatted_errors[$field] = [$msg]; // Muestra el mensaje debajo del campo infractor
                    }
                    $formatted_errors['message'] = $msg; // Fuerza el toast global en la UI de Vue
                    
                    // Aseguramos el header Application/JSON para el fetch de Vue
                    if(isset($app->response)) {
                        $app->response = $app->response->withHeader('Content-Type', 'application/json');
                    }
                    
                    $app->halt(400, json_encode([
                        'error' => true,
                        'data' => $formatted_errors
                    ]));
                    return;
                } else {
                    throw new \Exception($msg);
                }
            }
        });

        $app->hook('panel.nav', function (&$nav_items) use ($app) {
            if ($app->user && $app->user->is('admin')) {
                // Modificação LibreCoop Uruguay: Link para a interface do Vue desvinculada do Modal
                $nav_items['admin']['items'][] = [
                    'route'  => 'spamdetector/config',
                    'icon'   => 'security',
                    'label'  => i::__('Control de SPAM'),
                ];
            }
        });

        // remove a permissão de publicar caso encontre termos que estão na lista de termos elegível a bloqueio
        $app->hook("entity(<<{$hooks}>>).canUser(publish)", function ($user, &$result) use($plugin, &$last_spam_sent) {
            /** @var Entity $this */
            if($user && $plugin->getSpamTerms($this, $plugin->config['termsBlock']) && !$user->is('admin') && $this->spam_status != 2) {
                $result = false;
            }
        });

        // Caso for encontrado o termo e o usuário logado for o admin, irá aparecer na entidade um warning
        $app->hook("template(<<{$hooks}>>.<<edit|single>>.entity-header):before", function() use($plugin, $app) {
            $entity = $this->controller->requestedEntity;
            $terms = array_merge($plugin->config['termsBlock'], $plugin->config['terms']);

            if($entity && $plugin->getSpamTerms($entity, $terms) && $app->user && $app->user->is('admin')) {
                $this->part('admin-spam-warning');
                $app->view->enqueueStyle('app-v2', 'admin-spam-warning', 'css/admin-spam-warning.css');
            }
        });

        // Envia notificação para o admin caso encontre termos que estão na lista de termos elegível a notificação
        $app->hook("entity(<<{$hooks}>>).save:after", function () use ($plugin, $last_spam_sent, $app) {
            /** @var Entity $this */
            try {
                // Bypass para administradores
                if ($app->user && $app->user->is('admin')) {
                    return;
                }

                // Evitar duplicidade de processamento se já foi bloqueado no save:before
                if (isset($this->spamBlock) && $this->spamBlock) {
                    return;
                }

                $spam_detections = $plugin->getSpamTerms($this, $plugin->config['terms']);

                if ($spam_detections) {
                    // Modificação LibreCoop Uruguay: Blindagem contra acesso a propriedades nulas
                    $owner = $this->ownerUser;
                    if (!$owner) {
                        return;
                    }

                    $meta = $this->getMetadata('spam_sent_email');
                    $last_sent = null;

                    if ($meta) {
                        if ($meta instanceof \DateTime) {
                            $last_sent = $meta;
                        } elseif (is_string($meta)) {
                            try {
                                $last_sent = new \DateTime($meta);
                            } catch (\Exception $e) {
                                $last_sent = null;
                            }
                        }
                    }

                    $now = new \DateTime();

                    if (!$last_sent || $now->getTimestamp() > $last_sent->getTimestamp()) {
                        $admins = $plugin->getAdminUsers($this);
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

                        foreach ($admins as $admin) {
                            $plugin->createNotification($admin, $this, $spam_detections, $ip);
                        }
                    }
                }
            } catch (\Exception $e) {
                error_log("DEBUG SPAM ERROR in save:after: " . $e->getMessage());
            } catch (\Error $e) {
                error_log("DEBUG SPAM FATAL in save:after: " . $e->getMessage());
            }
        });

        // bloqueia e torna o status da entidade como rascunho (-1) caso encontre termos que estão na lista de termos elegível a bloqueio
        $app->hook("entity(<<{$hooks}>>).save:finish", function ($is_new) use ($plugin, $app) {
            /** @var Entity $this */
            
            if ($app->user && $app->user->is('admin')) {
                return;
            }

            // Se o save:before já marcou como spamBlock, o processo de salvamento deve ser interrompido/modificado
            // No entanto, se o fluxo chegou aqui, tentamos uma última verificação de segurança
            if ($plugin->getSpamTerms($this, $plugin->config['termsBlock']) && $this->spam_status != 2) {
                $this->status = -10; // Enviar para lixeira
                
                // Forçar persistência do status se necessário
                $conn = $app->em->getConnection();
                $table = $plugin->dictTable($this);
                $id = (int) $this->id;
                
                if ($id > 0) {
                    $conn->executeQuery("UPDATE {$table} SET status = -10 WHERE id = {$id}");
                }
            }
        });

        $app->hook('component(mc-icon).iconset', function(&$iconset){
            $iconset['security'] = "material-symbols:security";
        });
    }
    
    public function register() {
        $app = App::i();

        $app->registerController('spamdetector', Controller::class);

        // Modificação LibreCoop Uruguay: Comando de limpeza automática (Purge)
        if (php_sapi_name() === 'cli' && isset($app->console)) {
            $app->console->add(new Console\PurgeSpamCommand());
        }

        $entities = $this->config['entities'];

        foreach($entities as $entity) {
            $namespace = "MapasCulturais\\Entities\\{$entity}";

            $this->registerMetadata($namespace,'spam_sent_email', [
                'label' => i::__('Data de envio do e-mail'),
                'type' => 'DateTime',
                'default' => null,
            ]);
            
            $this->registerMetadata($namespace,'spam_status', [
                'label' => i::__('Classificar como Spam'),
                'type' => 'int',
                'default' => 1,
            ]);
        }
    }
    
    public function createNotification($recipient, $entity, $spam_detections, $ip)
    {
        $app = App::i();
        $app->disableAccessControl();
        
        $is_save = !$entity->spamBlock;
        $message = $this->getNotificationMessage($entity, $is_save);
        $notification = new Notification;
        $notification->user = $recipient->user;
        $notification->message = $message;
        $notification->save(true);

        $locale = i::get_locale();
        $template = "email-spam-{$locale}.html";
        
        $filename = $app->view->resolveFilename("views/emails", $template);
        if (!$filename) {
            // Tentar fallback genérico se não achou o locale específico (ex: CLI test)
            $filename = __DIR__ . '/views/emails/email-spam-es_ES.html';
            if (!file_exists($filename)) {
                $filename = __DIR__ . '/views/emails/email-spam-pt_BR.html';
            }
        }
        $templateContent = file_get_contents($filename);
        
        $field_translations = [
            "name" => i::__("Nome"),
            "shortDescription" => i::__("Descrição Curta"),
            "longDescription" => i::__("Descrição Longa"),
        ];
        
        $detected_details = [];
        foreach ($spam_detections as $detection) {
            $translated_field = isset($field_translations[$detection['field']]) ? $field_translations[$detection['field']] : $detection['field'];
            $detected_details[] = sprintf(i::__("Campo: %s, Termos: %s"), $translated_field, implode(', ', $detection['terms'])) . '<br>';
        }

        $dict_entity = $this->dictEntity($entity, 'artigo');

        $mail_notification_message = i::__('O sistema detectou possível spam em um conteúdo recente. Por favor, revise as informações abaixo e tome as medidas necessárias:');
        $mail_blocked_message = i::__("O sistema detectou um conteúdo inadequado neste cadastro e moveu-o para a lixeira. Seguem abaixo os dados para análise do conteúdo:");
        $mail_message = $is_save ? $mail_notification_message : $mail_blocked_message;

        $params = [
            "siteName" => $app->siteName,
            "nome" => $entity->name,
            "id" => $entity->id,
            "url" => $entity->singleUrl,
            "baseUrl" => $app->getBaseUrl(),
            "detectedDetails" => implode("\n", $detected_details),
            "ip" => $ip,
            "adminName" => $recipient->name,
            'mailMessage' => $mail_message,
            'dictEntity' => $this->dictEntity($entity, 'none')
        ];
        
        $mustache = new \Mustache_Engine();
        $content = $mustache->render($templateContent, $params);

        if ($email = $this->getAdminEmail($recipient)) {
            $app->createAndSendMailMessage([
                'from' => $app->config['mailer.from'],
                'to' => $email,
                'subject' => $is_save ? i::__("Spam - Conteúdo suspeito") : i::__("Spam - {$dict_entity} foi bloqueado(a)"),
                'body' => $content,
            ]);
        }

        // Salvar metadado
        $date_time = new DateTime();
        $date_time->add(new \DateInterval('PT10S'));
        $date_time = $date_time->format('Y-m-d H:i:s');
        
        $table = $this->dictTable($entity);
        $table_meta = strtolower($table)."_meta";
        $entity_id = (int) $entity->id;

        if ($entity_id > 0) {
            $conn = $app->em->getConnection();
            if(!$conn->fetchAll("SELECT * FROM {$table_meta} WHERE key = 'spam_sent_email' and object_id = {$entity_id}")) {
                $conn->executeQuery("INSERT INTO {$table_meta} (id, object_id, key, value) VALUES (nextval('{$table_meta}_id_seq'), {$entity_id}, 'spam_sent_email', '{$date_time}')");
            } else {
                $conn->executeQuery("UPDATE {$table_meta} SET value = '{$date_time}' WHERE object_id = {$entity_id} AND key = 'spam_sent_email'");
            }
        }

        $app->enableAccessControl();
    }   

    /**
     *  Retorna o texto relacionado a entidade
     * @param Entity $entity 
     * @return string 
     */
    public function dictEntity(Entity $entity, $type = "preposição"): string
    {
        $class = $entity->getClassName();

        switch ($type) {
            case 'preposição':
                $prefixes = (object) ["f" => "na", "m" => "no"];
                break;
            case 'pronome':
                $prefixes = (object) ["f" => "esta", "m" => "este"];
                break;
            case 'artigo':
                $prefixes = (object) ["f" => "a", "m" => "o"];
                break;
            case 'none':
                $prefixes = (object) ["f" => "", "m" => ""];
                break;
            default:
                $prefixes = (object) ["f" => "", "m" => ""];
                break;
        }

        $entities = [
            Agent::class => "{$prefixes->m} Agente",
            Opportunity::class => "{$prefixes->f} Oportunidade",
            Project::class => "{$prefixes->m} Projeto",
            Space::class => "{$prefixes->m} Espaço",
            Event::class => "{$prefixes->m} Evento",
        ];

        return $entities[$class];
    }

    /**
     *  Retorna o texto com o nome da tabela
     * @param Entity $entity 
     * @return string 
     */
    public function dictTable(Entity $entity): string
    {
        $class = $entity->getClassName();

        $entities = [
            Agent::class => "agent",
            Opportunity::class => "opportunity",
            Project::class => "project",
            Space::class => "space",
            Event::class => "event",
        ];

        return $entities[$class];
    }

    /**
     * @param string $text
     * @return string
     */
    public function formatText($text)
    {
        $text = trim($text);
        $text = strip_tags($text);
        $text = mb_strtolower($text);

        return $text;
    }

    /**
     * @param object $entity Objeto da entidade que deve ter a propriedade `subsiteId`. A presença desta propriedade determina o tipo de papéis a serem recuperados.
     * 
     * @return array Um array contendo os IDs dos usuários que têm um papel administrativo. O array pode estar vazio se nenhum papel administrativo for encontrado.
    */
    public function getAdminUsers($entity): array {
        $app = App::i();

        $subsiteId = null;
        if (isset($entity->subsiteId)) {
            $subsiteId = $entity->subsiteId;
        }

        $roles = $app->repo('Role')->findBy(['subsiteId' => [$subsiteId, null]]);
        
        $users = [];
        if ($roles) {
            foreach ($roles as $role) {
                if ($role->user && $role->user->is('admin')) {
                    $users[] = $role->user;
                }
            }
        }

        return $users;
    }

    /**
     * @param object $entity Objeto da entidade a ser validada. A entidade deve ter propriedades que correspondem aos campos configurados.
     * 
     * @return array Retorna um array contendo os campos onde termos de spam foram encontrados.
    */
    public function getSpamTerms($entity, $terms): array {
        $app = App::i();

        $fields = $this->config['fields'];
        $spam_detector = [];
        $found_terms = [];
        $special_chars = ['@', '#', '$', '%', '^', '·', '&', '*', '(', ')', '-', '_', '=', '+', '{', '}', '[', ']', '|', ':', ';', '"', '\'', '<', '>', ',', '.', '?', '/', ' '];
        $special_chars = array_map(fn($char) => preg_quote($char, '/'), $special_chars);
        $special_chars = '[' . implode('', $special_chars) . ']*';

        foreach ($fields as $field) {
            $value = $entity->$field;
            
            // Se o campo estiver no POST (prioridade para dados novos/asíncronos)
            $isPost = false;
            try {
                $isPost = isset($_SERVER['REQUEST_METHOD']) && in_array(strtoupper($_SERVER['REQUEST_METHOD']), ['POST', 'PUT', 'PATCH']);
            } catch (\Throwable $e) {}

            if($isPost){
                $postData = [];
                try {
                    $controller = $app->_currentController ?? null;
                    if ($controller && property_exists($controller, 'postData')) {
                        $postData = $controller->postData;
                    } elseif ($controller && property_exists($controller, 'putData')) {
                        $postData = $controller->putData;
                    } else {
                        // fallback se json bruto
                        $json = file_get_contents('php://input');
                        if($json) $postData = json_decode($json, true) ?: [];
                    }
                } catch (\Throwable $e) {}
                
                if(is_array($postData) && isset($postData[$field])){
                    $value = $postData[$field];
                }
            }

            if ($value) {
                // Modificação LibreCoop Uruguay: Remove mb_strtolower para permitir Regex Case Insensitive avançado
                $clean_value = strip_tags(trim($value));

                foreach ($terms as $term) {
                    $term = trim($term);
                    if (empty($term)) continue;

                    // Modificação LibreCoop Uruguay: Suporte nativo para expressões regulares puras
                    // Se o termo começar e terminar com barra (ex. /http.*\.ru/i), será usado crú
                    if (str_starts_with($term, '/') && str_ends_with($term, '/') && strlen($term) > 2) {
                         $pattern = $term . 'u'; // Adiciona flag 'u' para unicode preventivamente
                    } else {
                        // Escapa o termo para Regex
                        $_term = preg_quote($term, '/');

                        // Padrão melhorado: \b (limite de palavra), /i (Case Insensitive), /u (Suporte Unicode utf-8)
                        // Usa delimitadores negativos de lookbehind/lookahead para suporte Unicode onde \b falha com acentos
                        $pattern = '/(?<![\p{L}\p{N}_])' . $_term . '(?![\p{L}\p{N}_])/iu';
                    }

                    if (@preg_match($pattern, $clean_value) && !in_array($term, $found_terms[$field] ?? [])) {
                        $found_terms[$field][] = $term;
                    }
                }
            }
        }

        if ($found_terms) {
            foreach($found_terms as $key => $value) {
                $spam_detector[] = [
                    'field' => $key,
                    'terms' => $value
                ];
            }
        }

        return $spam_detector;
    }

    /**
     * @param object $entity Objeto da entidade que contém as propriedades `name` e `singleUrl`. A propriedade `name` é usada para identificar a entidade na mensagem, e `singleUrl` é o link para a verificação.
     * @param bool $is_save Indica o status de salvamento da entidade.
     * 
     * @return string Retorna uma mensagem formatada de notificação baseada no status de salvamento.
    */
    public function getNotificationMessage($entity, $is_save): string {
        $dict_entity = $this->dictEntity($entity, 'artigo');
        $message_save = sprintf(i::__("Possível spam detectado %s - <strong><i>%s</i></strong><br><br> <a href='%s'>Clique aqui</a> para verificar. Mais detalhes foram enviados para o seu e-mail"), $dict_entity, $entity->name, $entity->singleUrl);
        $message_insert = sprintf(i::__("Possível spam detectado %s - <strong><i>%s</i></strong><br><br> Apenas um administrador pode publicar este conteúdo, <a href='%s'>clique aqui</a> para verificar. Mais detalhes foram enviados para o seu e-mail"), $dict_entity, $entity->name, $entity->singleUrl);

        $message = $is_save ? $message_save : $message_insert;

        return $message;
    }
    
    /**
     * @param object $agent Objeto que representa o agente. O objeto deve ter as propriedades `emailPrivado`, `emailPublico`, e `user` (que deve ter a propriedade `email`).
     * 
     * @return string O endereço de e-mail do agente.
    */
    public function getAdminEmail($recipient): string {
        if($recipient->emailPrivado) {
            $email = $recipient->emailPrivado;
        } else if($recipient->emailPublico) {
            $email = $recipient->emailPublico;
        } else {
            $email = $recipient->user->email;
        }

        return $email;
    }

    public static function getInstance(){
        return self::$instance;
    }
    
    /**
     * @return array Retorna um array com os dados salvos no arquivo de configuração de termos
     */
    public static function getFileTerms(): array
    {
        $path = Plugin::getPathFile();
        $result = [
            "notification" => [],
            "blocked" => [],
        ];

        if (file_exists($path)) {
            $data = file_get_contents($path);

            if($_data = json_decode($data, true)) {
                $result['notification'] = $_data['notification'] ?? [];
                $result['blocked'] = $_data['blocked'] ?? [];
            }
        }

        return $result;
    }

    /**
     * @return string Retorna uma string que representa o caminho do arquivo de configuração de termos
     */
    public static function getPathFile(): string
    {
        $file_path = PRIVATE_FILES_PATH . "spamDetector";
        $file_name = 'terms-config.txt';
        $path = $file_path . '/' . $file_name;
        $source_file = __DIR__ . '/files/' . $file_name;

        // Verifica se o diretório existe, senão cria
        if (!is_dir($file_path)) {
            mkdir($file_path, 0777, true);
        }

        // Verifica se o arquivo não existe e copia do diretório de origem
        if (!file_exists($path) && file_exists($source_file)) {
            copy($source_file, $path);
        }

        return $path;
    }

    public function lockEntityTree($user)
    {
        $app = App::i();

        $conn = $app->em->getConnection();

        $agent_ids = [];
        if($entities = $this->config['entities']) {
            if($results = $conn->fetchAll("SELECT * FROM agent WHERE user_id = {$user->id}")) {
                foreach($results as $value) {
                    $agent_ids[] = $value['id'];
                }
            }

            if($agent_ids) {
                $ids = implode(",", $agent_ids);
                foreach($entities as $entity) {
                    $table = strtolower($entity);
                    $column = $table === "agent" ? "id" : "agent_id";
                    $conn->executeQuery("UPDATE {$table} SET status = -10 WHERE {$column} IN ({$ids})");
                }
            }
        }
    }
}
