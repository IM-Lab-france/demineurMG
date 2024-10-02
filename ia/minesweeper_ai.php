<?php
// minesweeper_ai.php

require __DIR__ . '/../vendor/autoload.php';
use Ratchet\Client\WebSocket;
use Ratchet\Client\Connector;
use React\EventLoop\Factory;

$aiLevels = [
    'easy' => 0.7,  // 70% chance to make the correct move
    'medium' => 0.85, // 85% chance to make the correct move
    'hard' => 0.95  // 95% chance to make the correct move
];

// Load AI accounts
$iaAccounts = json_decode(file_get_contents('ia_accounts.json'), true);

$loop = Factory::create();
$connector = new Connector($loop);

// Variables de configuration
$inviteAutomatically = in_array('--invite', $argv);  // Si '--invite' est passé comme argument, l'IA invite elle-même
$pauseDuration = 1; // Pause par défaut en millisecondes
$invitedPlayerId = null; // Stocke l'ID du joueur invité pour réinviter plus tard

// Vérification des arguments de lancement pour la pause
foreach ($argv as $arg) {
    if (preg_match('/--pause=(\d+)/', $arg, $matches)) {
        $pauseDuration = (int)$matches[1];  // Extraire la durée de la pause
    }
}

// AI configuration
$selectedLevel = 'medium'; // Can be 'easy', 'medium', or 'hard'
$accuracy = $aiLevels[$selectedLevel]; // The probability that the AI makes the correct move

// Get an available AI account
$aiAccount = $iaAccounts[array_rand($iaAccounts)];
$username = $aiAccount['username'];
$password = $aiAccount['password'];

// Variables pour stocker l'état du jeu
$currentGameId = null;
$currentPlayerId = null;
$currentInvitationId = null;

$connector('ws://localhost:8080')
    ->then(function (WebSocket $conn) use ($loop, $username, $password, $accuracy, $inviteAutomatically, $pauseDuration, &$currentGameId, &$currentPlayerId, &$currentInvitationId, &$invitedPlayerId) {
        // Login to the server
        $conn->send(json_encode([
            'type' => 'login',
            'username' => $username,
            'password' => $password
        ]));

        $conn->on('message', function ($msg) use ($conn, $accuracy, $username, $inviteAutomatically, $pauseDuration, &$currentGameId, &$currentPlayerId, &$currentInvitationId, &$invitedPlayerId) {
            $data = json_decode($msg, true);

            switch ($data['type']) {
                case 'login_success':
                    echo "Logged in as AI: " . $data['username'] . PHP_EOL;
                    $currentPlayerId = $data['playerId']; // Stocke l'ID du joueur IA

                    // Si l'invitation automatique est activée, chercher un joueur à inviter
                    if ($inviteAutomatically) {
                        searchAndInvitePlayer($conn, $data['players'], $invitedPlayerId);
                    }
                    break;

                case 'invite':
                    // Automatically accept the invitation
                    echo "Invitation received from: " . $data['inviter'] . PHP_EOL;
                    $currentInvitationId = $data['invitationId']; // Stocker l'invitation
                    $conn->send(json_encode([
                        'type' => 'accept_invite',
                        'invitationId' => $currentInvitationId
                    ]));
                    break;

                case 'game_start':
                    // Game starts, store game_id and process the board like in update_board
                    echo "Game started!" . PHP_EOL;
                    $currentGameId = $data['game_id']; // Stocker l'ID de la partie
                    displayBoard($data['board']);
                    makeMove($conn, $data['board'], $currentGameId, $accuracy, $pauseDuration);
                    break;

                case 'update_board':
                    // Check if it's the AI's turn
                    if ($data['currentPlayer'] === $username) {
                        echo "It's my turn!" . PHP_EOL;
                        displayBoard($data['board']);
                        makeMove($conn, $data['board'], $currentGameId, $accuracy, $pauseDuration);
                    } else {
                        echo "Waiting for my turn..." . PHP_EOL;
                        displayBoard($data['board']);
                    }
                    break;

                case 'game_over':
                    echo "Game over. Winner: " . $data['winner'] . PHP_EOL;
                    displayBoard($data['board']);
                    
                    // Réinviter le même joueur après la fin de la partie
                    if ($inviteAutomatically && $invitedPlayerId !== null) {
                        echo "Reinviting player with ID: " . $invitedPlayerId . PHP_EOL;
                        invitePlayer($conn, $invitedPlayerId);
                    }
                    break;

                case 'player_disconnected':
                    echo "Opponent disconnected. The game is over." . PHP_EOL;
                    break;

                case 'connected_players':
                    // Si l'invitation automatique est activée, chercher un joueur à inviter
                    if ($inviteAutomatically) {
                        searchAndInvitePlayer($conn, $data['players'], $invitedPlayerId);
                    }
                    break;

                case 'error':
                    echo "Error: " . $data['message'] . PHP_EOL;
                    break;

                default:
                    echo "Unknown message type received: " . $data['type'] . PHP_EOL;
                    break;
            }
        });

        $conn->on('close', function ($code = null, $reason = null) use ($loop) {
            echo "Connection closed ({$code} - {$reason})\n";
            $loop->stop();
        });
    }, function ($e) use ($loop) {
        echo "Could not connect: {$e->getMessage()}\n";
        $loop->stop();
    });

