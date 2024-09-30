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
        return ['error' => 'file_unreadable', 'message' => 'Impossible de lire le fichier de logs. Problème d\'accès ou de permission.'];
    }

    return array_slice($file, -$lines); // Récupérer les dernières lignes
}

// Fonction pour récupérer les fichiers de logs disponibles
function getLogFiles($logDirPath) {
    return glob($logDirPath . '*.log');
}

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
    <style>
        /* Ajout pour la disparition progressive des popins */
        .fade-out {
            opacity: 1;
            transition: opacity 2s ease-out;
        }
        .fade-out.fade {
            opacity: 0;
        }
    </style>
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
<script>
    let refreshInterval = 10000; // Par défaut 10 secondes
    let refreshIntervalId;
    let socket = null;

    function connectWebSocket() {
        showNotification('Tentative de connexion au WebSocket...', 'info'); // Message indiquant la tentative de connexion
        socket = new WebSocket('wss://fozzy.fr:9443'); // Remplacez l'adresse par celle de votre serveur

        socket.onopen = function() {
            showNotification('Connexion WebSocket réussie.', 'success');
            console.log('WebSocket connecté');
            socket.send(JSON.stringify({ type: 'get_player_count' }));
            startAutoRefresh();
        };

        socket.onmessage = function(event) {
            const data = JSON.parse(event.data);
            if (data.type === 'player_count') {
                updatePlayerCount(data.connectedPlayers, data.gamesInProgress || 0);
            }
        };

        socket.onerror = function() {
            showNotification('Erreur de connexion WebSocket. Le serveur est peut-être hors ligne.', 'danger');
            updateServerStatus('offline');
        };

        socket.onclose = function() {
            showNotification('WebSocket déconnecté. Tentative de reconnexion...', 'warning');
            updateServerStatus('offline');
            setTimeout(connectWebSocket, 5000); // Réessayer après 5 secondes
        };
    }

    function updatePlayerCount(connectedPlayers, playersInGame) {
        const playersCount = document.getElementById('connectedPlayers');
        const playersInGameCount = document.getElementById('playersInGame');
        
        playersCount.textContent = connectedPlayers;
        playersInGameCount.textContent = playersInGame;
        updateServerStatus('online');
    }

    function updateServerStatus(status) {
        const serverStatusDiv = document.getElementById('serverStatus');
        if (status === 'online') {
            serverStatusDiv.className = 'alert alert-success';
            serverStatusDiv.querySelector('h4').textContent = 'En ligne';
        } else {
            serverStatusDiv.className = 'alert alert-danger';
            serverStatusDiv.querySelector('h4').textContent = 'Hors ligne';
        }
    }

    function refreshLogs() {
        console.log("refreshLogs");
        $.ajax({
            url: './fetch_logs.php?logfile=<?php echo urlencode($currentLogFile); ?>',
            method: 'GET',
            success: function(data) {
                const logContent = document.getElementById('logContent');
                logContent.innerHTML = data;
            }
        });
    }

    function requestPlayerCount() {
        console.log("requestPlayerCount");
        if (socket && socket.readyState === WebSocket.OPEN) {
            socket.send(JSON.stringify({ type: 'get_player_count' }));
        }
    }

    function startAutoRefresh() {
        console.log("startAutoRefresh");
        refreshIntervalId = setInterval(() => {
            requestPlayerCount();
            refreshLogs();
        }, refreshInterval);
    }

    function updateRefreshRate() {
        console.log("updateRefreshRate");
        clearInterval(refreshIntervalId);
        refreshInterval = parseInt(document.getElementById('refreshRate').value);
        startAutoRefresh(); // Redémarrer l'intervalle avec le nouveau délai
    }

    // Afficher une popin de notification qui disparaît progressivement
    function showNotification(message, type = 'info') {
        const notificationPopin = document.getElementById('notificationPopin');
        notificationPopin.className = 'alert alert-' + type + ' fade-out'; // Définir la classe Bootstrap pour l'alerte
        document.getElementById('notificationMessage').textContent = message;
        notificationPopin.style.display = 'block';

        setTimeout(() => {
            notificationPopin.classList.add('fade'); // Démarrer la disparition progressive
            setTimeout(() => {
                notificationPopin.style.display = 'none'; // Masquer après la disparition
            }, 2000);
        }, 2000);
    }

    // Appels AJAX pour démarrer ou arrêter le serveur sans recharger la page
    function startServer() {
        $.ajax({
            url: 'start_server.php',
            method: 'POST',
            success: function() {
                showNotification('Serveur démarré avec succès.', 'success');
                refreshLogs(); // Rafraîchir les logs après l'action
            },
            error: function() {
                showNotification('Erreur lors du démarrage du serveur.', 'danger');
            }
        });
    }

    function stopServer() {
        $.ajax({
            url: 'stop_server.php',
            method: 'POST',
            success: function() {
                showNotification('Serveur arrêté avec succès.', 'warning');
                refreshLogs(); // Rafraîchir les logs après l'action
            },
            error: function() {
                showNotification('Erreur lors de l\'arrêt du serveur.', 'danger');
            }
        });
    }

    // Rafraîchir l'état du serveur et les logs au démarrage
    $(document).ready(function() {
        connectWebSocket();
        

        // Mettre à jour le taux de rafraîchissement lorsque la selectbox change
        document.getElementById('refreshRate').addEventListener('change', updateRefreshRate);

        // Attacher les événements pour démarrer/arrêter le serveur
        document.getElementById('startServer').addEventListener('click', startServer);
        document.getElementById('stopServer').addEventListener('click', stopServer);
    });
</script>

</body>
</html>
