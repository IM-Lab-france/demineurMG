<?php

date_default_timezone_set('Europe/Paris');

require __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use Ratchet\Server\IoServer;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;

require 'db.php';


// Configuration du logger
$logger = new Logger('minesweeper_logger');

// Handler pour les fichiers tournants avec une taille maximale de 5Mo par fichier
$logFilePath = __DIR__ . '/logs/minesweeper.log'; // Chemin du fichier log
$rotatingHandler = new RotatingFileHandler($logFilePath, 0, Logger::DEBUG);
$rotatingHandler->setFilenameFormat('{filename}-{date}', 'Y-m-d'); // Optionnel : format du fichier

// Handler pour afficher dans le prompt/console
$consoleHandler = new StreamHandler('php://stdout', Logger::DEBUG);

// Ajout des deux handlers
$logger->pushHandler($rotatingHandler);
$logger->pushHandler($consoleHandler);



class MinesweeperServer implements MessageComponentInterface {
    protected $clients;
    protected $players;      // Liste des joueurs connectés
    protected $games;        // Liste des parties en cours

    protected $defaultSize = 10;
    protected $difficulty = 0.10;
    protected $defaultNbMines;
    protected $pendingInvitations = []; // Stocker les invitations en attente

    protected $logger; // Ajout d'une propriété pour le logger

    public function __construct($logger) {
        $this->clients = new \SplObjectStorage;
        $this->players = [];
        $this->games = [];
        $this->defaultNbMines = intval($this->defaultSize * $this->defaultSize * $this->difficulty);

        $this->logger = $logger; // Initialisation du logger
        $this->logger->info("MinesweeperServer started!");
        

    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        $this->logger->info("Nouvelle connexion ! ({$conn->resourceId})");
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $data = json_decode($msg, true);
        $this->logger->info("IN  :" . json_encode($data) );

        switch ($data['type']) {
            case 'register':
                $this->handleRegister($from, $data);
                break;
            
            case 'login':
                $this->handleLogin($from, $data);
                break;

            case 'invite':
                $this->handleInvite($from, $data);
                break;

            case 'accept_invite':
                $this->handleAcceptInvite($from, $data);
                break;

            case 'reveal_cell':
                $this->handleRevealCell($from, $data);
                break;

            case 'place_flag':
                $this->handlePlaceFlag($from, $data);
                break;

            case 'ready_for_new_game':
                $this->handleReadyForNewGame($from, $data);
                break;

            case 'logout':
                $this->handleLogout($from);
                break;

            case 'refresh_players':
                $this->sendConnectedPlayersList($from);
                break;
            case 'get_scores':
                $this->handleGetScores($from);
                break;
            case 'get_player_count':
                $this->handleGetPlayerCount($from);
                break;
        }
    }

    public function onClose(ConnectionInterface $from) {
        // Supprimer le joueur des clients connectés
        $this->clients->detach($from);
        $disconnectedPlayerId = $from->resourceId;

        $this->logger->info("Connexion fermée pour le joueur {$disconnectedPlayerId}");

        // Vérifier si le joueur déconnecté est en partie
        foreach ($this->games as $gameId => $game) {
            if (in_array($disconnectedPlayerId, $game['players'])) {
                // L'autre joueur dans la partie
                $otherPlayerId = $game['players'][0] === $disconnectedPlayerId ? $game['players'][1] : $game['players'][0];

                // Envoyer un message à l'autre joueur pour l'informer de la déconnexion
                $otherPlayerConnection = $this->getConnectionFromPlayerId($otherPlayerId);
                if ($otherPlayerConnection) {
                    $otherPlayerConnection->send(json_encode([
                        'type' => 'player_disconnected',
                        'message' => 'Votre adversaire s\'est déconnecté. La partie est annulée.'
                    ]));

                    $this->logger->info("OUT:" . json_encode([
                        'type' => 'player_disconnected',
                        'message' => 'Votre adversaire s\'est déconnecté. La partie est annulée.'
                    ]));
                }

                // Retirer une partie jouée à chaque joueur
                $this->decrementGamesPlayed($this->players[$disconnectedPlayerId]['id']);
                $this->decrementGamesPlayed($this->players[$otherPlayerId]['id']);

                // Supprimer la partie
                unset($this->games[$gameId]);
            }
        }

        // Supprimer le joueur de la liste des joueurs connectés
        unset($this->players[$disconnectedPlayerId]);

        // Envoyer la liste mise à jour des joueurs connectés à tous les autres clients
        $this->sendConnectedPlayersList($from);
    }

