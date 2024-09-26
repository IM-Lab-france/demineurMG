let socket;
let username;
let currentGameId;
let refreshInterval;
let connected = false;
let currentPlayerId;
let currentInvitationId = null;
let errorDiv = null;
let retryInterval = 5000;

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
        connected = true;
        hideConnectionError(); // Masquer l'erreur s'il y en a une
        showLoginForm(); // Réafficher le formulaire de login
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
                document.getElementById('welcomeMessage').style.display = 'block';
                document.getElementById('logoutLink').style.display = 'block';
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
            
                // Révéler toutes les cellules (mines et chiffres)
                revealAllCells(data.board);

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

           
        }
    };

    // Gestion de la fermeture ou de l'erreur de connexion WebSocket
    socket.onerror = function() {
        logMessage('Impossible de se connecter au serveur WebSocket.');
        showConnectionError();
        attemptReconnect(); // Essayer de se reconnecter
    };

    socket.onclose = function() {
        logMessage('Connexion WebSocket fermée.');
        connected = false; // Indiquer que le client est déconnecté
        showConnectionError();
        attemptReconnect(); // Essayer de se reconnecter
    };
}

// Fonction pour essayer de se reconnecter régulièrement si déconnecté
function attemptReconnect() {
    if (!connected) { // Vérifier si le client est déconnecté avant d'essayer de se reconnecter
        setTimeout(function() {
            logMessage('Tentative de reconnexion au serveur...');
            connectWebSocket(); // Tente une reconnexion
        }, retryInterval);
    }
}


// Fonction pour afficher un message d'erreur et masquer le formulaire de login
function showConnectionError() {
    document.getElementById('login').style.display = 'none'; // Masquer le formulaire de connexion
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.classList.add('connection-error', 'text-center'); // Utiliser une classe CSS pour styliser le message
        errorDiv.innerHTML = `
            <h2>Oops!</h2>
            <p>On dirait que notre serveur a pris une petite pause café ☕, mais ne vous inquiétez pas, il revient bientôt !</p>
            <p>Nous essayons de le réveiller...</p>
        `;
        const container = document.createElement('div');
        container.id = 'errorContainer'; // Ajouter un ID pour faciliter la suppression
        container.classList.add('container', 'd-flex', 'justify-content-center', 'align-items-center', 'vh-100');
        container.appendChild(errorDiv);
        document.body.appendChild(container); // Afficher le message d'erreur
    }
}

// Fonction pour masquer le message d'erreur de connexion
function hideConnectionError() {
    if (errorDiv) {
        const container = document.getElementById('errorContainer');
        if (container) {
            container.remove(); // Supprimer le conteneur
        }
        errorDiv = null; // Réinitialiser la référence de l'erreur
    }
}

// Fonction pour réafficher le formulaire de login
function showLoginForm() {
    document.getElementById('login').style.display = 'block'; // Afficher le formulaire de connexion
}

function revealAllCells(board) {
    board.forEach((row, x) => {
        row.forEach((cell, y) => {
            cell.revealed = true; // Marquer chaque cellule comme révélée
        });
    });

    // Après avoir marqué toutes les cellules comme révélées, on réaffiche le plateau
    displayGameBoard(board);
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
                if (cell.mine) {
                    td.textContent = '💣'; // Afficher la mine
                } else if (cell.adjacentMines > 0) {
                    td.textContent = cell.adjacentMines; // Afficher le nombre de mines adjacentes
                    
                    // Ajouter une classe pour la couleur du nombre de mines
                    td.classList.add(`mine-number-${cell.adjacentMines}`);
                }
            } else if (cell.flagged) {
                td.classList.add('flag');
                td.textContent = '🚩'; // Afficher le drapeau
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
    document.getElementById('welcomeMessage').style.display = 'none';
    document.getElementById('logoutLink').style.display = 'none';
    document.getElementById('login').style.display = 'block';
    document.getElementById('game').style.display = 'none';
    document.getElementById('navbar').style.display = 'none';
    logMessage('Déconnexion de ' + username);
    connected = false;
});

document.getElementById('acceptInviteBtn').addEventListener('click', acceptInvite);
document.getElementById('declineInviteBtn').addEventListener('click', declineInvite);



// Sélectionner les éléments du menu burger et des liens
const navbarToggler = document.querySelector('.navbar-toggler');
const navbarCollapse = document.querySelector('.navbar-collapse');
const navLinks = document.querySelectorAll('.nav-link');

// Fonction pour masquer le menu burger
function hideMenu() {
    if (navbarCollapse.classList.contains('show')) {
        navbarToggler.click();  // Simule un clic sur le bouton du burger pour fermer le menu
    }
}

// Cacher le menu burger lorsque l'on clique sur un lien de navigation
navLinks.forEach(link => {
    link.addEventListener('click', hideMenu);
});

// Cacher le menu burger lorsque l'on perd le focus
navbarToggler.addEventListener('blur', hideMenu);


// Démarrer la connexion WebSocket lors du chargement de la page
window.onload = function() {
    connectWebSocket();
};
