<?php

require '/var/www/html/bootstrap.php';
require_once __DIR__ . '/Plugin.php';
require_once __DIR__ . '/Controller.php';

define('SPAMDETECTOR_TEST_MODE', true);

use MapasCulturais\App;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\User;

$app = App::i();
$app->disableAccessControl();
$conn = $app->em->getConnection();

echo "========================================\n";
echo "🛠️  INICIANDO SUITE DE PRUEBAS DE SPAM\n";
echo "========================================\n\n";

// Inyectar términos de prueba directamente al sistema de archivos para garantizar su carga
$path = PRIVATE_FILES_PATH . "spamDetector";
if (!is_dir($path)) mkdir($path, 0777, true);
$testConfig = [
    'blocked' => ['cassino', 'citotec', '/\+7\d{10}/', '/http.*\.ru/'],
    'notification' => ['comprar', 'vender']
];
file_put_contents($path . '/terms-config.txt', json_encode($testConfig));

$plugin = \SpamDetector\Plugin::getInstance();
if (!$plugin) {
    echo "[!] Inicializando SpamDetector manualmente para la prueba CLI...\n";
    $config = [];
    $plugin = new \SpamDetector\Plugin($config);
    $app->plugins['SpamDetector'] = $plugin;
    $plugin->_init();
}

// --- PREPARACIÓN DE USUARIOS ---
echo "[*] Preparando Usuarios de prueba...\n";

// Obtener Usuario Normal existente (Cualquiera que no sea admin, ej: ID > 1)
$userNormal = $app->repo('User')->findOneBy(['id' => 2]);
if (!$userNormal) {
    // Si no existe ID 2, traemos cualquiera q no sea ID 1
    $qb = $app->em->createQueryBuilder();
    $qb->select('u')->from('MapasCulturais\Entities\User', 'u')->where('u.id != 1')->setMaxResults(1);
    $userNormal = $qb->getQuery()->getOneOrNullResult();
}

// Obtener Usuario Admin existente (Generalmente ID 1)
$userAdmin = $app->repo('User')->find(1);

if ($userNormal->is('admin')) {
    die("    [!] ERROR CRÍTICO: El usuario normal de pruebas tiene rol Admin. Este test es inválido.\n");
}

// Hacerlo admin insertando en el array de admins en config.d (no es posible en runtime fácil),
// Así que forzaremos el rol o haremos un mock.
// MapasCulturais verifica admin mediante Roles en subsite.
$role = new \MapasCulturais\Entities\Role();
$role->user = $userAdmin;
$role->name = 'admin';
$role->save(true);

echo "    - Usuario Normal ID: {$userNormal->id}\n";
echo "    - Usuario Admin ID: {$userAdmin->id} (Role Admin)\n\n";

// --- PRUEBA 1: Regex Limits (\b) ---
echo "[1] PRUEBA: Motor Regex Avanzado y Falsos Positivos\n";
// Simular logueo como ciudadano vía Reflection (ya que \$app->user = \$userNormal arroja Notice/no setea)
if (isset($app->auth)) {
    $authRef = new \ReflectionClass($app->auth);
    if ($authRef->hasProperty('authenticatedUser')) {
        $authProp = $authRef->getProperty('authenticatedUser');
        $authProp->setAccessible(true);
        $authProp->setValue($app->auth, $userNormal);
    }
}

$event1 = new Event();
$event1->owner = $userNormal->profile; // FIX: Esto evita que ownerUser devuelva GuestUser
$event1->name = "Evento de prueba - No es casino";
$event1->shortDescription = "Aquí se habla de la historia del cassino ilegal"; // Este sí debería bloquear
$event1->ownerUser = $userNormal;

$event1->save(true);
$app->em->flush(); // Forzar dispatch de eventos de Doctrine

$status1 = $conn->fetchOne("SELECT status FROM event WHERE id = " . $event1->id);
if ($status1 == -10) {
    echo "    ✅ ÉXITO: El evento con la palabra 'cassino' fue enviado a papelera (-10).\n";
} else {
    echo "    ❌ ERROR: El evento no se bloqueó en la DB (Status: {$status1}).\n";
}

