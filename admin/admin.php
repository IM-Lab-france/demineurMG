<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Europe/Paris');

require __DIR__ . '/../vendor/autoload.php';  // Assurez-vous d'inclure Monolog via Composer

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

// Initialiser Monolog
$logger = new Logger('monitor_server');

// Définir le chemin du fichier de log
$logFilePath = __DIR__ . '/../logs/monitor_server.log';
$rotatingHandler = new RotatingFileHandler($logFilePath, 0, Logger::INFO);

// Ajouter le handler pour gérer les logs avec rotation
$logger->pushHandler($rotatingHandler);

$lockFile = '/var/www/html/admin/locks/server.lock'; // Fichier de verrouillage

// Fonction pour vérifier si le processus server.php est actif
function isServerRunning() {
    global $logger;
    $output = [];
    exec("ps aux | grep '[/]server.php'", $output);  // Exécuter la commande ps aux | grep
    $logger->info('Vérification du statut du serveur', ['output' => $output]);

    if (count($output) > 0) {
        $logger->info('Le serveur est toujours actif.', ['processes' => $output]);
        return true;
    } else {
        $logger->info('Le serveur est arrêté.');
        return false;
    }
}

// Fonction pour démarrer le serveur
function startServer() {
    global $logger;
    exec('php /var/www/html/server.php > /dev/null &');
    $logger->info('Le serveur a été démarré.');
}

// Fonction pour arrêter le serveur
function stopServer() {
    global $logger;
    $output = [];

    // Utiliser pgrep pour récupérer les PID des processus 'server.php'
    exec("pgrep -f '/var/www/html/server.php'", $output);
    $logger->info('Processus en cours (avant tentative de kill):', ['processes' => $output]);

    if (count($output) > 0) {
        foreach ($output as $pid) {
            exec("sudo kill $pid");  // Utiliser sudo pour kill
            $logger->info("Processus $pid tué.");
        }

        // Attendre quelques secondes pour permettre l'arrêt complet des processus
        sleep(2);

        // Vérification après l'arrêt
        $remainingProcesses = [];
        exec("pgrep -f '/var/www/html/server.php'", $remainingProcesses);
        if (count($remainingProcesses) === 0) {
            $logger->info('Tous les processus server.php ont été tués avec succès.');
        } else {
            $logger->warning('Certains processus server.php sont toujours actifs après tentative de kill.', ['remaining_processes' => $remainingProcesses]);
        }
    } else {
        $logger->info("Aucun processus 'server.php' trouvé.");
    }
}

// Démarrer le serveur manuellement
if (isset($_POST['start_server'])) {
    startServer();
    if (file_exists($lockFile)) {
        unlink($lockFile); // Supprimer le fichier de verrouillage
    }
    $logger->info('Le fichier de verrouillage a été supprimé.');
    echo "Le serveur a été démarré.";
}

// Arrêter le serveur manuellement
if (isset($_POST['stop_server'])) {
    stopServer();
    file_put_contents($lockFile, ''); // Créer le fichier de verrouillage
    $logger->info('Le serveur a été arrêté manuellement et le fichier de verrouillage créé.');
    echo "Le serveur a été arrêté manuellement.";
}
?>

<form method="post">
    <button name="start_server">Démarrer le serveur</button>
    <button name="stop_server">Arrêter le serveur</button>
</form>
