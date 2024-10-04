# move_strategy.py

from abc import ABC, abstractmethod
import os
import json
import numpy as np
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
    def __init__(self, memory_file="q_table.json"):
        # Obtenir le chemin du fichier dans le répertoire du plugin
        current_dir = os.path.dirname(os.path.abspath(__file__))
        self.memory_file = os.path.join(current_dir, memory_file)  # Fichier enregistré dans le répertoire du plugin
        self.q_table = {}  # Table Q pour stocker les valeurs d'état-action

    def load_memory(self):
        """Charger la table Q depuis un fichier JSON"""
        if os.path.exists(self.memory_file):
            try:
                with open(self.memory_file, 'r') as f:
                    self.q_table = json.load(f)
                print(f"Q-table loaded from {self.memory_file}.")
            except json.JSONDecodeError:
                print(f"Error decoding {self.memory_file}. Starting fresh.")
                self.q_table = {}
        else:
            print("No previous memory found. Starting fresh.")
            self.q_table = {}

    def save_memory(self):
        """Sauvegarder la table Q dans un fichier JSON"""
        # Convertir les clés numpy int64 en types natifs Python avant de sauvegarder
        q_table_native = {str(key): [float(q) for q in value] for key, value in self.q_table.items()}
        try:
            with open(self.memory_file, 'w') as f:
                json.dump(q_table_native, f, indent=4)
            print(f"Q-table saved to {self.memory_file}.")
        except Exception as e:
            print(f"Error saving Q-table: {e}")

class MoveStrategy(MoveStrategy):
    def __init__(self):
        self.memory_manager = MemoryManager()
        self.memory_manager.load_memory()

        # Paramètres d'apprentissage
        self.alpha = 0.1  # Taux d'apprentissage
        self.gamma = 0.9  # Facteur de réduction (discount factor)
        self.epsilon = 0.2  # Taux d'exploration

        self.last_state = None
        self.last_action = None

    def beginGame(self):
        """Initialiser les paramètres au début d'une partie"""
        print("Game started using Q-Learning strategy.")
        self.last_state = None
        self.last_action = None

    def state_to_string(self, board):
        """Convertir l'état du plateau en une chaîne de caractères pour l'utiliser comme clé dans la Q-table"""
        return '|'.join([str(cell['revealed']) + '_' + str(cell['flagged']) for row in board for cell in row])

    def get_available_actions(self, board):
        """Retourner les actions valides (cases non révélées et non marquées)"""
        actions = [(x, y) for x in range(len(board)) for y in range(len(board[0])) if not board[x][y]['revealed'] and not board[x][y]['flagged']]
        return actions

    def choose_move(self, board):
        """Choisir une action basée sur la politique ε-greedy et la table Q"""
        current_state = self.state_to_string(board)
        
        if current_state not in self.memory_manager.q_table:
            # Initialiser les actions pour ce nouvel état
            num_actions = len(board) * len(board[0])
            self.memory_manager.q_table[current_state] = np.zeros(num_actions)

        # Obtenir les actions valides (cases non révélées et non marquées)
        available_actions = self.get_available_actions(board)
        if not available_actions:
            print("No available actions left!")
            return None

        # ε-greedy: choisir une action aléatoire (exploration) ou la meilleure action (exploitation)
        if random.uniform(0, 1) < self.epsilon:
            # Exploration: choisir une action aléatoire parmi les actions disponibles
            x, y = random.choice(available_actions)
            action = x * len(board[0]) + y
        else:
            # Exploitation: choisir l'action avec la plus haute valeur Q parmi les actions disponibles
            best_action = None
            best_q_value = -float('inf')
            for x, y in available_actions:
                action_index = x * len(board[0]) + y
                q_value = self.memory_manager.q_table[current_state][action_index]
                if q_value > best_q_value:
                    best_q_value = q_value
                    best_action = (x, y)

            if best_action is None:
                x, y = random.choice(available_actions)  # Au cas où aucune action n'est meilleure
            else:
                x, y = best_action

        # Sauvegarder l'état et l'action pour la mise à jour future
        self.last_state = current_state
        self.last_action = x * len(board[0]) + y

        print(f"Move chosen at ({x}, {y})")

        return {'x': x, 'y': y}

    def update_q_table(self, reward, new_state):
        """Mettre à jour la table Q en utilisant la formule de Q-Learning"""
        if self.last_state is None or self.last_action is None:
            print("No previous state or action to update Q-table.")
            return

        if new_state not in self.memory_manager.q_table:
            self.memory_manager.q_table[new_state] = np.zeros(len(self.memory_manager.q_table[self.last_state]))  # Initialiser si nécessaire

        # Calculer la nouvelle valeur Q pour l'état précédent et l'action prise
        old_q_value = self.memory_manager.q_table[self.last_state][self.last_action]
        max_future_q_value = np.max(self.memory_manager.q_table[new_state])
        new_q_value = (1 - self.alpha) * old_q_value + self.alpha * (reward + self.gamma * max_future_q_value)

        print(f"Updating Q-table: old value = {old_q_value}, new value = {new_q_value}, reward = {reward}")
        
        self.memory_manager.q_table[self.last_state][self.last_action] = new_q_value

    def endGame(self, winner_name, ai_name):
        """Mettre à jour la Q-table en fonction de la récompense obtenue et sauvegarder"""
        print("Game ended using Q-Learning strategy.")
        
        # Si l'IA a gagné, attribuer une récompense positive, sinon une récompense négative
        reward = 1 if winner_name == ai_name else -1

        # L'état final est le dernier état observé
        new_state = self.last_state  
        
        # Mise à jour de la Q-table
        self.update_q_table(reward, new_state)
        
        # Sauvegarder la table Q
        self.memory_manager.save_memory()
