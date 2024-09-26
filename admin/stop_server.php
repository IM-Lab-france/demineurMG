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

// Fonction pour arrêter le serveur
function stopServer($logger, $lockFile) {
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
            echo json_encode(['status' => 'success', 'message' => 'Le serveur a été arrêté.']);
        } else {
            $logger->warning('Certains processus server.php sont toujours actifs après tentative de kill.', ['remaining_processes' => $remainingProcesses]);
            echo json_encode(['status' => 'partial_success', 'message' => 'Certains processus sont toujours actifs.']);
        }
    } else {
        $logger->info("Aucun processus 'server.php' trouvé.");
        echo json_encode(['status' => 'error', 'message' => 'Aucun processus server.php trouvé.']);
    }

    // Créer le fichier de verrouillage pour indiquer que le serveur est arrêté
    file_put_contents($lockFile, '');
}

// Arrêter le serveur
stopServer($logger, $lockFile);
