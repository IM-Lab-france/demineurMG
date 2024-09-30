<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Parties en Cours</title>
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css"> <!-- Lien vers le fichier CSS -->
</head>
<body class="bg-light">

<div class="container mt-5  transparent-bg">
    <h1 class="text-center">Spectateurs</h1>

    <div class="row mt-5">
        <div class="col-md-12">
            <h3>Liste des Parties en Cours</h3>
            <ul id="gamesList" class="list-group"></ul> <!-- La liste des parties -->

            <!-- Nouvelle div autour de l'état de la partie -->
            <div id="gameStateContainer" style="display:none;">
                <div class="mt-4">
                    <h4>État de la Partie</h4>
                    <div id="gameMessage" style="text-align: center; margin-bottom: 10px;"></div>
                    <div id="gameResult" style="display:none; margin-top: 20px;" class="alert alert-success"></div>
                    <div id="gameContainer">
                        <div id="currentTurnDisplay"></div> <!-- L'info sur le joueur qui doit jouer -->
                        <div id="plateau">
                            <div id="gameBoard"></div> <!-- Plateau de jeu -->
                        </div>
                    </div>
                    
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script>
let socket;
let refreshIntervalId = null;
let refreshGamesListIntervalId = null;
let firstMovePlayed = false; // Variable pour savoir si le premier coup a été joué

window.onload = function() {
    connectWebSocket(); // Connexion WebSocket
    startGamesListAutoRefresh(); // Démarrer le rafraîchissement automatique de la liste des parties
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
                displayGameBoard(data.board, data.currentPlayer); // Affichage du plateau de la partie
                break;
            case 'game_over': // Réponse de fin de partie avec le vainqueur
                displayWinner(data.winner,data.winner_name, data.board); // Afficher le vainqueur
                stopAutoRefresh(); // Arrêter le rafraîchissement une fois la partie terminée
                break;
            case 'spectator_join_success':
                createEmptyGrid(data.gridSize.width, data.gridSize.height); // Crée la grille vide
                document.getElementById('currentTurnDisplay').textContent = `C'est au tour de ${data.currentPlayer} de jouer.`;
                document.getElementById('gameMessage').textContent = `Partie en cours entre ${data.players.join(' vs ')}.`;
                break;
            case 'error':
                console.error('Erreur: ' + data.message);
                break;
        }
    };

    socket.onclose = function() {
        console.log('WebSocket déconnecté');
        stopAutoRefresh(); // Arrêter le rafraîchissement automatique si le WebSocket est fermé
        stopGamesListAutoRefresh(); // Arrêter aussi le rafraîchissement de la liste des parties
    };
}

function createEmptyGrid(width, height) {
    const gameBoardDiv = document.getElementById('gameBoard');
    const plateauDiv = document.getElementById('plateau');

    plateauDiv.style.display = 'block';
    
    gameBoardDiv.innerHTML = ''; // Réinitialiser le plateau de jeu

    const table = document.createElement('table');
    for (let x = 0; x < width; x++) {
        const tr = document.createElement('tr');
        for (let y = 0; y < height; y++) {
            const td = document.createElement('td');
            td.classList.add('cell');

            // Crée une cellule vide pour le spectateur
            const cellInner = document.createElement('div');
            cellInner.classList.add('cell-inner');
            const cellFront = document.createElement('div');
            cellFront.classList.add('cell-front');
            const cellBack = document.createElement('div');
            cellBack.classList.add('cell-back');

            cellInner.appendChild(cellFront);
            cellInner.appendChild(cellBack);
            td.appendChild(cellInner);
            tr.appendChild(td);
        }
        table.appendChild(tr);
    }
    gameBoardDiv.appendChild(table);
}

// Fonction pour demander la liste des parties en cours
function requestGamesList() {
    socket.send(JSON.stringify({ type: 'get_active_games' })); // Envoyer une demande de parties actives
}

// Fonction pour mettre à jour la liste des parties
function updateGamesList(games) {
    const gamesList = document.getElementById('gamesList');
    gamesList.innerHTML = ''; // Réinitialiser la liste

    if (games.length === 0) {
        // Afficher un message s'il n'y a pas de parties en cours
        const noGameItem = document.createElement('li');
        noGameItem.classList.add('list-group-item', 'text-center');
        noGameItem.textContent = 'Aucune partie en cours';
        gamesList.appendChild(noGameItem);
    } else {
        // Afficher les parties en cours
        games.forEach(game => {
            const listItem = document.createElement('li');
            listItem.classList.add('list-group-item');
            listItem.textContent = game.players.join(' vs '); // Afficher les joueurs
            listItem.setAttribute('data-game-id', game.gameId); // Associer l'ID de la partie à l'élément

            // Ajouter un événement pour charger l'état de la partie lorsqu'on clique sur l'élément
            listItem.addEventListener('click', function() {
                loadGameState(game.gameId); // Charger l'état de la partie sélectionnée
            });

            gamesList.appendChild(listItem); // Ajouter l'élément à la liste
        });
    }
}

