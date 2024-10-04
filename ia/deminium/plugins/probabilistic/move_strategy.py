# move_strategy.py

from abc import ABC, abstractmethod
import os
import json
import random

class MoveStrategy(ABC):
    @abstractmethod
    def choose_move(self, board):
        pass

    @abstractmethod
    def beginGame(self):
        pass

    @abstractmethod
    def endGame(self, winner_name, ai_name):
        pass

class MemoryManager:
    def __init__(self, memory_file="ia_moves.json"):
        self.memory_file = memory_file
        self.moves = []  # Initialisation de la liste pour stocker les coups joués

    def load_memory(self):
        """Charger les coups joués à partir d'un fichier JSON"""
        if os.path.exists(self.memory_file):
            try:
                with open(self.memory_file, 'r') as f:
                    data = json.load(f)
                    if isinstance(data, list):  # Assurez-vous que les données sont bien une liste
                        self.moves = data
                    else:
                        print(f"Invalid format in {self.memory_file}. Resetting memory.")
                        self.moves = []  # Réinitialiser en tant que liste vide
            except json.JSONDecodeError:
                print(f"Error decoding {self.memory_file}. Resetting memory.")
                self.moves = []  # En cas d'erreur, réinitialiser la liste des mouvements
        else:
            print("No previous memory found, starting fresh.")
            self.moves = []  # Initialiser avec une liste vide si aucun fichier trouvé

    def save_move(self, x, y):
        """Ajouter un coup joué à la liste et sauvegarder dans le fichier JSON"""
        move = {'x': x, 'y': y}
        self.moves.append(move)  # Ajout du coup dans la liste

        # Sauvegarder dans le fichier JSON
        with open(self.memory_file, 'w') as f:
            json.dump(self.moves, f, indent=4)
        print(f"Move saved: {move}")

    def save_memory(self):
        """Sauvegarder la mémoire complète des coups joués dans un fichier JSON"""
        with open(self.memory_file, 'w') as f:
            json.dump(self.moves, f, indent=4)
        print(f"All moves saved to {self.memory_file}.")

class MoveStrategy(MoveStrategy):
    def __init__(self):
        self.memory_manager = MemoryManager()
        self.memory_manager.load_memory()

    def beginGame(self):
        """Méthode appelée au début de la partie"""
        print("Game started for probabilistic strategy.")

    def endGame(self, winner_name, ai_name):
        """Méthode appelée à la fin de la partie"""
        print("Game ended for probabilistic strategy.")
        self.save_memory()

    def choose_move(self, board):
        """Calculer la probabilité d'une mine sur chaque case non révélée et choisir la case avec la probabilité la plus faible."""
        width = len(board)
        height = len(board[0])
        probabilities = {}  # Probabilités que chaque case contienne une mine
        safe_moves = []  # Liste des coups sûrs à jouer
        possible_moves = []

        # Fonction pour obtenir les voisins d'une case
        def get_neighbors(x, y):
            neighbors = []
            for dx in range(-1, 2):
                for dy in range(-1, 2):
                    if dx == 0 and dy == 0:
                        continue
                    nx, ny = x + dx, y + dy
                    if 0 <= nx < width and 0 <= ny < height:
                        neighbors.append((nx, ny))
            return neighbors

        # Calculer les probabilités en fonction des cases révélées
        for x in range(width):
            for y in range(height):
                cell = board[x][y]
                if cell['revealed'] and 'adjacentMines' in cell:
                    adjacent_mines = cell['adjacentMines']
                    neighbors = get_neighbors(x, y)

                    # Compter les drapeaux et les cases non révélées autour de cette case
                    flagged_neighbors = 0
                    unrevealed_neighbors = []
                    for nx, ny in neighbors:
                        neighbor_cell = board[nx][ny]
                        if neighbor_cell['flagged']:
                            flagged_neighbors += 1
                        elif not neighbor_cell['revealed']:
                            unrevealed_neighbors.append((nx, ny))

                    # Calculer la probabilité pour chaque voisin non révélé
                    if unrevealed_neighbors:
                        remaining_mines = adjacent_mines - flagged_neighbors
                        prob_mine = remaining_mines / len(unrevealed_neighbors)
                        for nx, ny in unrevealed_neighbors:
                            # Si déjà une probabilité, la moyenne avec la nouvelle
                            if (nx, ny) in probabilities:
                                probabilities[(nx, ny)] = (probabilities[(nx, ny)] + prob_mine) / 2
                            else:
                                probabilities[(nx, ny)] = prob_mine

        # Trouver les cases non révélées pour lesquelles on a des probabilités, et choisir celle avec la plus faible probabilité
        for x in range(width):
            for y in range(height):
                cell = board[x][y]
                if not cell['revealed'] and not cell['flagged']:
                    possible_moves.append((x, y))
                    if (x, y) in probabilities:
                        if probabilities[(x, y)] == 0:
                            safe_moves.append((x, y))  # Si la probabilité est 0, c'est un coup sûr

        # Si des coups sûrs ont été trouvés, en choisir un
        if safe_moves:
            x, y = safe_moves[0]  # Choisir le premier coup sécurisé
            print(f"Safe move chosen at ({x}, {y})")
        else:
            # Sinon, choisir la case avec la plus faible probabilité de contenir une mine
            if probabilities:
                x, y = min(probabilities, key=probabilities.get)
                print(f"Move chosen with lowest probability of mine at ({x}, {y}) - Probability: {probabilities[(x, y)]}")
            else:
                # Si aucune probabilité calculée, jouer aléatoirement parmi les cases possibles
                x, y = random.choice(possible_moves)
                print(f"Random move chosen at ({x}, {y})")

        # Sauvegarder le coup joué
        self.memory_manager.save_move(x, y)

        return {'x': x, 'y': y}

    def save_memory(self):
        """Sauvegarder la mémoire."""
        self.memory_manager.save_memory()
