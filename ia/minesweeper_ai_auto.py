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
from sklearn.ensemble import RandomForestClassifier
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import StandardScaler
import pickle

# -------------------------
# Utilisation de sklearn pour l'apprentissage
# -------------------------



# Initialiser le modèle et les données d'entraînement
X_data = []
y_data = []
model = RandomForestClassifier()

# Configuration des niveaux d'IA
ai_levels = {
    'easy': 0.7,
    'medium': 0.85,
    'hard': 0.95,
    'notfun': 1
}

# Chargement des comptes IA
with open('ia_accounts.json', 'r') as f:
    ia_accounts = json.load(f)

# Variables de configuration par défaut
pause_duration = 1000  # Pause par défaut en millisecondes
invite_automatically = '--invite' in sys.argv  # Si '--invite' est passé en argument
learn_enabled = '--learn' in sys.argv  # Si '--learn' est passé en argument, l'IA apprend
invited_player_id = None  # Stocke l'ID du joueur invité pour réinviter plus tard
grid_size = '10x10'  # Taille de la grille par défaut
selected_level = 'medium'  # Niveau de difficulté par défaut
memory_file = "ia_memory.pkl"  # Fichier de mémoire de l'IA, avec le modèle et les données d'apprentissage
mine_exploded = False  # Variable pour suivre l'état de l'explosion de mine
stop_ai = False  # Variable pour arrêter l'IA

# Vérification des arguments de lancement pour la taille de la grille, la pause et le niveau de difficulté
for arg in sys.argv:
    if arg.startswith('--pause='):
        pause_duration = int(arg.split('=')[1])  # Extraire la durée de la pause
    elif arg.startswith('--grid_size='):
        grid_size = arg.split('=')[1]  # Extraire la taille de la grille
    elif arg.startswith('--ai_level='):
        selected_level = arg.split('=')[1]  # Extraire le niveau d'IA

# Vérification que le niveau d'IA existe
if selected_level not in ai_levels:
    print(f"Niveau d'IA invalide : {selected_level}. Utilisation du niveau par défaut (medium).")
    selected_level = 'medium'

# Configuration IA
accuracy = ai_levels[selected_level]









# Charger ou initialiser la mémoire de l'IA à chaque nouvelle partie
def load_memory():
    """Charger la mémoire et le modèle depuis un fichier"""
    global X_data, y_data, model
    if os.path.exists(memory_file):
        with open(memory_file, 'rb') as f:
            data = pickle.load(f)
            X_data = data.get('X_data', [])
            y_data = data.get('y_data', [])
            model = data.get('model', RandomForestClassifier())  # Si pas de modèle, en créer un nouveau
        print("Memory and model loaded.")
    else:
        X_data = []
        y_data = []
        model = RandomForestClassifier()  # Nouveau modèle si aucun n'existe
        print("No previous memory found, starting fresh.")

# Sauvegarder la mémoire à chaque fin de partie
def save_memory():
    """Sauvegarder la mémoire et le modèle dans un fichier"""
    with open(memory_file, 'wb') as f:
        pickle.dump({
            'X_data': X_data,
            'y_data': y_data,
            'model': model
        }, f)
    print("Memory and model saved.")

# Entraîner le modèle avec les données existantes
def train_model():
    global model, X_data, y_data
    if len(X_data) > 5:  # Assurez-vous d'avoir suffisamment de données
        X = np.array(X_data)
        y = np.array(y_data)
        
        # Normalisation des données
        scaler = StandardScaler()
        X = scaler.fit_transform(X)

        # Diviser les données en ensemble d'entraînement et de test
        X_train, X_test, y_train, y_test = train_test_split(X, y, test_size=0.2)

        # Entraîner le modèle
        model.fit(X_train, y_train)
        print(f"Model trained with {len(X_train)} samples.")
    else:
        print(f"Not enough data to train the model. Need at least 5 samples, currently have {len(X_data)}.")

load_memory()  # Charge la mémoire et met à jour directement X_data, y_data et model

# Entraîner le modèle au démarrage si des données sont disponibles
if learn_enabled and len(X_data) > 0:
    train_model()

async def connect_to_server(uri):
    async with websockets.connect(uri) as websocket:
        await attempt_login(websocket)

async def attempt_login(websocket):
    """Tente de se connecter avec un compte au hasard jusqu'à succès"""
    available_accounts = ia_accounts.copy()
    while available_accounts:
        ai_account = random.choice(available_accounts)
        username = ai_account['username']
        password = ai_account['password']

        print(f"Trying to log in with {username}...")

        login_message = {
            'type': 'login',
            'username': username,
            'password': password
        }
        await websocket.send(json.dumps(login_message))

        message = await websocket.recv()
        data = json.loads(message)

        if data['type'] == 'login_success':
            print(f"Logged in successfully as {username}")
            global current_player_id
            current_player_id = data['playerId']
            await handle_server_messages(websocket, username)
            break
        elif data['type'] == 'login_failed':
            print(f"Login failed for {username}. Trying another account...")
            available_accounts.remove(ai_account)

