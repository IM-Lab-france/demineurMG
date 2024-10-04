<?php

// admin/admin.php

if ($_SERVER['REMOTE_ADDR'] === '192.168.1.170') {
    // Afficher les erreurs pour les connexions locales
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    // Ne pas afficher les erreurs pour les connexions externes
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

date_default_timezone_set('Europe/Paris');

require __DIR__ . '/../vendor/autoload.php';  // Assurez-vous d'inclure Monolog via Composer

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;

// Initialiser Monolog
$logger = new Logger('monitor_server');

// Définir le répertoire des fichiers de log
$logDirPath = __DIR__ . '/../logs/';

// gestion des IA
$iaConfigFile = '/var/www/html/ia/deminium/ia_config.json';
$iaConfigs = json_decode(file_get_contents($iaConfigFile), true);

// Fonction pour obtenir le fichier de log le plus récent
function getLatestLogFile($logDirPath) {
    $files = glob($logDirPath . '*.log');
    if (empty($files)) {
        return null;
    }
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a); // Trier par date de modification décroissante
    });
    return $files[0]; // Retourner le fichier le plus récent
}

// Fonction pour récupérer les logs les plus récents
function getRecentLogs($filePath, $lines = 10) {
    if (!file_exists($filePath)) {
        return ['error' => 'file_missing', 'message' => 'Le fichier de logs est introuvable.'];
    }

    $file = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES); // Lire les lignes sans les sauts de ligne
    if ($file === false) {
        return ['error' => 'file_unreadable', 'message' => 'Impossible de lire le fichier de logs. Problème d accès ou de permission.'];
    }

    return array_slice($file, -$lines); // Récupérer les dernières lignes
}

// Fonction pour récupérer les fichiers de logs disponibles
function getLogFiles($logDirPath) {
    return glob($logDirPath . '*.log');
}

// Fonction pour obtenir la mémoire et la charge CPU de server.php
function getServerResourceUsage() {
    $output = [];
    // Exécuter la commande shell pour obtenir les informations sur le processus server.php
    exec("ps -C server.php -o %cpu,%mem --no-headers", $output);

    if (!empty($output)) {
        // Séparer la charge CPU et la mémoire
        list($cpuUsage, $memoryUsage) = preg_split('/\s+/', trim($output[0]));
        return [
            'cpu' => $cpuUsage,
            'memory' => $memoryUsage
        ];
    }
    return [
        'cpu' => 'N/A',
        'memory' => 'N/A'
    ];
}

// Récupérer les informations de mémoire et CPU de server.php
$serverUsage = getServerResourceUsage();

// Récupérer le fichier de log à afficher
$currentLogFile = isset($_GET['logfile']) ? $_GET['logfile'] : getLatestLogFile($logDirPath);
$recentLogs = $currentLogFile ? getRecentLogs($currentLogFile, 10) : ['error' => 'file_missing', 'message' => 'Aucun fichier de logs disponible.'];

// Récupérer la liste des fichiers de logs
$logFiles = getLogFiles($logDirPath);

?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Gestion du Serveur</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-5">
    <h1 class="text-center">Administration du Serveur</h1>

    <div class="row">
        <div class="col-md-6">
            <h3>État du Serveur</h3>
            <div id="serverStatus" class="alert alert-secondary">
                <h4>Vérification en cours...</h4>
                <p>Nombre de joueurs connectés : <strong id="connectedPlayers">--</strong></p>
                <p>Nombre de partie en cours   : <strong id="playersInGame">--</strong></p>
                <p>Mémoire utilisée par server.php : <strong id="memoryUsage">--</strong></p>
                <p>Charge CPU utilisée par server.php : <strong id="cpuUsage">--</strong></p>
            </div>
        </div>
        <div class="row mt-5">
            <div class="col-md-12">
                <h3>Graphique de la charge CPU et mémoire</h3>
                <div id="cpuMemoryChart" style="width: 100%; height: 500px;"></div>
            </div>
        </div>
        <div class="col-md-6 text-center">
            <h3>Actions</h3>
            <div class="d-flex justify-content-around">
                <button id="startServer" class="btn btn-success">Démarrer le serveur</button>
                <button id="stopServer" class="btn btn-danger">Arrêter le serveur</button>
            </div>
        </div>
    </div>
    <div class="container mt-5">
        <h3>Gestion des IA</h3>
        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Nom de l'IA</th>
                    <th>Modèle</th>
                    <th>Statut</th>
                    <th>État de lancement</th>
                    <th>Actions</th>
                    <th>Logs</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($iaConfigs as $ia): ?>
                    <tr>
                        <td><?= htmlspecialchars($ia['name']) ?></td>
                        <td><?= htmlspecialchars($ia['model']) ?></td>
                        <td><?= htmlspecialchars($ia['status']) ?></td>
                        <td><?= htmlspecialchars($ia['launch_status']) ?></td>
                        <td>
                            <?php if ($ia['status'] === 'idle'): ?>
                                <button onclick="manageIA('start', '<?= $ia['name'] ?>')" class="btn btn-success">Lancer</button>
                            <?php elseif ($ia['status'] === 'running'): ?>
                                <button onclick="manageIA('stop', '<?= $ia['name'] ?>')" class="btn btn-danger">Arrêter</button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button onclick="viewLogs('<?= $ia['name'] ?>')" class="btn btn-info">Voir les logs</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div id="logModal" class="modal fade" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Logs de l'IA</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <pre id="logContent" class="bg-dark text-light p-3" style="max-height: 400px; overflow-y: auto;"></pre>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Fermer</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row mt-5">
        <div class="col-md-12">
            <h3>Derniers Logs</h3>

            <form method="get" class="mb-3">
                <label for="logfile">Sélectionner un fichier de log :</label>
                <select name="logfile" id="logfile" class="form-control" onchange="this.form.submit()">
                    <?php foreach ($logFiles as $logFile): ?>
                        <option value="<?php echo htmlspecialchars($logFile); ?>" <?php if ($logFile === $currentLogFile) echo 'selected'; ?>>
                            <?php echo basename($logFile); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div class="card">
                <div class="card-body">
                    <pre id="logContent" class="bg-dark text-light p-3" style="max-height: 300px; overflow-y: auto;">
                        <?php if (isset($recentLogs['error'])): ?>
                            <p class="text-warning"><?php echo htmlspecialchars($recentLogs['message']); ?></p>
                        <?php else: ?>
                            <?php foreach ($recentLogs as $log): ?>
                                <?php echo htmlspecialchars($log) . "\n"; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </pre>
                </div>
            </div>

            <!-- Selectbox pour le taux de rafraîchissement -->
            <div class="mt-3">
                <label for="refreshRate">Taux de rafraîchissement :</label>
                <select id="refreshRate" class="form-control">
                    <option value="1000">Toutes les secondes</option>
                    <option value="5000">Toutes les 5 secondes</option>
                    <option value="10000" selected>Toutes les 10 secondes</option>
                    <option value="60000">Toutes les minutes</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Popin de notification -->
    <div id="notificationPopin" class="alert alert-info fade-out" style="display: none; position: fixed; top: 20px; right: 20px; z-index: 1000;">
        <strong id="notificationMessage"></strong>
    </div>

</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="admin.js"></script>
</body>
</html>