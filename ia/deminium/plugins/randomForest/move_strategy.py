# move_strategy.py

from abc import ABC, abstractmethod
import os
import json
import numpy as np
from sklearn.ensemble import RandomForestClassifier
from sklearn.preprocessing import StandardScaler
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
        self.memory_file = os.path.join(os.path.dirname(__file__), memory_file)
        self.X_data = []  # Caractéristiques (features)
        self.y_data = []  # Labels (mine ou non)
        self.model = None  # Modèle de machine learning

    def load_memory(self):
        """Charger les données d'entraînement (X_data, y_data) à partir d'un fichier JSON"""
        if os.path.exists(self.memory_file):
            try:
                with open(self.memory_file, 'r') as f:
                    data = json.load(f)

                    # Vérifier si le fichier est une liste ou un dictionnaire
                    if isinstance(data, dict):
                        self.X_data = data.get('X_data', [])
                        self.y_data = data.get('y_data', [])
                    else:
                        print("Invalid format found in memory file. Starting fresh.")
                        self.X_data = []
                        self.y_data = []

            except json.JSONDecodeError:
                print("Error decoding the JSON file. Starting fresh.")
                self.X_data = []
                self.y_data = []
        else:
            print("No previous memory found, starting fresh.")
            self.X_data = []
            self.y_data = []

    def save_memory(self):
        """Sauvegarder les données d'entraînement dans un fichier JSON"""
        with open(self.memory_file, 'w') as f:
            json.dump({
                'X_data': self.X_data,
                'y_data': self.y_data
            }, f, indent=4)
        print(f"Training data saved to {self.memory_file}.")

    def save_training_sample(self, features, label):
        """Ajouter une nouvelle donnée d'entraînement"""
        print(f"Saving training sample: features={features}, label={label}")
        self.X_data.append(features)
        self.y_data.append(label)

class MoveStrategy(MoveStrategy):
    def __init__(self):
        self.memory_manager = MemoryManager()
        self.memory_manager.load_memory()
        self.model = RandomForestClassifier(n_estimators=100)  # Modèle de forêt aléatoire
        self.scaler = StandardScaler()

        # Entraîner le modèle avec les données existantes
        if len(self.memory_manager.X_data) > 0:
            self.train_model()

    def beginGame(self):
        print("Game started.")
        # Initialiser ou charger la mémoire ici si nécessaire

    def endGame(self, winner_name, ai_name):
        print("Game ended.")
        self.memory_manager.save_memory()
        # Sauvegarder la mémoire ou autres actions de fin de partie

    def train_model(self):
        """Entraîner le modèle avec les données actuelles"""
        X_clean = []
        for features in self.memory_manager.X_data:
            if isinstance(features, list) and all(isinstance(f, (int, float)) for f in features):
                X_clean.append(features)
            else:
                print(f"Invalid feature set found: {features}. Skipping.")

        if not X_clean:
            print("No valid data available to train the model.")
            return

        X = np.array(X_clean)
        y = np.array(self.memory_manager.y_data)

        # Normalisation des données
        X = self.scaler.fit_transform(X)

        # Entraîner le modèle
        self.model.fit(X, y)
        print("Model trained with existing data.")

    def extract_features(self, board, x, y):
        """Extraire les caractéristiques d'une cellule pour la prédiction"""
        width = len(board)
        height = len(board[0])
        adjacent_mines = 0
        flagged_neighbors = 0
        unrevealed_neighbors = 0
        distance_to_edge = min(x, width - x - 1, y, height - y - 1)  # Distance aux bords

        neighbors = self.get_neighbors(x, y, width, height)

        for nx, ny in neighbors:
            neighbor_cell = board[nx][ny]
            if neighbor_cell['revealed']:
                adjacent_mines += neighbor_cell.get('adjacentMines', 0)
            if neighbor_cell['flagged']:
                flagged_neighbors += 1
            if not neighbor_cell['revealed']:
                unrevealed_neighbors += 1

        features = [
            float(adjacent_mines),
            float(flagged_neighbors),
            float(unrevealed_neighbors),
            float(distance_to_edge)
        ]

        return features

    def choose_move(self, board):
        """Choisir le coup basé sur la prédiction du modèle"""
        width = len(board)
        height = len(board[0])
        possible_moves = []

        if not hasattr(self.model, 'n_features_in_') or len(self.memory_manager.X_data) < 5:
            for x in range(width):
                for y in range(height):
                    if not board[x][y]['revealed'] and not board[x][y]['flagged']:
                        possible_moves.append((x, y))
            x, y = random.choice(possible_moves)
            return {'x': x, 'y': y}

        move_probabilities = []
        for x in range(width):
            for y in range(height):
                if not board[x][y]['revealed'] and not board[x][y]['flagged']:
                    features = self.extract_features(board, x, y)
                    features_scaled = self.scaler.transform([features])
                    prob_mine = self.model.predict_proba(features_scaled)[0][1]
                    move_probabilities.append((x, y, prob_mine))

        move_probabilities.sort(key=lambda move: move[2])

        if move_probabilities:
            best_move = move_probabilities[0]
            x, y = best_move[0], best_move[1]
            return {'x': x, 'y': y}

        x, y = random.choice(possible_moves)
        return {'x': x, 'y': y}

    def get_neighbors(self, x, y, width, height):
        neighbors = []
        for dx in range(-1, 2):
            for dy in range(-1, 2):
                if dx == 0 and dy == 0:
                    continue
                nx, ny = x + dx, y + dy
                if 0 <= nx < width and 0 <= ny < height:
                    neighbors.append((nx, ny))
        return neighbors

    def save_training_sample(self, board, x, y, is_mine):
        features = self.extract_features(board, x, y)
        label = 1 if is_mine else 0
        self.memory_manager.save_training_sample(features, label)