async def handle_server_messages(websocket, username):
    """Gère les messages reçus du serveur après la connexion"""
    global current_game_id, current_invitation_id, invited_player_id, mine_exploded

    async for message in websocket:
        data = json.loads(message)

        if stop_ai:
            print("Stopping AI...")
            return

        if data['type'] == 'login_success':
            print(f"Logged in as AI: {data['username']}")
            current_player_id = data['playerId']

            if invite_automatically:
                await search_and_invite_player(websocket, data['players'], username)

        elif data['type'] == 'invite':
            print(f"Invitation received from: {data['inviter']}")
            current_invitation_id = data['invitationId']
            await accept_invite(websocket)

        elif data['type'] == 'game_start':
            print("Game started!")
            current_game_id = data['game_id']
            display_board(data['board'])
            await make_move(websocket, data['board'])

        elif data['type'] == 'update_board':
            # L'IA joue uniquement si c'est son tour
            if data['currentPlayer'] == username:
                print("It's my turn!")
                display_board(data['board'])
                await make_move(websocket, data['board'])
            else:
                # Tour de l'adversaire, ne rien faire
                print("Waiting for my turn...")
                display_board(data['board'])

        elif data['type'] == 'game_over':
            print(f"Game over. Winner: {data['winner_name']}")
            display_board(data['board'])

            # Si le jeu est terminé et que l'IA a perdu (winner_name n'est pas l'IA)
            mine_exploded = (data['winner_name'] != username)

            # Sauvegarder la matrice 7x7 avec la case qui a causé l'explosion si l'IA apprend
            losing_cell = data.get('losingCell')
            if losing_cell:
                x, y = losing_cell.get('x', None), losing_cell.get('y', None)
            else:
                x, y = None, None
            if x is not None and y is not None and learn_enabled:
                record_7x7_matrix(data['board'], x, y, mine_exploded)

            # Sauvegarder la mémoire après la partie, si l'apprentissage est activé
            if learn_enabled:
                save_memory()

            # Réinviter le même joueur après la fin de la partie
            if invite_automatically and invited_player_id is not None:
                print(f"Reinviting player with ID: {invited_player_id}")
                await invite_player(websocket, invited_player_id)

        elif data['type'] == 'connected_players':
            if invite_automatically:
                await search_and_invite_player(websocket, data['players'], username)

        elif data['type'] == 'error':
            print(f"Error: {data['message']}")

async def accept_invite(websocket):
    """Accepter une invitation"""
    accept_message = {
        'type': 'accept_invite',
        'invitationId': current_invitation_id
    }
    await websocket.send(json.dumps(accept_message))

async def search_and_invite_player(websocket, players, username):
    """Chercher un joueur dont le nom commence par 'ia_' et envoyer une invitation"""
    global invited_player_id
    for player in players:
        if player['username'].startswith('ia_') and player['username'] != username:
            print(f"Inviting player: {player['username']}")
            await invite_player(websocket, player['id'])
            invited_player_id = player['id']
            return
    print("No suitable 'ia_' player found to invite.")

async def invite_player(websocket, player_id):
    """Inviter un joueur à jouer une partie"""
    invite_message = {
        'type': 'invite',
        'invitee': player_id,
        'gridSize': grid_size,  # Taille de la grille définie par le paramètre de lancement
        'difficulty': 15  # Niveau de difficulté par défaut, pourrait être ajusté si nécessaire
    }
    await websocket.send(json.dumps(invite_message))

async def make_move(websocket, board):
    """Effectuer un coup basé sur l'état du plateau en utilisant la mémoire abstraite ou le modèle appris"""
    global pause_duration, model

    width = len(board)
    height = len(board[0])
    possible_moves = []

    # Collecter toutes les cases non révélées
    for x in range(width):
        for y in range(height):
            if not board[x][y]['revealed'] and not board[x][y]['flagged']:
                possible_moves.append({'x': x, 'y': y})

    # Si le modèle est activé et suffisamment entraîné, on peut essayer de prédire le prochain coup
    if learn_enabled and len(X_data) > 5 and hasattr(model, "n_features_in_"):  # Vérifier si le modèle est entraîné
        best_move = None
        for move in possible_moves:
            x, y = move['x'], move['y']
            board_vector = extract_7x7_matrix(board, x, y)  # Extraire la matrice 7x7 autour du mouvement potentiel

            # Normalisation avant la prédiction
            scaler = StandardScaler()
            board_vector = scaler.fit_transform([board_vector])  # Le scaler attend une liste de vecteurs

            # Prédire la sécurité de cette case
            prediction = model.predict(board_vector)

            # Si la prédiction est positive, choisir ce coup
            if prediction[0] == 0:  # 0 signifie probablement sans mine
                best_move = move
                break

        if best_move:
            print(f"Move predicted at ({best_move['x']}, {best_move['y']})")
        else:
            best_move = random.choice(possible_moves)  # Si aucune prédiction sûre, choisir au hasard
            print(f"No safe move predicted. Random move chosen at ({best_move['x']}, {best_move['y']})")
    else:
        # Si pas de modèle entraîné ou pas assez de données, choisir un coup aléatoire
        if len(X_data) <= 5:
            print(f"Not enough data to train the model. Falling back to random move. Data samples: {len(X_data)}")
        elif not hasattr(model, "n_features_in_"):
            print(f"Model not trained yet. Falling back to random move.")
        best_move = random.choice(possible_moves)
        print(f"Random move chosen at ({best_move['x']}, {best_move['y']})")

    # Pause avant d'envoyer le coup
    time.sleep(pause_duration / 1000)

    move_message = {
        'type': 'reveal_cell',
        'game_id': current_game_id,
        'x': best_move['x'],
        'y': best_move['y']
    }
    await websocket.send(json.dumps(move_message))

    print(f"Move made at ({best_move['x']}, {best_move['y']})")

