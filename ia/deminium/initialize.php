<?php
header('Content-Type: application/json');

// initialize.php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $iaName = $_POST['iaName'];
    $pluginsDir = '/var/www/html/ia/deminium/plugins';
    $iaPath = $pluginsDir . '/' . $iaName;
    $envPath = $iaPath . '/env';
    $logDir = $iaPath . '/logs';

    // Vérifier si l'IA existe
    if (!is_dir($iaPath)) {
        echo json_encode(['success' => false, 'message' => 'IA non trouvée.']);
        exit;
    }

    // Créer le dossier des logs s'il n'existe pas
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }

    $logFile = $logDir . '/initialize.log';

    // Créer l'environnement virtuel
    $command = "python3 -m venv $envPath 2>&1";
    exec($command, $output, $returnVar);
    file_put_contents($logFile, implode("\n", $output), FILE_APPEND);

    if ($returnVar !== 0) {
        echo json_encode(['success' => false, 'message' => 'Échec de la création de l\'environnement virtuel.']);
        exit;
    }

    // Installer les dépendances
    $requirementsFile = $iaPath . '/requirements.txt';
    if (file_exists($requirementsFile)) {
        $command = "$envPath/bin/pip install -r $requirementsFile 2>&1";
        exec($command, $output, $returnVar);
        file_put_contents($logFile, implode("\n", $output), FILE_APPEND);

        if ($returnVar !== 0) {
            echo json_encode(['success' => false, 'message' => 'Échec de l\'installation des dépendances.']);
            exit;
        }
    }

    // Après l'installation des dépendances
    // Vérifier si l'installation a réussi
    $command = "$envPath/bin/pip check 2>&1";
    exec($command, $output, $returnVar);
    file_put_contents($logFile, implode("\n", $output), FILE_APPEND);

    if ($returnVar !== 0) {
        echo json_encode(['success' => false, 'message' => 'Des dépendances sont manquantes ou incompatibles.']);
        exit;
    }

    // Si tout s'est bien passé, afficher un message de succès
    echo json_encode(['success' => true, 'message' => 'Dépendances installées avec succès.']);
}
