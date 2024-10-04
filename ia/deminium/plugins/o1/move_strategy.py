import numpy as np
import os
import json
import random
from collections import deque
from tensorflow.keras.models import Sequential, load_model
from tensorflow.keras.layers import Dense, Flatten, Input
from tensorflow.keras.optimizers import Adam

class MoveStrategy:
    def __init__(self):
        # Paramètres du modèle
        self.state_size = (10, 10)  # width, height
        self.action_size = self.state_size[0] * self.state_size[1]
        self.memory = deque(maxlen=2000)
        self.gamma = 0.95
        self.epsilon = 1.0
        self.epsilon_min = 0.01
        self.epsilon_decay = 0.995
        self.learning_rate = 0.001
        self.batch_size = 32

        # Chemin du répertoire du plugin
        plugin_dir = os.path.dirname(__file__)
        self.model_path = os.path.join(plugin_dir, 'model.keras')
        self.epsilon_path = os.path.join(plugin_dir, 'epsilon.json')

        # Charger le modèle s'il existe, sinon en créer un nouveau
        if os.path.isfile(self.model_path):
            self.model = load_model(self.model_path)
            if os.path.isfile(self.epsilon_path):
                with open(self.epsilon_path, 'r') as f:
                    self.epsilon = json.load(f)['epsilon']
        else:
            self.model = self._build_model()

        self.last_state = None
        self.last_action = None

    def _build_model(self):
        model = Sequential()
        model.add(Input(shape=self.state_size))
        model.add(Flatten())
        model.add(Dense(256, activation='relu'))
        model.add(Dense(256, activation='relu'))
        model.add(Dense(self.action_size, activation='linear'))
        model.compile(loss='mse', optimizer=Adam(learning_rate=self.learning_rate))
        return model

    def beginGame(self):
        self.last_state = None
        self.last_action = None

    def choose_move(self, board):
        state = self._get_state(board)

        if self.last_state is not None and self.last_action is not None:
            reward = 0  # Récompense neutre pour les actions intermédiaires
            done = False
            self.memory.append((self.last_state, self.last_action, reward, state, done))

        # Trouver les coups sûrs basés sur les cases adjacentes
        safe_moves = self._find_safe_moves(board)
        if safe_moves:
            move = random.choice(safe_moves)
            print(f"Action sûre choisie : ({move['x']}, {move['y']})")
        else:
            # Si aucun coup sûr n'est trouvé, utiliser l'algorithme existant
            possible_actions = self._get_possible_actions(state)
            if not possible_actions:
                print("Aucune action possible !")
                return None

            action = self._act(state, possible_actions)
            x = int(action // self.state_size[1])
            y = int(action % self.state_size[1])

            # Vérifier si la cellule est déjà révélée
            cell = board[x][y]
            if cell['revealed']:
                print(f"Erreur : la cellule ({x}, {y}) est déjà révélée !")
                possible_actions.remove(action)
                if possible_actions:
                    return self.choose_move(board)
                else:
                    return None

            move = {'x': x, 'y': y}
            print(f"Action aléatoire choisie : ({x}, {y})")

        self.last_state = state
        self.last_action = move['x'] * self.state_size[1] + move['y']
        return move

    def endGame(self, winner_name, username):
        final_reward = 1 if winner_name == username else -1

        # Mise à jour de la dernière transition avec la récompense finale
        if self.last_state is not None and self.last_action is not None:
            self.memory.append((self.last_state, self.last_action, final_reward, None, True))

        self._replay()

        self.model.save(self.model_path)
        with open(self.epsilon_path, 'w') as f:
            json.dump({'epsilon': self.epsilon}, f)

    def _get_state(self, board):
        state = np.zeros(self.state_size)
        for x in range(self.state_size[0]):
            for y in range(self.state_size[1]):
                cell = board[x][y]
                if cell['revealed']:
                    if 'mine' in cell and cell['mine']:
                        state[x][y] = -1
                    elif cell['adjacentMines'] > 0:
                        state[x][y] = cell['adjacentMines']
                    else:
                        state[x][y] = -3
                elif cell.get('flagged', False):
                    state[x][y] = -2
                else:
                    state[x][y] = 0
        return state

    def _act(self, state, possible_actions):
        if np.random.rand() <= self.epsilon:
            return random.choice(possible_actions)
        else:
            act_values = self.model.predict(state.reshape(1, *self.state_size), verbose=0)
            masked_act_values = np.full(self.action_size, -np.inf)
            masked_act_values[possible_actions] = act_values[0][possible_actions]
            chosen_action = np.argmax(masked_act_values)
            return chosen_action

    def _get_possible_actions(self, state):
        possible_actions = []
        for x in range(self.state_size[0]):
            for y in range(self.state_size[1]):
                if state[x][y] == 0:
                    action = x * self.state_size[1] + y
                    possible_actions.append(action)
        return possible_actions

    def _find_safe_moves(self, board):
        safe_moves = []
        width = self.state_size[0]
        height = self.state_size[1]

        for x in range(width):
            for y in range(height):
                cell = board[x][y]
                if cell['revealed'] and cell['adjacentMines'] > 0:
                    # Compter les cases non révélées autour
                    unrevealed_neighbors = []
                    flagged_neighbors = 0

                    for dx in [-1, 0, 1]:
                        for dy in [-1, 0, 1]:
                            nx, ny = x + dx, y + dy
                            if 0 <= nx < width and 0 <= ny < height and (dx != 0 or dy != 0):
                                neighbor = board[nx][ny]
                                if not neighbor['revealed']:
                                    if neighbor.get('flagged', False):
                                        flagged_neighbors += 1
                                    else:
                                        unrevealed_neighbors.append({'x': nx, 'y': ny})

                    # Si le nombre de drapeaux autour est égal au nombre indiqué, les autres cases sont sûres
                    if flagged_neighbors == cell['adjacentMines']:
                        safe_moves.extend(unrevealed_neighbors)

        # Éliminer les doublons
        safe_moves_unique = [dict(t) for t in {tuple(d.items()) for d in safe_moves}]
        return safe_moves_unique

    def _replay(self):
        if len(self.memory) < self.batch_size:
            return
        minibatch = random.sample(self.memory, self.batch_size)
        for state, action, reward, next_state, done in minibatch:
            target = reward
            if not done and next_state is not None:
                target = reward + self.gamma * np.amax(self.model.predict(next_state.reshape(1, *self.state_size), verbose=0)[0])
            target_f = self.model.predict(state.reshape(1, *self.state_size), verbose=0)
            target_f[0][action] = target
            self.model.fit(state.reshape(1, *self.state_size), target_f, epochs=1, verbose=0)
        if self.epsilon > self.epsilon_min:
            self.epsilon *= self.epsilon_decay

    def _get_state_after_action(self, state, action):
        x = action // self.state_size[1]
        y = action % self.state_size[1]
        new_state = np.copy(state)
        new_state[x][y] = 1
        return new_state
