protected function handleAIAction($gameId) {
    $game = $this->games[$gameId];
    $currentAI = $game['currentTurn'];

    // Sélectionner le niveau de difficulté de l'IA
    $difficultyLevel = $this->players[$currentAI]['difficulty'];

    // Filtrer les cases non révélées
    $board = $game['board'];
    $nonRevealedCells = [];

    for ($x = 0; $x < count($board); $x++) {
        for ($y = 0; $y < count($board[0]); $y++) {
            if (!$board[$x][$y]['revealed'] && !$board[$x][$y]['flagged']) {
                $nonRevealedCells[] = ['x' => $x, 'y' => $y];
            }
        }
    }

    // Selon la difficulté, prendre une décision
    $cellToReveal = $this->chooseCellForAI($nonRevealedCells, $difficultyLevel, $board);

    // Révéler la cellule sélectionnée
    $this->handleRevealCell($this->getConnectionFromPlayerId($currentAI), [
        'game_id' => $gameId,
        'x' => $cellToReveal['x'],
        'y' => $cellToReveal['y']
    ]);
}

// Fonction qui choisit une cellule pour l'IA en fonction de la difficulté
protected function chooseCellForAI($nonRevealedCells, $difficultyLevel, $board) {
    // À difficulté basse, l'IA choisit au hasard
    if ($difficultyLevel == 'easy') {
        return $nonRevealedCells[array_rand($nonRevealedCells)];
    }

    // À difficulté moyenne, l'IA essaie d'éviter les mines en tenant compte des informations visibles
    if ($difficultyLevel == 'medium') {
        foreach ($nonRevealedCells as $cell) {
            $x = $cell['x'];
            $y = $cell['y'];

            // Vérifier les cases adjacentes révélées pour éviter les mines
            $adjacentMines = $this->getAdjacentMines($board, $x, $y);
            if ($adjacentMines == 0) {
                return $cell;
            }
        }
    }

    // À difficulté difficile, l'IA est plus prudente et utilise une stratégie
    if ($difficultyLevel == 'hard') {
        $bestCell = null;
        $minRisk = PHP_INT_MAX;

        foreach ($nonRevealedCells as $cell) {
            $x = $cell['x'];
            $y = $cell['y'];

            // Calculer le risque de mines autour
            $risk = $this->calculateRisk($board, $x, $y);
            if ($risk < $minRisk) {
                $minRisk = $risk;
                $bestCell = $cell;
            }
        }

        return $bestCell ?: $nonRevealedCells[array_rand($nonRevealedCells)]; // Si aucune cellule sûre, choisir au hasard
    }

    return $nonRevealedCells[array_rand($nonRevealedCells)]; // Par défaut, choix aléatoire
}

// Fonction pour obtenir les mines adjacentes
protected function getAdjacentMines($board, $x, $y) {
    $directions = [[-1, -1], [-1, 0], [-1, 1], [0, -1], [0, 1], [1, -1], [1, 0], [1, 1]];
    $adjacentMines = 0;

    foreach ($directions as $dir) {
        $newX = $x + $dir[0];
        $newY = $y + $dir[1];

        if (isset($board[$newX][$newY]) && $board[$newX][$newY]['revealed'] && $board[$newX][$newY]['mine']) {
            $adjacentMines++;
        }
    }

    return $adjacentMines;
}

// Calculer le risque de mines adjacentes
protected function calculateRisk($board, $x, $y) {
    // Plus le nombre de mines adjacentes est élevé, plus le risque est élevé
    return $this->getAdjacentMines($board, $x, $y);
}
