let socket;
let username;
let currentGameId;
let refreshInterval;
let connected = false;
let currentPlayerId;
let currentInvitationId = null;

// Fonction pour afficher les messages WebSocket dans la div messages
function logMessage(message) {
    const messageDiv = document.getElementById('messages');
    const messageElement = document.createElement('p');
    messageElement.textContent = message;
    messageDiv.appendChild(messageElement);
    console.log(message);
    // Faire défiler automatiquement vers le bas quand un nouveau message est ajouté
    messageDiv.scrollTop = messageDiv.scrollHeight;
}

// Connexion WebSocket
function connectWebSocket() {
    socket = new WebSocket('ws://192.168.1.170:8080');

    socket.onopen = function() {
        logMessage('WebSocket ouvert');
    };

    socket.onmessage = function(event) {
        const data = JSON.parse(event.data);
        logMessage('Message reçu du serveur: ' + JSON.stringify(data));

        switch (data.type) {
            case 'login_failed':
                // Afficher le message d'erreur et réinitialiser les inputs
                document.getElementById('loginError').textContent = 'Login ou mot de passe incorrect.';
                document.getElementById('username').value = '';
                document.getElementById('password').value = '';
                break;

            case 'login_success':
                currentPlayerId = data.playerId; 
                // Masquer la section de connexion et afficher la liste des joueurs
                document.getElementById('login').style.display = 'none';
                document.getElementById('game').style.display = 'block';
                document.getElementById('navbarUserDisplay').textContent = data.username;
                document.getElementById('navbar').style.display = 'block';
                refreshPlayersList(data.players);
                break;

            case 'connected_players':
                
                // Rafraîchir la liste des joueurs connectés
                refreshPlayersList(data.players);
                break;

            case 'game_start':

                // Stocker le game_id pour les futures actions
                document.getElementById('availableUser').style.display = 'none';
                document.getElementById('gameContainer').style.display = 'flex';
                currentGameId = data.game_id;
                displayGameBoard(data.board);

                // Afficher le joueur qui commence
                currentPlayerDisplay = document.getElementById('currentTurnDisplay');
                currentPlayerDisplay.textContent = 'C\'est à ' + data.currentPlayer + ' de commencer.'; // Afficher qui commence


                logMessage('Tour actuel: ' + data.turn);
                break;

            case 'update_board':
                displayGameBoard(data.board);
                // Mettre à jour le nom du joueur dont c'est le tour
                currentPlayerDisplay = document.getElementById('currentTurnDisplay');
                currentPlayerDisplay.textContent = 'Tour actuel: ' + data.currentPlayer; // Affiche le joueur actuel
                break;

            case 'invite':
                document.getElementById('inviter').textContent = data.inviter;
                document.getElementById('invitation').style.display = 'block';
                currentInvitationId = data.invitationId;
                break;

            case 'invite_declined':
                logMessage('Invitation refusée par le joueur.');
                break;

            case 'game_over':
                // Fin de partie et affichage du gagnant
                displayGameBoard(data.board);
                showWinnerModal(data.winner, data.game_id);
                break;
                // Ajout de la gestion de la déconnexion d'un joueur
            case 'player_disconnected':
                logMessage('Votre adversaire s\'est déconnecté. La partie est annulée.');
                showWinnerModal('Votre adversaire s\'est déconnecté. La partie est annulée.', currentGameId);
                break;
            case 'logout_success':
                handleLogoutSuccess(data);
                break;

            case 'error':
            if (data.message === "Ce n'est pas votre tour de jouer.") {
                    showNotYourTurnPopup();
                }
                logMessage('Erreur: ' + data.message);
                break;

            // Autres types de messages...
        }
    };

    socket.onclose = function() {
        logMessage('WebSocket fermé');

        location.reload();
    };
}

