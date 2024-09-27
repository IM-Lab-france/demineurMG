<?php
// fetch_game_state.php

// Simuler la récupération de l'état du jeu à partir de $games (utilise ton propre système pour les récupérer)
$gameId = $_GET['gameId'] ?? null;

$games = [
    'game1' => ['board' => 'État du jeu 1'],
    'game2' => ['board' => 'État du jeu 2']
];

if ($gameId && isset($games[$gameId])) {
    echo $games[$gameId]['board'];
} else {
    echo 'Partie introuvable';
}
?>
