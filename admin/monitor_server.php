<?php

date_default_timezone_set('Europe/Paris');

require __DIR__ . '/../vendor/autoload.php';

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;

// Configuration du logger
$logger = new Logger('monitor_logger');

// Handler pour les fichiers tournants avec une taille maximale de 5Mo par fichier
$logFilePath = __DIR__ . '/../logs/monitor_server.log'; // Chemin du fichier log
$rotatingHandler = new RotatingFileHandler($logFilePath, 0, Logger::DEBUG);

// Handler pour afficher dans le prompt/console
$consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);

// Ajout des deux handlers au logger
$logger->pushHandler($rotatingHandler);
$logger->pushHandler($consoleHandler);

$lockFile = '/var/www/html/admin/locks/server.lock'; // Chemin correct du fichier de verrouillage

// Fonction pour vérifier si le processus server.php est actif
function isServerRunning() {
    global $logger;
    $output = [];
    exec("ps aux | grep '[/]server.php'", $output);
    
    return count($output) > 0;
}

// Fonction pour démarrer le serveur
function startServer($logger) {
    $logger->info("Démarrage du serveur...");
    exec('php /var/www/html/server.php > /dev/null &');
}

// Fonction pour envoyer un email en cas de redémarrage
function sendEmailNotification($logger) {
    $to = 'cedric.hourde@gmail.com';  // Remplace par ton adresse email
    $subject = 'Redémarrage du serveur détecté';
    $message = 'Le serveur a été redémarré automatiquement suite à une interruption.';
    $headers = 'From: no-reply@fozzy.fr';
    
    // Envoi de l'email
    if (mail($to, $subject, $message, $headers)) {
        $logger->info("Notification de redémarrage envoyée à $to.");
    } else {
        $logger->error("Échec de l'envoi de l'email de notification à $to.");
    }
}

// Vérification de l'état du serveur et du fichier de verrouillage
if (file_exists($lockFile)) {
    $logger->info("--------- Le fichier de verrouillage est présent. Le serveur a été arrêté manuellement.");
    echo "Le serveur a été arrêté manuellement. Aucune action n'est nécessaire.\n";
    exit; // Ne fait rien si le serveur est arrêté intentionnellement
}

if (!isServerRunning()) {
    $logger->warning("Le serveur n'est pas en cours d'exécution. Tentative de redémarrage...");
    startServer($logger);
    sendEmailNotification($logger);
    $logger->info("Le serveur a été redémarré avec succès.");
    echo "Le serveur a été redémarré.\n";
} else {
    $logger->info("Le serveur fonctionne correctement.");
    echo "Le serveur fonctionne correctement.\n";
}
?>
