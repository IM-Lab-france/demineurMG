// admin.js

function updateIAStatus() {
    $.ajax({
        url: 'fetch_ia_status.php',
        method: 'GET',
        success: function(response) {
            let iaConfigs = JSON.parse(response);

            iaConfigs.forEach(function(ia) {
                // Trouver la ligne correspondant à l'IA
                let row = $('tr').filter(function () {
                    return $(this).find('td:first').text() === ia.name;
                });

                if (row.length > 0) {
                    row.find('td:nth-child(3)').text(ia.status);
                    row.find('td:nth-child(4)').text(ia.launch_status);
                    let actionButton = row.find('td:nth-child(5) button');

                    if (ia.status === 'idle') {
                        actionButton.removeClass('btn-danger').addClass('btn-success').text('Lancer').attr('onclick', `manageIA('start', '${ia.name}')`);
                    } else if (ia.status === 'running') {
                        actionButton.removeClass('btn-success').addClass('btn-danger').text('Arrêter').attr('onclick', `manageIA('stop', '${ia.name}')`);
                    } else if (ia.status === 'initializing') {
                        actionButton.removeClass('btn-success btn-danger').addClass('btn-warning').text('Initialisation en cours').attr('disabled', true);
                    } else if (ia.status === 'error') {
                        actionButton.removeClass('btn-success btn-danger').addClass('btn-dark').text('Erreur').attr('disabled', true);
                    }
                }
            });
        },
        error: function() {
            console.error("Erreur lors de la récupération de l'état des IA.");
        }
    });
}

// Rafraîchir l'état des IA toutes les 5 secondes
setInterval(updateIAStatus, 5000);

// Gestion des IA
function manageIA(action, iaName) {
    $.ajax({
        url: 'manage_ia.php',
        method: 'POST',
        data: { action: action, iaName: iaName },
        success: function(response) {
            let data = JSON.parse(response);

            if (data.message) {
                alert(data.message);
            }

            if (data.ia) {
                // Mettre à jour l'état de l'IA dynamiquement dans le tableau
                let row = $('tr').filter(function () {
                    return $(this).find('td:first').text() === data.ia.name;
                });

                if (row.length > 0) {
                    row.find('td:nth-child(3)').text(data.ia.status);
                    row.find('td:nth-child(4)').text(data.ia.launch_status);
                    let actionButton = row.find('td:nth-child(5) button');

                    if (data.ia.status === 'idle') {
                        actionButton.removeClass('btn-danger').addClass('btn-success').text('Lancer').attr('onclick', `manageIA('start', '${data.ia.name}')`);
                    } else if (data.ia.status === 'running') {
                        actionButton.removeClass('btn-success').addClass('btn-danger').text('Arrêter').attr('onclick', `manageIA('stop', '${data.ia.name}')`);
                    }
                }
            }
        },
        error: function() {
            alert("Une erreur s'est produite lors de la gestion de l'IA.");
        }
    });
}

function viewLogs(iaName) {
    $.ajax({
        url: 'fetch_logs_ia.php',
        method: 'GET',
        data: { iaName: iaName },
        success: function(data) {
            $('#logContent').text(data);
            $('#logModal').modal('show');
        }
    });
}

// Charger Google Charts
google.charts.load('current', {'packages':['corechart']});
google.charts.setOnLoadCallback(drawChart);

let chartData = [['Heure', 'CPU (%)', 'Mémoire (%)']]; // Initialisation avec les en-têtes
let chart;
let data;
let options = {
    title: 'Charge CPU et Mémoire',
    curveType: 'function',
    legend: { position: 'bottom' },
    vAxis: { viewWindow: { min: 0 } }
};
let refreshInterval = 10000; // Par défaut 10 secondes
let refreshIntervalId;
let socket = null;

function drawChart() {
    data = google.visualization.arrayToDataTable(chartData);

    chart = new google.visualization.LineChart(document.getElementById('cpuMemoryChart'));
    chart.draw(data, options);
}

// Fonction pour mettre à jour le graphique
function updateChart(cpu, memory) {
    const currentTime = new Date().toLocaleTimeString();

    // Ajouter les nouvelles données au tableau
    chartData.push([currentTime, parseFloat(cpu), parseFloat(memory)]);

    // Limiter à 20 points de données pour éviter que le graphique devienne trop large
    if (chartData.length > 20) {
        chartData.splice(1, 1); // Supprimer l'élément le plus ancien (après les en-têtes)
    }

    // Redessiner le graphique avec les nouvelles données
    data = google.visualization.arrayToDataTable(chartData);
    chart.draw(data, options);
}

// Fonction pour récupérer la charge CPU et mémoire et mettre à jour le graphique
function fetchServerUsageAndUpdateChart() {
    $.ajax({
        url: 'server_usage.php', // Chemin vers le fichier PHP qui renvoie les informations
        method: 'GET',
        success: function(data) {
            if (data.cpu !== 'N/A' && data.memory !== 'N/A') {
                // Mettre à jour le graphique avec les nouvelles valeurs
                updateChart(data.cpu, data.memory);
                $('#cpuUsage').text(data.cpu + '%');
                $('#memoryUsage').text(data.memory + '%');
            } else {
                $('#cpuUsage').text('Indisponible');
                $('#memoryUsage').text('Indisponible');
            }
        },
        error: function() {
            $('#cpuUsage').text('Erreur');
            $('#memoryUsage').text('Erreur');
        }
    });
}

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
        url: 'fetch_logs.php?logfile=' + encodeURIComponent($('#logfile').val()),
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
            showNotification('Erreur lors de l arrêt du serveur.', 'danger');
        }
    });
}

// Rafraîchir l'état du serveur et les logs au démarrage
$(document).ready(function() {
    connectWebSocket();
    setInterval(fetchServerUsageAndUpdateChart, 10000);

    // Mettre à jour le taux de rafraîchissement lorsque la selectbox change
    document.getElementById('refreshRate').addEventListener('change', updateRefreshRate);

    // Attacher les événements pour démarrer/arrêter le serveur
    document.getElementById('startServer').addEventListener('click', startServer);
    document.getElementById('stopServer').addEventListener('click', stopServer);
    updateIAStatus();
});