<?php
header('Content-Type: text/plain');

function getRecentLogs($filePath, $lines = 10) {
	echo $filePath;

    if (!file_exists($filePath)) {
        return ['error' => 'file_missing', 'message' => 'Le fichier de logs est introuvable.'];
    }

    $file = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($file === false) {
        return ['error' => 'file_unreadable', 'message' => 'Impossible de lire le fichier de logs.'];
    }

    return array_slice($file, -$lines);
}


// Vérifier si le script est lancé depuis la ligne de commande
if (php_sapi_name() == 'cli') {
    // Récupérer les arguments de la ligne de commande
    parse_str(implode('&', array_slice($argv, 1)), $_GET);
}

$logFile = isset($_GET['logfile']) ? $_GET['logfile'] : null;
echo $logFile;
if ($logFile) {
    $logs = getRecentLogs($logFile, 10);

    if (isset($logs['error'])) {
        echo $logs['message'];
    } else {
        foreach ($logs as $log) {
            echo htmlspecialchars($log) . "\n";
        }
    }
} else {
    echo "Aucun fichier de logs sélectionné.";
}
?>