$loop->run();

// Fonction pour afficher le plateau de jeu
function displayBoard($board) {
    $width = count($board);
    $height = count($board[0]);

    echo "Current board state:\n";
    for ($x = 0; $x < $width; $x++) {
        for ($y = 0; $y < $height; $y++) {
            $cell = $board[$x][$y];
            if ($cell['revealed']) {
                if (isset($cell['mine']) && $cell['mine']) {
                    echo "💣 "; // Mine
                } elseif ($cell['adjacentMines'] > 0) {
                    echo $cell['adjacentMines'] . " "; // Nombre de mines adjacentes
                } else {
                    echo "  "; // Case vide
                }
            } elseif ($cell['flagged']) {
                echo "🚩 "; // Drapeau
            } else {
                echo "⬜ "; // Case non révélée
            }
        }
        echo "\n";
    }
    echo "\n";
}

// Fonction pour rechercher un joueur dont le nom commence par 'ia_' et envoyer une invitation
function searchAndInvitePlayer($conn, $players, &$invitedPlayerId) {
    foreach ($players as $player) {
        if (strpos($player['username'], 'ia_') === 0 && $player['username'] !== $GLOBALS['username']) {
            echo "Inviting player: " . $player['username'] . PHP_EOL;
            // Envoyer l'invitation avec une grille 10x10 et difficulté facile (15%)
            invitePlayer($conn, $player['id']);
            $invitedPlayerId = $player['id']; // Stocker l'ID du joueur invité
            return; // Arrêter après avoir invité un joueur
        }
    }
    echo "No suitable 'ia_' player found to invite." . PHP_EOL;
}

// Fonction pour inviter un joueur
function invitePlayer($conn, $playerId) {
    $conn->send(json_encode([
        'type' => 'invite',
        'invitee' => $playerId,
        'gridSize' => '10x10',   // Taille par défaut de la grille
        'difficulty' => 15       // Difficulté par défaut
    ]));
}

// Fonction pour effectuer un coup basé sur l'état du plateau
function makeMove($conn, $board, $currentGameId, $accuracy, $pauseDuration) {
    $width = count($board);
    $height = count($board[0]);
    
    $safeMoves = [];
    $possibleMoves = [];

    // Collecter toutes les cases non révélées et analyser les cases révélées avec des mines adjacentes
    for ($x = 0; $x < $width; $x++) {
        for ($y = $y = 0; $y < $height; $y++) {
            if (!$board[$x][$y]['revealed'] && !$board[$x][$y]['flagged']) {
                // Case non révélée et non marquée comme drapeau
                $possibleMoves[] = ['x' => $x, 'y' => $y];
            } elseif ($board[$x][$y]['revealed'] && isset($board[$x][$y]['adjacentMines']) && $board[$x][$y]['adjacentMines'] > 0) {
                // Case révélée avec des mines adjacentes, analyser les voisins
                $neighbors = getNeighbors($x, $y, $width, $height);
                $unrevealedNeighbors = [];
                $flaggedNeighbors = 0;

                foreach ($neighbors as $neighbor) {
                    if (!$board[$neighbor['x']][$neighbor['y']]['revealed']) {
                        if ($board[$neighbor['x']][$neighbor['y']]['flagged']) {
                            $flaggedNeighbors++;
                        } else {
                            $unrevealedNeighbors[] = $neighbor;
                        }
                    }
                }

                // Si toutes les mines adjacentes sont marquées, les autres voisins sont sûrs
                if ($flaggedNeighbors == $board[$x][$y]['adjacentMines']) {
                    $safeMoves = array_merge($safeMoves, $unrevealedNeighbors);
                }
            }
        }
    }

    // Décider quel coup jouer
    if (!empty($safeMoves)) {
        // Jouer un coup sûr
        $selectedMove = $safeMoves[array_rand($safeMoves)];
        echo "Safe move found at ({$selectedMove['x']}, {$selectedMove['y']})\n";
    } else {
        // Si aucun coup sûr, choisir au hasard
        $selectedMove = $possibleMoves[array_rand($possibleMoves)];
        echo "Random move chosen at ({$selectedMove['x']}, {$selectedMove['y']})\n";
    }

    // Pause avant d'envoyer le coup
    usleep($pauseDuration * 1000); // Pause en millisecondes

    // Envoyer le coup au serveur
    $conn->send(json_encode([
        'type' => 'reveal_cell',
        'game_id' => $currentGameId,
        'x' => $selectedMove['x'],
        'y' => $selectedMove['y']
    ]));

    echo "Move made at ({$selectedMove['x']}, {$selectedMove['y']})\n";
}

// Fonction pour obtenir les voisins valides d'une cellule
function getNeighbors($x, $y, $width, $height) {
    $neighbors = [];
    for ($dx = -1; $dx <= 1; $dx++) {
        for ($dy = -1; $dy <= 1; $dy++) {
            if ($dx === 0 && $dy === 0) {
                continue;
            }
            $nx = $x + $dx;
            $ny = $y + $dy;
            if ($nx >= 0 && $ny >= 0 && $nx < $width && $ny < $height) {
                $neighbors[] = ['x' => $nx, 'y' => $ny];
            }
        }
    }
    return $neighbors;
}
