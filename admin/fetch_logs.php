<?php
header('Content-Type: text/plain');

function getRecentLogs($filePath, $lines = 10) {
    if (!file_exists($filePath)) {
        return ['error' => 'file_missing', 'message' => 'Le fichier de logs est introuvable.'];
    }

    $file = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($file === false) {
        return ['error' => 'file_unreadable', 'message' => 'Impossible de lire le fichier de logs.'];
    }

    return array_slice($file, -$lines);
}

$logFile = isset($_GET['logfile']) ? $_GET['logfile'] : null;

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