def extract_7x7_matrix(board, x, y):
    """Extraire une matrice 7x7 centrée sur la cellule (x, y)"""
    matrix = []
    half_size = 3  # Taille de la matrice 7x7 = 3 cases autour du centre

    for dx in range(-half_size, half_size + 1):
        row = []
        for dy in range(-half_size, half_size + 1):
            new_x, new_y = x + dx, y + dy
            if 0 <= new_x < len(board) and 0 <= new_y < len(board[0]):
                if board[new_x][new_y]['revealed']:
                    row.append(board[new_x][new_y]['adjacentMines'])
                else:
                    row.append(-1)  # Utiliser -1 pour les cellules non révélées
            else:
                row.append(-1)  # Cellule hors du plateau
        matrix.append(row)

    return np.array(matrix).flatten()  # Aplatir la matrice 7x7 en un vecteur 1D

def record_7x7_matrix(board, x, y, mine_exploded):
    """Enregistre une matrice 7x7 centrée sur la case x, y avec les valeurs adjacentMines"""
    matrix = []
    half_size = 3  # Taille de la matrice 7x7 = 3 cases autour du centre

    for dx in range(-half_size, half_size + 1):
        row = []
        for dy in range(-half_size, half_size + 1):
            new_x, new_y = x + dx, y + dy
            if 0 <= new_x < len(board) and 0 <= new_y < len(board[0]):
                # Ajouter la valeur adjacentMines ou ? si non révélée
                if board[new_x][new_y]['revealed']:
                    row.append(board[new_x][new_y]['adjacentMines'])
                else:
                    row.append(-1)  # Valeur indicative pour les cellules non révélées
            else:
                row.append(None)  # Hors du plateau
        matrix.append(row)

    # Enregistrer dans la mémoire avec le résultat (mine explosée ou non)
    memory_key = str(matrix)
    X_data.append(np.array(matrix).flatten())  # Aplatir la matrice 7x7 pour la stocker
    y_data.append(1 if mine_exploded else 0)  # 1 pour mine explosée, 0 sinon

    if learn_enabled:
        train_model()

    print(f"7x7 matrix saved with mine_exploded: {mine_exploded}")

# Sauvegarder les données d'apprentissage après chaque partie
def save_learning_data():
    memory['X'] = X_data
    memory['y'] = y_data
    save_memory()

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

def get_neighbors(x, y, width, height):
    """Obtenir les voisins valides d'une cellule"""
    neighbors = []
    for dx in range(-1, 2):
        for dy in range(-1, 2):
            if dx == 0 and dy == 0:
                continue
            nx = x + dx
            ny = y + dy
            if 0 <= nx < width and 0 <= ny < height:
                neighbors.append({'x': nx, 'y': ny})
    return neighbors

def get_distance_pattern(board):
    """Générer un motif abstrait basé uniquement sur les distances aux mines, converti en valeurs numériques."""
    distance_pattern = []
    for row in board:
        row_pattern = []
        for cell in row:
            if cell['revealed'] and 'adjacentMines' in cell:
                row_pattern.append(cell['adjacentMines'])  # Utiliser les valeurs de mines adjacentes
            else:
                row_pattern.append(-1)  # Cellule non révélée, on utilise -1 pour indiquer une valeur inconnue
        distance_pattern.append(row_pattern)
    return distance_pattern

def monitor_keyboard():
    """Thread pour surveiller l'entrée du clavier et arrêter l'IA si 'q' est pressé"""
    global stop_ai
    while True:
        if input() == 'q':
            stop_ai = True
            print("Stopping AI via keyboard input.")
            break

if __name__ == "__main__":
    uri = "ws://localhost:8080"

    # Lancer le thread pour surveiller la touche 'q'
    keyboard_thread = threading.Thread(target=monitor_keyboard, daemon=True)
    keyboard_thread.start()

    # Utilisation de asyncio.run() pour éviter les warnings de boucle d'événement dépréciée
    asyncio.run(connect_to_server(uri))
