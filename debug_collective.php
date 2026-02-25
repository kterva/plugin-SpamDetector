<?php
define('SPAMDETECTOR_TEST_MODE', true);
require '/var/www/html/bootstrap.php';
$app = \MapasCulturais\App::i();
$app->disableAccessControl();

$plugin = \SpamDetector\Plugin::getInstance();
if (!$plugin) {
    $plugin = new \SpamDetector\Plugin([]);
    $app->plugins['SpamDetector'] = $plugin;
}
$plugin->_init();

$user = $app->repo('User')->find(2);
$authRef = new \ReflectionClass($app->auth);
if ($authRef->hasProperty('authenticatedUser')) {
    $authProp = $authRef->getProperty('authenticatedUser');
    $authProp->setAccessible(true);
    $authProp->setValue($app->auth, $user);
}

echo "Attempting to save COLLECTIVE agent with name 'puta'...\n";
$agent = new \MapasCulturais\Entities\Agent;
$agent->type = 2; // Colectivo
$agent->name = "puta";

try {
    $agent->save(true);
    echo "SUCCESS (Not expected)\n";
} catch (\Exception $e) {
    echo "CAUGHT EXCEPTION: " . $e->getMessage() . "\n";
    echo "TRACE:\n" . $e->getTraceAsString() . "\n";
}