// Rafraîchir la liste des joueurs connectés
function refreshPlayersList(players) {
    const playersList = document.getElementById('players');
    playersList.innerHTML = '';
    const filteredPlayers = players.filter(player => player.id !== currentPlayerId);

    if (filteredPlayers.length === 0) {
        // Si aucun autre joueur en ligne, afficher le message
        const li = document.createElement('li');
        li.textContent = 'Aucun joueur en ligne';
        playersList.appendChild(li);
    } else {
        filteredPlayers.forEach(player => {
            const li = document.createElement('li');
            li.textContent = player.username;
            li.dataset.playerId = player.id;
            li.addEventListener('click', () => invitePlayer(player.id));
            playersList.appendChild(li);
        });
    }
} 

// Gestion des invitations
function invitePlayer(playerId) {
    // Afficher la popin
    const inviteModal = document.getElementById('inviteSettingsModal');
    inviteModal.style.display = 'block';

    // Gestion de la fermeture de la popin
    const closeBtn = document.getElementById('closeInviteSettings');
    closeBtn.onclick = function() {
        inviteModal.style.display = 'none';
    };

    // Gestion de l'envoi de l'invitation après sélection de la grille et difficulté
    const inviteForm = document.getElementById('inviteSettingsForm');
    inviteForm.onsubmit = function(e) {
        e.preventDefault(); // Empêche le rechargement de la page

        // Récupérer les choix de l'utilisateur
        const gridSize = document.getElementById('gridSize').value;
        const difficulty = document.getElementById('difficulty').value;

        // Envoyer l'invitation avec les paramètres choisis
        socket.send(JSON.stringify({
            type: 'invite',
            invitee: playerId,
            gridSize: gridSize,
            difficulty: difficulty
        }));

        // Masquer la popin après l'envoi
        inviteModal.style.display = 'none';

        console.log('Invitation envoyée à ' + playerId + ' avec une grille de ' + gridSize + ' et une difficulté de ' + difficulty + '%');
    };
}

function acceptInvite() {
    const inviter = document.getElementById('inviter').textContent;

    // Envoyer la réponse d'acceptation avec l'invitationId
    socket.send(JSON.stringify({
        type: 'accept_invite',
        inviter: inviter,
        invitationId: currentInvitationId // Utiliser la variable stockée
    }));

    // Masquer la popin d'invitation
    document.getElementById('invitation').style.display = 'none';
}

function declineInvite() {
    const inviter = document.getElementById('inviter').textContent;

    // Envoyer la réponse de refus avec l'invitationId
    socket.send(JSON.stringify({
        type: 'decline_invite',
        inviter: inviter,
        invitationId: currentInvitationId // Utiliser la variable stockée
    }));

    // Masquer la popin d'invitation
    document.getElementById('invitation').style.display = 'none';
}

// Afficher le plateau de jeu
function displayGameBoard(board) {
    const gameBoardDiv = document.getElementById('gameBoard');
    gameBoardDiv.innerHTML = ''; // Réinitialiser le plateau de jeu

    const table = document.createElement('table');
    board.forEach((row, x) => {
        const tr = document.createElement('tr');
        row.forEach((cell, y) => {
            const td = document.createElement('td');
            td.dataset.x = x;
            td.dataset.y = y;

            if (cell.revealed) {
                td.classList.add('revealed');
                td.textContent = cell.mine ? '💣' : (cell.adjacentMines || '');
            } else if (cell.flagged) {
                td.classList.add('flag');
                td.textContent = '🚩';
            }

            // Gestion des clics (révélation des cases)
            td.addEventListener('click', () => revealCell(x, y));
            // Clic droit pour placer ou retirer un drapeau
            td.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                placeFlag(x, y);
            });

            tr.appendChild(td);
        });
        table.appendChild(tr);
    });
    gameBoardDiv.appendChild(table);
}

// Fonction pour vider le plateau de jeu
function clearGameBoard() {
    const gameBoardDiv = document.getElementById('gameBoard');
    gameBoardDiv.innerHTML = '';  // Supprimer tout le contenu du plateau de jeu
    logMessage('Le plateau de jeu a été vidé.');
}

function showNotYourTurnPopup() {
    const popup = document.getElementById('notYourTurnPopup');
    
    // Ajouter la classe 'show' pour afficher la popin
    popup.classList.add('show');
    
    // Retirer la classe 'show' après 2 secondes pour la faire disparaître
    setTimeout(() => {
        popup.classList.remove('show');
    }, 2000); // Affiche la popin pendant 2 secondes
}