$event2 = new Event();
$event2->owner = $userNormal->profile;
$event2->name = "Evento Inocente";
$event2->shortDescription = "No contiene palabras prohibidas. Solo la palabra comprar que es nivel 1.";
$event2->ownerUser = $userNormal;
$event2->save(true);
$app->em->flush();

if ($event2->status > 0) {
    echo "    ✅ ÉXITO: El evento inocente (Nivel 1) no fue bloqueado en papelera.\n";
    
    // Check metadata
    $stmt = $conn->prepare("SELECT value FROM event_meta WHERE object_id = :id AND key = 'spam_status'");
    $stmt->bindValue('id', $event2->id);
    $meta = $stmt->executeQuery()->fetchOne();
    if ($meta == '1') {
        echo "    ✅ ÉXITO: El evento nivel 1 tiene el metadata spam_status = 1.\n";
    } else {
        echo "    ❌ ERROR: No se insertó metadata spam_status.\n";
    }
} else {
    echo "    ❌ ERROR: El evento nivel 1 fue bloqueado indebidamente.\n";
}

// --- PRUEBA 2: Regex Crudo ---
echo "\n[2] PRUEBA: Expresiones Regulares Dinámicas (UI)\n";
$event3 = new Event();
$event3->owner = $userNormal->profile;
$event3->name = "Venta de algo Ruso";
$event3->shortDescription = "Visítanos en http://spamy.ru para más info.";
$event3->ownerUser = $userNormal;
$event3->save(true);
$app->em->flush();

$status3 = $conn->fetchOne("SELECT status FROM event WHERE id = " . $event3->id);
if ($status3 == -10) {
    echo "    ✅ ÉXITO: El regex dinámico (/http.*\.ru/) detectó y bloqueó el evento.\n";
} else {
    echo "    ❌ ERROR: El evento Ruso sobrevivió (Status: {$status3}).\n";
}

// --- PRUEBA 3: Limitador de Notificaciones (Redis) ---
echo "\n[3] PRUEBA: Rate Limit de Notificaciones a Admins (Redis)\n";
// Se asume que el envío de notificaciones se disparó en las entidades anteriores.
// Chequeamos el Redis
$cacheKey = 'spamdetector_last_admin_notification';
$lastNotification = $app->cache->fetch($cacheKey);
if ($lastNotification) {
    echo "    ✅ ÉXITO: La bandera de tiempo del Redis se insertó para Cooldown.\n";
} else {
    echo "    ❌ ERROR: Redis cache `$cacheKey` está vacío.\n";
}


// --- PRUEBA 4: Inmunidad del Usuario Creador (La cuenta no muere) ---
echo "\n[4] PRUEBA: Prevención de Muerte en Cascada del Creador\n";
$app->em->flush();
$statusUserNormal = $conn->fetchOne("SELECT status FROM agent WHERE user_id = " . $userNormal->id);
if ($statusUserNormal > 0) {
    echo "    ✅ ÉXITO: El perfil del ciudadano Sigue Activo (Status: {$statusUserNormal}) pese a crear múltiples spams.\n";
} else {
    echo "    ❌ ERROR: El usuario Normal fue enviado a la papelera (Status: {$statusUserNormal}).\n";
}

// --- PRUEBA 5: Inmunidad del Administrador ---
echo "\n[5] PRUEBA: Bypass para Usuarios Administradores\n";
if (isset($authProp) && isset($app->auth)) {
    $authProp->setValue($app->auth, $userAdmin); // Simular Sesión Admin
}

$adminEvent = new Event();
$adminEvent->owner = $userAdmin->profile;
$adminEvent->name = "Reporte de sitio clausurado";
$adminEvent->shortDescription = "Se descubrió un cassino ilegal.";
$adminEvent->ownerUser = $userAdmin;
$adminEvent->save(true);
$app->em->flush();

$statusAdminEvent = $conn->fetchOne("SELECT status FROM event WHERE id = " . $adminEvent->id);
if ($statusAdminEvent > 0) {
    echo "    ✅ ÉXITO: El administrador PUDO crear el evento conteniendo palabras restringidas (Status: {$statusAdminEvent}).\n";
} else {
    echo "    ❌ ERROR: El administrador fue BLOQUEADO al crear el evento (Status: {$statusAdminEvent}).\n";
}

echo "\n----------------------------------------\n";
echo "📋 REPORTE FINALIZADO.\n";
echo "----------------------------------------\n";
