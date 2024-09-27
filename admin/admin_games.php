<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Parties en Cours</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Styles pour le plateau de jeu */
        #gameBoard {
            display: grid;
            grid-template-columns: repeat(10, 1fr); /* Change selon la taille du plateau */
            gap: 2px;
        }

        .cell {
            width: 40px;
            height: 40px;
            border: 1px solid #ccc;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: #f0f0f0;
            cursor: pointer;
        }

        .cell.revealed {
            background-color: #e0e0e0;
        }

        .cell.mine-triggered {
            background-color: red;
        }

        .cell-flagged {
            background-color: yellow;
        }

        .mine-number-1 { color: blue; }
        .mine-number-2 { color: green; }
        .mine-number-3 { color: red; }
        .mine-number-4 { color: darkblue; }
        .mine-number-5 { color: darkred; }
    </style>
</head>
<body class="bg-light">

<div class="container mt-5">
    <h1 class="text-center">Administration des Parties en Cours</h1>

    <div class="row mt-5">
        <div class="col-md-12">
            <h3>Liste des Parties en Cours</h3>
            <form>
                <label for="gameSelect">Sélectionnez une partie :</label>
                <select name="game" id="gameSelect" class="form-control" onchange="loadGameState()">
                    <option value="">-- Sélectionner une partie --</option>
                </select>
            </form>

            <div class="mt-4">
                <h4>État de la Partie</h4>
                <div id="gameBoard"></div> <!-- Div pour afficher le plateau -->
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script>
let socket;
let refreshIntervalId = null;

window.onload = function() {
    connectWebSocket(); // Connexion WebSocket
};

// Connexion WebSocket
function connectWebSocket() {
    socket = new WebSocket('wss://fozzy.fr:9443'); // Remplace par l'adresse de ton serveur WebSocket

    socket.onopen = function() {
        console.log('WebSocket connecté');
        requestGamesList(); // Récupérer la liste des parties en cours une fois connecté
    };

    socket.onmessage = function(event) {
        const data = JSON.parse(event.data);
        console.log('Message reçu du serveur:', data);

        // Traiter les messages reçus du serveur
        switch (data.type) {
            case 'active_games': // Réponse contenant les parties en cours
                updateGamesList(data.games); // Mise à jour de la selectbox avec les parties
                break;
            case 'update_board': // Réponse contenant l'état d'une partie spécifique
                displayGameBoard(data.board); // Affichage du plateau de la partie
                break;
            case 'spectator_join_success':
                console.log(data.message);
                break;
            case 'error':
                console.error('Erreur: ' + data.message);
                break;
        }
    };

    socket.onclose = function() {
        console.log('WebSocket déconnecté');
        stopAutoRefresh(); // Arrêter le rafraîchissement automatique si le WebSocket est fermé
    };
}

// Fonction pour demander la liste des parties en cours
function requestGamesList() {
    socket.send(JSON.stringify({ type: 'get_active_games' })); // Envoyer une demande de parties actives
}

// Fonction pour mettre à jour la liste des parties dans la selectbox
function updateGamesList(games) {
    const selectBox = document.getElementById('gameSelect');
    selectBox.innerHTML = '<option value="">-- Sélectionner une partie --</option>';

    games.forEach(game => {
        const option = document.createElement('option');
        option.value = game.gameId; // Utiliser l'ID de la partie comme valeur
        option.text = game.players.join(' vs '); // Afficher les joueurs
        selectBox.appendChild(option);
    });
}

// Fonction pour charger l'état de la partie sélectionnée et s'inscrire en tant que spectateur
function loadGameState() {
    const selectedGameId = document.getElementById('gameSelect').value;

    if (selectedGameId) {
        socket.send(JSON.stringify({ type: 'add_spectator', gameId: selectedGameId })); // S'inscrire en tant que spectateur

        // Si un intervalle de rafraîchissement existe déjà, on l'arrête avant d'en créer un nouveau
        if (refreshIntervalId !== null) {
            clearInterval(refreshIntervalId);
        }

        // Démarrer un rafraîchissement toutes les secondes
        refreshIntervalId = setInterval(() => {
            socket.send(JSON.stringify({ type: 'get_game_state', gameId: selectedGameId }));
        }, 1000); // Rafraîchit toutes les secondes
    } else {
        document.getElementById('gameBoard').textContent = 'Veuillez sélectionner une partie pour voir son état.';
        stopAutoRefresh(); // Arrêter le rafraîchissement si aucune partie n'est sélectionnée
    }
}

// Fonction pour afficher le plateau de jeu
function displayGameBoard(board) {
    const gameBoardDiv = document.getElementById('gameBoard');
    gameBoardDiv.innerHTML = ''; // Réinitialiser le plateau de jeu

    board.forEach((row, x) => {
        row.forEach((cell, y) => {
            const div = document.createElement('div');
            div.classList.add('cell');
            if (cell.revealed) {
                div.classList.add('revealed');
                if (cell.mine) {
                    div.textContent = '💣'; // Afficher une mine
                    if (cell.triggered) {
                        div.classList.add('mine-triggered'); // Marquer la mine qui a explosé
                    }
                } else if (cell.adjacentMines > 0) {
                    div.textContent = cell.adjacentMines; // Afficher le nombre de mines adjacentes
                    div.classList.add(`mine-number-${cell.adjacentMines}`);
                }
            } else if (cell.flagged) {
                div.classList.add('cell-flagged');
                div.textContent = '🚩'; // Afficher le drapeau
            }

            gameBoardDiv.appendChild(div);
        });
    });
}

// Fonction pour arrêter le rafraîchissement automatique
function stopAutoRefresh() {
    if (refreshIntervalId !== null) {
        clearInterval(refreshIntervalId);
        refreshIntervalId = null;
    }
}
</script>


</body>
</html>