// Fonction pour charger l'état de la partie sélectionnée et s'inscrire en tant que spectateur
function loadGameState(gameId) {
    const resultDiv = document.getElementById('gameResult');
    const gameMessageDiv = document.getElementById('gameMessage');
    const gameBoardDiv = document.getElementById('gameBoard');
    const gameStateContainer = document.getElementById('gameStateContainer'); // Récupère la div
    const gameContainerDiv = document.getElementById('gameContainer');

    if (gameId) {
        // Masquer le message de fin de partie pour commencer une nouvelle
        resultDiv.style.display = 'none';
        resultDiv.innerHTML = ''; // Réinitialiser le contenu du message

        // Afficher la div contenant l'état de la partie
        gameStateContainer.style.display = 'block';

        // Réinitialiser le plateau de jeu
        createEmptyGrid(10, 10); // Réinitialise avec une taille par défaut, sera mise à jour ensuite

        // Réinitialiser le message d'attente du premier coup
        gameMessageDiv.textContent = 'En attente du premier coup...';

        gameContainerDiv.style.display = 'block';

        // Réafficher le plateau de jeu
        gameBoardDiv.style.display = 'grid';

        firstMovePlayed = false; // Réinitialiser le statut du premier coup
        socket.send(JSON.stringify({ type: 'add_spectator', gameId: gameId })); // S'inscrire en tant que spectateur

        // Si un intervalle de rafraîchissement existe déjà, on l'arrête avant d'en créer un nouveau
        if (refreshIntervalId !== null) {
            clearInterval(refreshIntervalId);
        }

        // Démarrer un rafraîchissement toutes les secondes pour l'état de la partie sélectionnée
        refreshIntervalId = setInterval(() => {
            socket.send(JSON.stringify({ type: 'get_game_state', gameId: gameId }));
        }, 1000); // Rafraîchit toutes les secondes

        // Arrêter le rafraîchissement de la liste des parties
        stopGamesListAutoRefresh();
    }
}


// Fonction pour afficher le plateau de jeu
function displayGameBoard(board, currentPlayer) {
    const plateauDiv = document.getElementById('plateau');
    const gameBoardDiv = document.getElementById('gameBoard');
    const currentPlayerDisplay = document.getElementById('currentTurnDisplay');
    gameBoardDiv.innerHTML = ''; // Réinitialiser le plateau de jeu

    const table = document.createElement('table');
    board.forEach((row, x) => {
        const tr = document.createElement('tr');
        row.forEach((cell, y) => {
            const td = document.createElement('td');
            td.classList.add('cell');
            td.dataset.x = x;
            td.dataset.y = y;

            const cellInner = document.createElement('div');
            cellInner.classList.add('cell-inner');

            const cellFront = document.createElement('div');
            cellFront.classList.add('cell-front');

            const cellBack = document.createElement('div');
            cellBack.classList.add('cell-back');

            if (cell.revealed) {
                td.classList.add('revealed');
                if (cell.mine) {
                    cellBack.textContent = '💣'; // Afficher la mine
                    if (cell.triggered) {
                        cellBack.classList.add('mine-triggered'); // Marquer la mine qui a explosé
                    }
                } else if (cell.adjacentMines > 0) {
                    cellBack.textContent = cell.adjacentMines; // Afficher le nombre de mines adjacentes
                    cellBack.classList.add(`mine-number-${cell.adjacentMines}`);
                }
            } else {
                if (cell.flagged) {
                    td.classList.add('cell-flagged');
                    cellFront.textContent = '🚩'; // Afficher le drapeau
                }
            }

            cellInner.appendChild(cellFront);
            cellInner.appendChild(cellBack);
            td.appendChild(cellInner);
            tr.appendChild(td);
        });
        table.appendChild(tr);
    });
    gameBoardDiv.appendChild(table);

    // Mettre à jour l'affichage du joueur actuel
    currentPlayerDisplay.textContent = `C'est au tour de ${currentPlayer} de jouer.`;
    plateauDiv.style.display = 'block';
}

// Fonction pour afficher le vainqueur ou l'égalité
function displayWinner(winner, winner_name, board) {
    const gameBoardDiv = document.getElementById('plateau');
    const currentTurnDisplayDev = document.getElementById('currentTurnDisplay');
    const resultDiv = document.getElementById('gameResult');
    
    // Révéler toutes les cellules du plateau
    displayGameBoard(board, null); // Pas besoin de `currentPlayer`, la partie est finie

    // Afficher le résultat
    currentTurnDisplayDev.style.display = 'none'; 

    if (winner === 'Egalité') {
        resultDiv.innerHTML = `La partie se termine par une égalité !`;
    } else {
        resultDiv.innerHTML = `Le vainqueur est ${winner_name}`;
    }

    resultDiv.style.display = 'block'; // Affiche le div avec le résultat du vainqueur ou d'égalité
}

// Fonction pour arrêter le rafraîchissement automatique de l'état de la partie
function stopAutoRefresh() {
    if (refreshIntervalId !== null) {
        clearInterval(refreshIntervalId);
        refreshIntervalId = null;
    }
}

// Fonction pour démarrer le rafraîchissement automatique de la liste des parties
function startGamesListAutoRefresh() {
    if (refreshGamesListIntervalId === null) {
        refreshGamesListIntervalId = setInterval(() => {
            requestGamesList();
        }, 1000); // Rafraîchir la liste des parties toutes les secondes
    }
}

// Fonction pour arrêter le rafraîchissement automatique de la liste des parties
function stopGamesListAutoRefresh() {
    if (refreshGamesListIntervalId !== null) {
        clearInterval(refreshGamesListIntervalId);
        refreshGamesListIntervalId = null;
    }
}
</script>

</body>
</html>