function handleLogoutSuccess(data) {
    if (data.username === username) {
        // Le joueur qui a initié la déconnexion doit être redirigé vers l'écran de connexion
        document.getElementById('game').style.display = 'none';
        document.getElementById('navbar').style.display = 'none';
        document.getElementById('login').style.display = 'block';
        logMessage('Vous avez été déconnecté.');
    } else {
        // Un autre joueur a été déconnecté, mettre à jour la liste des utilisateurs
        updatePlayerList(data.players);
        logMessage(data.username + ' a été déconnecté.');
    }
}

// Gestion des cellules
function revealCell(x, y) {
    if (currentGameId) {  // Vérifiez que le game_id est bien défini
        socket.send(JSON.stringify({
            type: 'reveal_cell',
            game_id: currentGameId,  // Utilisez le game_id stocké
            x: x,
            y: y
        }));
        logMessage('Cellule révélée: (' + x + ', ' + y + ')');
    } else {
        console.error('game_id manquant lors de la révélation de la cellule');
    }
}

function placeFlag(x, y) {
    if (currentGameId) {  // Vérifiez que le game_id est bien défini
        socket.send(JSON.stringify({
            type: 'place_flag',
            game_id: currentGameId,  // Utilisez le game_id stocké
            x: x,
            y: y
        }));
        logMessage('Drapeau posé: (' + x + ', ' + y + ')');
    } else {
        console.error('game_id manquant lors du placement du drapeau');
    }
}

// Afficher le modal du gagnant
function showWinnerModal(winnerMessage, gameId) {
    currentGameId = gameId;
    const modal = document.getElementById('winnerModal');
    const message = document.getElementById('winnerMessage');
    message.textContent = winnerMessage;
    modal.style.display = 'flex';
    
}

function hashPassword(password) {
    return CryptoJS.SHA256(password).toString(CryptoJS.enc.Hex); // Hachage en SHA-256 et conversion en hexadécimal
}

async function sendLogin(username, password) {
    const hashedPassword = hashPassword(password); // Crypter le mot de passe
    socket.send(JSON.stringify({
        type: 'login',
        username: username,
        password: hashedPassword // Envoi du mot de passe crypté
    }));
}

async function sendRegistern(username, password) {
    const hashedPassword = hashPassword(password); // Crypter le mot de passe
    socket.send(JSON.stringify({
        type: 'register',
        username: username,
        password: hashedPassword // Envoi du mot de passe crypté
    }));
}

// Fermer la modale du gagnant
document.getElementById('closeModalBtn').addEventListener('click', () => {
    document.getElementById('winnerModal').style.display = 'none';
    document.getElementById('availableUser').style.display = 'block';
    document.getElementById('gameContainer').style.display = 'none';
    clearGameBoard();
    socket.send(JSON.stringify({
        type: 'refresh_players',
        game_id: currentGameId
    }));
});

// Gestion des événements de connexion et déconnexion
document.getElementById('loginBtn').addEventListener('click', async () => {
    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    await sendLogin(username, password); // Envoyer les informations de login au serveur
    logMessage('Tentative de connexion pour ' + username);
    connected = true;
});

document.getElementById('registerBtn').addEventListener('click', async () => {
    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    await sendRegistern(username, password); // Envoyer les informations de login au serveur
    logMessage('Tentative de création de compte pour ' + username);
});


document.getElementById('logoutLink').addEventListener('click', () => {
    clearGameBoard(); 
    socket.send(JSON.stringify({ type: 'logout' }));
    document.getElementById('login').style.display = 'block';
    document.getElementById('game').style.display = 'none';
    document.getElementById('navbar').style.display = 'none';
    logMessage('Déconnexion de ' + username);
    connected = false;
});

document.getElementById('acceptInviteBtn').addEventListener('click', acceptInvite);
document.getElementById('declineInviteBtn').addEventListener('click', declineInvite);

// Démarrer la connexion WebSocket lors du chargement de la page
window.onload = function() {
    connectWebSocket();
};