    public function onError(ConnectionInterface $from, \Exception $e) {
        $this->logger->error($e->getMessage() );
        $from->close();
    }

    protected function handleGetPlayerCount(ConnectionInterface $from) {
        $connectedPlayersCount = count($this->players);
        $gamesInProgress = count($this->games);
    
        $from->send(json_encode([
            'type' => 'player_count',
            'connectedPlayers' => $connectedPlayersCount,
            'gamesInProgress' => $gamesInProgress
        ]));
    
        $this->logger->info("OUT: " . json_encode([
            'type' => 'player_count',
            'connectedPlayers' => $connectedPlayersCount,
            'gamesInProgress' => $gamesInProgress
        ]));
    }

    protected function handleRegister(ConnectionInterface $from, $data) {
        $username = $data['username'];
        $password = $data['password']; // Mot de passe en clair reçu du client
    
        // Utilisation de la base de données pour vérifier si le nom d'utilisateur existe déjà
        $db = new Database();
        $stmt = $db->getPDO()->prepare("SELECT * FROM users WHERE username = :username");
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
        if ($existingUser) {
            // L'utilisateur avec ce login existe déjà
            $from->send(json_encode([
                'type' => 'register_failed',
                'message' => 'Nom d\'utilisateur déjà pris.'
            ]));
            $this->logger->error("OUT:" . json_encode([
                'type' => 'register_failed',
                'message' => 'Nom d\'utilisateur déjà pris.'
            ]));
        } else {
            // L'utilisateur n'existe pas encore, on peut procéder à l'enregistrement
    
            // Hacher le mot de passe avec bcrypt
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    
            // Insérer le nouvel utilisateur dans la base de données
            $stmt = $db->getPDO()->prepare("
                INSERT INTO users (username, password_hash, created_at)
                VALUES (:username, :password_hash, NOW())
            ");
            $stmt->bindParam(':username', $username);
            $stmt->bindParam(':password_hash', $passwordHash);
            $stmt->execute();
    
            // Confirmation de l'enregistrement
            $from->send(json_encode([
                'type' => 'register_success',
                'message' => 'Enregistrement réussi. Vous pouvez vous connecter.'
            ]));
            $this->logger->info("OUT:" . json_encode([
                'type' => 'register_success',
                'message' => 'Enregistrement réussi. Vous pouvez vous connecter.'
            ]));
            
        }
    }
    
    protected function handleLogin(ConnectionInterface $from, $data) {
        $username = $data['username'];
        $password = $data['password']; // Mot de passe en clair reçu du client
    
        // Vérifier si l'utilisateur est déjà connecté
        foreach ($this->players as $resourceId => $playerInfo) {
            if ($playerInfo['username'] === $username) {
                // L'utilisateur est déjà connecté
                $from->send(json_encode([
                    'type' => 'login_failed',
                    'message' => 'Cet utilisateur est déjà connecté.'
                ]));
                $this->logger->info("OUT:" . json_encode([
                    'type' => 'login_failed',
                    'message' => 'Cet utilisateur est déjà connecté.'
                ]));
                return; // Arrêter le traitement
            }
        }

        // Utilisation de la base de données pour récupérer les informations de l'utilisateur
        $db = new Database();
        $user = $db->getUserByUsername($username);
    
        // Vérifier si l'utilisateur existe et si le mot de passe correspond
        if ($user && password_verify($password, $user['password_hash'])) {
            // Connexion réussie
            $this->players[$from->resourceId] = [
                'id' => $user['id'], 
                'username' => $username];
    
            $from->send(json_encode([
                'type' => 'login_success',
                'playerId' => $user['id'],  // Envoi de l'ID du joueur
                'username' => $username,
                'players' => $this->getConnectedPlayers()
            ]));

            $this->logger->info("OUT:" . json_encode([
                'type' => 'login_success',
                'playerId' => $user['id'],  // Envoi de l'ID du joueur
                'username' => $username,
                'players' => $this->getConnectedPlayers()
            ]));
    
            // Envoyer la liste des joueurs connectés
            $this->sendConnectedPlayersList($from);
        } else {
            // Échec de la connexion
            $from->send(json_encode([
                'type' => 'login_failed',
                'message' => 'Login ou mot de passe incorrect.'
            ]));

            $this->logger->error("OUT:" . json_encode([
                'type' => 'login_failed',
                'message' => 'Login ou mot de passe incorrect.'
            ]));
        }
    }

    protected function handleLogout(ConnectionInterface $from) {
        // Si le joueur est déjà déconnecté ou introuvable
        if (!isset($this->players[$from->resourceId])) {
            return;
        }
    
        // Retirer le joueur de la liste des joueurs connectés
        unset($this->players[$from->resourceId]);
    
        // Informer les autres joueurs que la liste des joueurs disponibles a changé
        
        $this->sendConnectedPlayersList($from);  // Mettre à jour la liste des joueurs
        
    
        // Déconnecter la session du joueur
        $from->send(json_encode([
            'type' => 'logout_success',
            'message' => 'Déconnexion réussie'
        ]));

        $this->logger->info("OUT:" . json_encode([
            'type' => 'logout_success',
            'message' => 'Déconnexion réussie'
        ]));
        
        $this->logger->info("Joueur {$from->resourceId} déconnecté.");
    }
    
    protected function handleInvite(ConnectionInterface $from, $data) {
        $inviteeId = $data['invitee'];
        $fromUser = $this->players[$from->resourceId];

        foreach ($this->clients as $client) {
            if (isset($this->players[$client->resourceId]) && $this->players[$client->resourceId]['id'] === $inviteeId) {
                // Générer un numéro d'invitation unique
                $invitationId = uniqid('inv_');

                // Stocker les informations d'invitation (taille et difficulté)
                $this->pendingInvitations[$invitationId] = [
                    'inviter' => $from->resourceId,
                    'gridSize' => $data['gridSize'],
                    'difficulty' => $data['difficulty']
                ];

                // Envoyer l'invitation avec le numéro unique à l'invité
                $client->send(json_encode([
                    'type' => 'invite',
                    'inviter' => $fromUser['username'],
                    'invitationId' => $invitationId // Transmettre le numéro d'invitation
                ]));

                $this->logger->info("OUT:" . json_encode([
                    'type' => 'invite',
                    'inviter' => $fromUser['username'],
                    'invitationId' => $invitationId // Transmettre le numéro d'invitation
                ]));

                return;
            }
        }

        // Si l'invité n'est pas trouvé
        $from->send(json_encode([
            'type' => 'invite_failed',
            'message' => 'Joueur non trouvé'
        ]));

        $this->logger->error("OUT:" . json_encode([
            'type' => 'invite_failed',
            'message' => 'Joueur non trouvé'
        ]));
    }

    protected function handleAcceptInvite(ConnectionInterface $from, $data) {
        $inviterUsername = $data['inviter'];
        $invitationId = $data['invitationId']; // Récupérer l'invitationId

        // Vérifier si l'invitation existe dans les invitations en attente
        if (isset($this->pendingInvitations[$invitationId])) {
            $invitation = $this->pendingInvitations[$invitationId];
            $gridSize = $invitation['gridSize'];
            $difficulty = intval($invitation['difficulty']);

            // Récupérer les dimensions de la grille
            list($widthI, $heightI) = explode('x', $gridSize);

            $width = 10;
            $height = 10;

            //sécurisation du nombre de mine pour éviter de faire peter le serveur.
            if($widthI <= 100) { $width = $widthI; }
            if($heightI <= 100) { $height = $heightI; }

            // Calculer le nombre de mines
            $numMines = intval(($width * $height) * ($difficulty / 100));

            // Générer le plateau
            $board = $this->generateBoard($width, $height, $numMines);

            // Créer la partie
            $gameId = uniqid();
            $this->games[$gameId] = [
                'players' => [$from->resourceId, $this->pendingInvitations[$invitationId]['inviter']],
                'board' => $board,
                'currentTurn' => $from->resourceId
            ];

            // Appel à la fonction handleGameStart pour incrémenter le compteur de parties jouées
            $player1Id = $this->players[$from->resourceId]['id']; // ID du joueur qui a accepté l'invitation
            $player2Id = $this->players[$this->pendingInvitations[$invitationId]['inviter']]['id']; // ID du joueur qui a envoyé l'invitation
            $this->handleGameStart($player1Id, $player2Id);  // Incrémenter le compteur de parties jouées

            // Envoyer les informations de la partie aux deux joueurs
            foreach ($this->games[$gameId]['players'] as $playerId) {
                $connection = $this->getConnectionFromPlayerId($playerId);
                if ($connection) {
                    $connection->send(json_encode([
                        'type' => 'game_start',
                        'game_id' => $gameId,
                        'board' => $board,
                        'turn' => $this->games[$gameId]['currentTurn'],
                        'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username'],
                        'gridSize' => $gridSize,
                        'difficulty' => $difficulty
                    ]));

                    $this->logger->info("OUT:" . json_encode([
                        'type' => 'game_start',
                        'game_id' => $gameId,
                        'board' => $board,
                        'turn' => $this->games[$gameId]['currentTurn'],
                        'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username'],
                        'gridSize' => $gridSize,
                        'difficulty' => $difficulty
                    ]));
                }
            }

            // Supprimer l'invitation une fois acceptée
            unset($this->pendingInvitations[$invitationId]);

        } else {
            // Si l'invitation n'est pas trouvée
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Invitation introuvable ou expirée.'
            ]));

            $this->logger->error("OUT:" . json_encode([
                'type' => 'error',
                'message' => 'Invitation introuvable ou expirée.'
            ]));
        }
    }

    protected function handleGameStart($player1Id, $player2Id) {
        $db = new Database();
        
        // Incrémenter le compteur de parties jouées pour les deux joueurs
        $stmt = $db->getPDO()->prepare("UPDATE users SET games_played = games_played + 1 WHERE id = :id");
        
        $stmt->bindParam(':id', $player1Id);
        $stmt->execute();
        
        $stmt->bindParam(':id', $player2Id);
        $stmt->execute();
    }

    protected function handleGameOver($winnerId) {

        $db = new Database();
        
        // Incrémenter le compteur de victoires pour le gagnant
        $stmt = $db->getPDO()->prepare("UPDATE users SET games_won = games_won + 1 WHERE id = :id");
        
        $stmt->bindParam(':id', $winnerId);
        $stmt->execute();
    }

    // Fonction pour révéler les cellules adjacentes de manière récursive si elles ont 0 mines adjacentes
    private function revealAdjacentCells(&$board, $x, $y) {
        $directions = [
            [-1, -1], [-1, 0], [-1, 1], // Haut gauche, haut, haut droite
            [0, -1],         [0, 1],    // Gauche, droite
            [1, -1], [1, 0], [1, 1]     // Bas gauche, bas, bas droite
        ];

        foreach ($directions as $dir) {
            $newX = $x + $dir[0];
            $newY = $y + $dir[1];

            // Vérifiez si la cellule est dans les limites du plateau
            if (isset($board[$newX][$newY])) {
                $cell = $board[$newX][$newY];

                // Révéler la cellule si elle n'est pas encore révélée et n'est pas une mine
                if (!$cell['revealed'] && !$cell['mine']) {
                    $board[$newX][$newY]['revealed'] = true;

                    // Si la cellule adjacente a 0 mines, continuer la révélation en cascade
                    if ($cell['adjacentMines'] == 0) {
                        $this->revealAdjacentCells($board, $newX, $newY);
                    }
                }
            }
        }
    }

    protected function handleRevealCell(ConnectionInterface $from, $data) {
        $gameId = $data['game_id'];
        $x = $data['x'];
        $y = $data['y'];
    
        // Vérifier si le jeu existe
        if (!isset($this->games[$gameId])) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Jeu introuvable'
            ]));

            $this->logger->error("OUT:" . json_encode([
                'type' => 'error',
                'message' => 'Jeu introuvable'
            ]));
            return; 
        }
    
        // Vérifier si c'est bien le tour du joueur
        if ($this->games[$gameId]['currentTurn'] !== $from->resourceId) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Ce n\'est pas votre tour de jouer.'
            ]));

            $this->logger->info("OUT:" . json_encode([
                'type' => 'error',
                'message' => 'Ce n\'est pas votre tour de jouer.'
            ]));
            return;
        }
    
        // Vérifier si la cellule n'a pas encore été révélée
        if (!$this->games[$gameId]['board'][$x][$y]['revealed']) {
            // Révéler la cellule
            $this->games[$gameId]['board'][$x][$y]['revealed'] = true;
    
            // Si la cellule est une mine, terminer la partie
            if ($this->games[$gameId]['board'][$x][$y]['mine']) {
                $this->endGame($from, $gameId, $from->resourceId,['x' => $x, 'y' => $y]);
                return;
            }
    
            // Si la cellule ne contient pas de mines adjacentes, révéler en cascade les cellules adjacentes
            if ($this->games[$gameId]['board'][$x][$y]['adjacentMines'] == 0) {
                $this->revealAdjacentCells($this->games[$gameId]['board'], $x, $y);
            }
    
            
            if ($this->checkForDraw($gameId)) {
                // Si toutes les cases sans mines sont révélées, égalité
                foreach ($this->games[$gameId]['players'] as $playerId) {
                    $connection = $this->getConnectionFromPlayerId($playerId);
                    if ($connection) {
                        $connection->send(json_encode([
                            'type' => 'game_over',
                            'winner' => 'Egalité',
                            'board' => $this->games[$gameId]['board']
                        ]));

                        $this->logger->info("OUT:" . json_encode([
                            'type' => 'game_over',
                            'winner' => 'Egalité',
                            'board' => $this->games[$gameId]['board']
                        ]));
                    }
                }
                unset($this->games[$gameId]);
                return;
            }

            // Passer au prochain joueur
            $this->games[$gameId]['currentTurn'] = $this->getNextPlayer($gameId);
    
            // Envoyer la mise à jour du plateau à tous les joueurs
            foreach ($this->games[$gameId]['players'] as $playerId) {
                $connection = $this->getConnectionFromPlayerId($playerId);
                if ($connection) {
                    $connection->send(json_encode([
                        'type' => 'update_board',
                        'board' => $this->games[$gameId]['board'],
                        'turn' => $this->games[$gameId]['currentTurn'],
                        'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username']
                    ]));

                    $this->logger->info("OUT:" . json_encode([
                        'type' => 'update_board',
                        'board' => $this->games[$gameId]['board'],
                        'turn' => $this->games[$gameId]['currentTurn'],
                        'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username']
                    ]));
                }
            }
        }
    }

    protected function handlePlaceFlag(ConnectionInterface $from, $data) {
        $gameId = $data['game_id'];
        $x = $data['x'];
        $y = $data['y'];

        if (!isset($this->games[$gameId])) {
            $from->send(json_encode([
                'type' => 'error',
                'message' => 'Jeu introuvable'
            ]));

            $this->logger->error("OUT:" . json_encode([
                'type' => 'error',
                'message' => 'Jeu introuvable'
            ]));

            return;
        }

        $this->games[$gameId]['board'][$x][$y]['flagged'] = !$this->games[$gameId]['board'][$x][$y]['flagged'];

        foreach ($this->games[$gameId]['players'] as $playerId) {
            $connection = $this->getConnectionFromPlayerId($playerId);
            if ($connection) {
                $connection->send(json_encode([
                    'type' => 'update_board',
                    'board' => $this->games[$gameId]['board'],
                    'turn' => $this->games[$gameId]['currentTurn'],
                    'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username']
                ]));

                $this->logger->info("OUT:" . json_encode([
                    'type' => 'update_board',
                    'board' => $this->games[$gameId]['board'],
                    'turn' => $this->games[$gameId]['currentTurn'],
                    'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username']
                ]));
            }
        }
    }

    protected function handleReadyForNewGame(ConnectionInterface $from, $data) {
        $gameId = $data['game_id'];

        if (!isset($this->games[$gameId]['ready'])) {
            $this->games[$gameId]['ready'] = [];
        }

        $this->games[$gameId]['ready'][] = $from->resourceId;

        if (count($this->games[$gameId]['ready']) === 2) {
            $board = $this->generateBoard($this->defaultSize, $this->defaultSize, $this->defaultNbMines);
            $this->games[$gameId]['board'] = $board;
            $this->games[$gameId]['ready'] = [];
            $this->games[$gameId]['currentTurn'] = $this->games[$gameId]['players'][0]; // Réinitialisation du tour

            foreach ($this->games[$gameId]['players'] as $playerId) {
                $connection = $this->getConnectionFromPlayerId($playerId);
                if ($connection) {
                    $connection->send(json_encode([
                        'type' => 'new_game_start',
                        'game_id' => $gameId,
                        'board' => $board,
                        'turn' => $this->games[$gameId]['currentTurn'],
                        'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username']
                    ]));

                    $this->logger->info("OUT:" . json_encode([
                        'type' => 'new_game_start',
                        'game_id' => $gameId,
                        'board' => $board,
                        'turn' => $this->games[$gameId]['currentTurn'],
                        'currentPlayer' => $this->players[$this->games[$gameId]['currentTurn']]['username']
                    ]));
                }
            }
        }
    }

    protected function checkForDraw($gameId) {
        foreach ($this->games[$gameId]['board'] as $row) {
            foreach ($row as $cell) {
                if (!$cell['mine'] && !$cell['revealed']) {
                    return false; // Si une case non mine n'est pas révélée, pas d'égalité
                }
            }
        }
        return true; // Toutes les cases sans mines sont révélées
    }

    protected function endGame(ConnectionInterface $from, $gameId, $loserId, $losingCell) {
        
        if (!isset($this->games[$gameId])) return;

        $game = $this->games[$gameId];
        $winnerId = null;
        $dbWinnerID = null;

        // Déterminer le gagnant
        foreach ($game['players'] as $playerId) {
            if ($playerId !== $loserId) {
                $winnerId = $playerId;
                $dbWinnerID = $this->players[$playerId]['id'];
            }
        }

        // Révéler toutes les cellules du plateau
        $this->revealAllCells($this->games[$gameId]['board']);

        // Envoyer un message à chaque joueur
        foreach ($game['players'] as $playerId) {
            $connection = $this->getConnectionFromPlayerId($playerId);
            if ($connection) {

                $message = ($winnerId === $playerId) ? 'Vous avez gagné!' : 'Vous avez perdu!';

                // Mettre à jour les points dans la base si le joueur a gagné
                if ($winnerId === $playerId) {
                    $this->handleGameOver($this->players[$winnerId]['id']);
                }

                // Envoyer le plateau complet et le message de fin de partie
                $connection->send(json_encode([
                    'type' => 'game_over',
                    'winner' => $message,
                    'board' => $this->games[$gameId]['board'], // Envoyer le plateau complet
                    'losingCell' => $losingCell
                ]));

                $this->logger->info("OUT:" . json_encode([
                    'type' => 'game_over',
                    'winner' => $message,
                    'board' => $this->games[$gameId]['board'], // Envoyer le plateau complet
                    'losingCell' => $losingCell
                ]));
            }
        }

        // Envoyer la liste mise à jour des joueurs connectés
        $this->sendConnectedPlayersList($from);

        // Supprimer la partie
        unset($this->games[$gameId]);
    }

    protected function revealAllCells(&$board) {
        foreach ($board as &$row) {
            foreach ($row as &$cell) {
                $cell['revealed'] = true; // Révéler chaque cellule, qu'elle soit une mine ou non
            }
        }
    }
    
    // Fonction pour décrémenter le nombre de parties jouées
    protected function decrementGamesPlayed($playerId) {
        $db = new Database();
        $stmt = $db->getPDO()->prepare("UPDATE users SET games_played = games_played - 1 WHERE id = :id");
        $stmt->bindParam(':id', $playerId);
        $stmt->execute();
    }
    
    protected function sendConnectedPlayersList(ConnectionInterface $from) {
        $playersList = $this->getConnectedPlayers();
        foreach ($this->clients as $client) {
            $client->send(json_encode([
                'type' => 'connected_players',
                'playerId' => $from->resourceId,
                'players' => $playersList
            ]));

            $this->logger->info("OUT:" . json_encode([
                'type' => 'connected_players',
                'playerId' => $from->resourceId,
                'players' => $playersList
            ]));
        }
    }

    protected function getConnectionFromPlayerId($playerId) {
        foreach ($this->clients as $client) {
            if ($client->resourceId === $playerId) {
                return $client;
            }
        }
        return null;
    }

    protected function getConnectedPlayers() {
        $players = [];
        foreach ($this->players as $resourceId => $playerInfo) {
            $inGame = false;

            // Vérifier si le joueur est déjà dans une partie
            foreach ($this->games as $game) {
                if (in_array($resourceId, $game['players'])) {
                    $inGame = true;
                    break;
                }
            }

            // Si le joueur n'est pas en jeu, on l'ajoute à la liste
            if (!$inGame) {
                $players[] = [
                    'id' => $playerInfo['id'],
                    'username' => $playerInfo['username']
                ];
            }
        }
        return $players;
    }

    protected function getNextPlayer($gameId) {
        $game = $this->games[$gameId];
        $nextPlayer = ($game['currentTurn'] === $game['players'][0]) ? $game['players'][1] : $game['players'][0];
        $this->games[$gameId]['currentTurn'] = $nextPlayer;
        return $nextPlayer;
    }

    protected function generateBoard($width, $height, $numMines) {
        
        

        $board = [];

        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $board[$x][$y] = [
                    'mine' => false,
                    'revealed' => false,
                    'adjacentMines' => 0,
                    'flagged' => false
                ];
            }
        }

        $minesPlaced = 0;
        while ($minesPlaced < $numMines) {
            $randX = rand(0, $width - 1);
            $randY = rand(0, $height - 1);

            if (!$board[$randX][$randY]['mine']) {
                $board[$randX][$randY]['mine'] = true;
                $minesPlaced++;

                for ($i = -1; $i <= 1; $i++) {
                    for ($j = -1; $j <= 1; $j++) {
                        $newX = $randX + $i;
                        $newY = $randY + $j;
                        if ($newX >= 0 && $newX < $width && $newY >= 0 && $newY < $height) {
                            $board[$newX][$newY]['adjacentMines']++;
                        }
                    }
                }
            }
        }

        return $board;
    }

    protected function handleGetScores(ConnectionInterface $from) {
        // Récupérer les scores des joueurs depuis la base de données
        $db = new Database();
        $stmt = $db->getPDO()->prepare("
            SELECT username, games_won, games_played, 
            (games_won / NULLIF(games_played, 0)) * 100 AS win_percentage
            FROM users
            ORDER BY win_percentage DESC
        ");
        $stmt->execute();
        $scores = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        // Envoyer les scores au client WebSocket
        $from->send(json_encode([
            'type' => 'connected_players', // ou 'get_scores' selon le format que tu veux suivre
            'players' => $scores
        ]));

        $this->logger->info("OUT:" . json_encode([
            'type' => 'connected_players', // ou 'get_scores' selon le format que tu veux suivre
            'players' => $scores
        ]));
    }
}

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new MinesweeperServer($logger)
        )
    ),
    8080, '192.168.1.170'
);

$server->run();
