import asyncio
import websockets
import json
import random
import time
import sys
import os
import threading
import fcntl
import numpy as np
from sklearn.neural_network import MLPClassifier
from sklearn.preprocessing import StandardScaler
from collections import deque

# Configuration
GRID_SIZES = {'10x10': (10, 10), '20x20': (20, 20), '30x30': (30, 30)}
AI_LEVELS = {'easy': 0.7, 'medium': 0.85, 'hard': 0.95, 'notfun': 1}

# Paramètres par défaut
grid_size = '10x10'
selected_level = 'medium'
pause_duration = 1000
invite_automatically = False
learn_enabled = True
exploration_rate = 0.1  # Probabilité de faire un coup aléatoire pour exploration

# Initialisation du modèle d'apprentissage
model = MLPClassifier(hidden_layer_sizes=(100, 50), max_iter=1000, warm_start=True)
scaler = StandardScaler()
memory_buffer = deque(maxlen=10000)  # Tampon pour stocker les expériences récentes

# Charger les données précédemment enregistrées
try:
    training_data = np.load("training_data.npy", allow_pickle=True)
except FileNotFoundError:
    training_data = []

def preprocess_board(board):
    """Prétraite le plateau pour l'apprentissage."""
    flat_board = [
        cell['adjacentMines'] if cell['revealed'] else -1
        for row in board for cell in row
    ]
    return np.array(flat_board).reshape(1, -1)

def train_model():
    """Entraîne le modèle sur les données du tampon mémoire."""
    if len(memory_buffer) < 1000:
        return  # Attendre d'avoir suffisamment de données

    X = []
    y = []
    for state, action, reward in memory_buffer:
        X.append(state.flatten())
        y.append(1 if reward > 0 else 0)  # 1 = sûr, 0 = dangereux

    X = np.array(X)
    y = np.array(y)
    
    X = scaler.fit_transform(X)
    model.partial_fit(X, y, classes=[0, 1])

def get_best_move(board):
    """Détermine le meilleur mouvement basé sur le modèle d'apprentissage."""
    possible_moves = [
        (x, y) for x, row in enumerate(board)
        for y, cell in enumerate(row)
        if not cell['revealed'] and not cell['flagged']
    ]

    if not possible_moves:
        return None

    # Exploration/exploitation
    if random.random() < exploration_rate:
        return random.choice(possible_moves)  # Coup aléatoire pour exploration
    
    best_move = None
    best_score = -float('inf')

    for move in possible_moves:
        state = preprocess_board(board)
        state = scaler.transform(state)
        score = model.predict_proba(state)[0][1]  # Probabilité que le mouvement soit sûr

        if score > best_score:
            best_score = score
            best_move = move

    return best_move

async def make_move(websocket, board):
    """Effectue un mouvement basé sur le modèle d'apprentissage."""
    move = get_best_move(board)
    if move is None:
        print("Aucun mouvement possible.")
        return

    x, y = move
    print(f"Mouvement choisi : ({x}, {y})")

    await asyncio.sleep(pause_duration / 1000)

    move_message = {
        'type': 'reveal_cell',
        'game_id': current_game_id,
        'x': x,
        'y': y
    }
    await websocket.send(json.dumps(move_message))

def update_memory(board, move, reward):
    """Met à jour la mémoire avec le dernier mouvement."""
    state = preprocess_board(board)
    memory_buffer.append((state, move, reward))

    # Sauvegarder les coups pour réutilisation future dans l'apprentissage
    training_data.append((state, move, reward))
    np.save("training_data.npy", training_data)

async def handle_server_messages(websocket, username):
    """Gère les messages reçus du serveur."""
    global current_game_id, current_invitation_id, invited_player_id
    
    async for message in websocket:
        data = json.loads(message)

        if data['type'] == 'login_success':
            print(f"Connecté en tant qu'IA : {data['username']}")
            current_player_id = data['playerId']
            if invite_automatically:
                await search_and_invite_player(websocket, data['players'], username)

        elif data['type'] == 'invite':
            print(f"Invitation reçue de : {data['inviter']}")
            current_invitation_id = data['invitationId']
            await accept_invite(websocket)

        elif data['type'] == 'game_start':
            print("La partie commence !")
            current_game_id = data['game_id']
            display_board(data['board'])
            await make_move(websocket, data['board'])

        elif data['type'] == 'update_board':
            if data['currentPlayer'] == username:
                print("C'est mon tour !")
                display_board(data['board'])
                await make_move(websocket, data['board'])
            else:
                print("En attente de mon tour...")
                display_board(data['board'])

        elif data['type'] == 'game_over':
            print(f"Fin de la partie. Gagnant : {data['winner_name']}")
            display_board(data['board'])

            # Mise à jour de la mémoire et entraînement du modèle
            if learn_enabled:
                reward = 1 if data['winner_name'] == username else -1
                update_memory(data['board'], (data['losingCell']['x'], data['losingCell']['y']), reward)
                train_model()

            if invite_automatically and invited_player_id is not None:
                print(f"Réinvitation du joueur avec l'ID : {invited_player_id}")
                await invite_player(websocket, invited_player_id)

        elif data['type'] == 'connected_players':
            if invite_automatically:
                await search_and_invite_player(websocket, data['players'], username)

        elif data['type'] == 'error':
            print(f"Erreur : {data['message']}")

def display_board(board):
    """Affichage du plateau de jeu"""
    width = len(board)
    height = len(board[0])

    print("Current board state:")
    for x in range(width):
        for y in range(height):
            cell = board[x][y]
            if cell['revealed']:
                if 'mine' in cell and cell['mine']:
                    print("💣", end=" ")
                elif cell['adjacentMines'] > 0:
                    print(cell['adjacentMines'], end=" ")
                else:
                    print("  ", end=" ")
            elif cell['flagged']:
                print("🚩", end=" ")
            else:
                print("⬜", end=" ")
        print()

async def main():
    uri = "ws://localhost:8080"
    async with websockets.connect(uri) as websocket:
        # Logique de connexion et gestion des messages
        await handle_server_messages(websocket, "IA_Username")

if __name__ == "__main__":
    keyboard_thread = threading.Thread(target=lambda: input("Appuyez sur Entrée pour arrêter l'IA...") or setattr(__builtins__, 'stop_ai', True), daemon=True)
    keyboard_thread.start()

    asyncio.run(main())
