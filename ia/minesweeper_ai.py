import asyncio
import websockets
import json
import random
import time
import sys

# Configuration des niveaux d'IA
ai_levels = {
    'easy': 0.7,  # 70% chance de faire un bon coup
    'medium': 0.85,  # 85% chance de faire un bon coup
    'hard': 0.95  # 95% chance de faire un bon coup
}

# Chargement des comptes IA
with open('ia_accounts.json', 'r') as f:
    ia_accounts = json.load(f)

# Variables de configuration
pause_duration = 1000  # Pause par défaut en millisecondes
invite_automatically = '--invite' in sys.argv  # Si '--invite' est passé en argument
invited_player_id = None  # Stocke l'ID du joueur invité pour réinviter plus tard

# Vérification des arguments de lancement pour la pause
for arg in sys.argv:
    if arg.startswith('--pause='):
        pause_duration = int(arg.split('=')[1])  # Extraire la durée de la pause

# Configuration IA
selected_level = 'medium'  # Peut être 'easy', 'medium', ou 'hard'
accuracy = ai_levels[selected_level]

# Variables pour stocker l'état du jeu
current_game_id = None
current_player_id = None
current_invitation_id = None


async def connect_to_server(uri):
    async with websockets.connect(uri) as websocket:
        await attempt_login(websocket)


async def attempt_login(websocket):
    """Tente de se connecter avec un compte au hasard jusqu'à succès"""
    available_accounts = ia_accounts.copy()
    while available_accounts:
        # Choisir un compte IA au hasard
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
        
        # Attendre la réponse du serveur après l'envoi du login
        message = await websocket.recv()
        data = json.loads(message)
        
        if data['type'] == 'login_success':
            print(f"Logged in successfully as {username}")
            global current_player_id
            current_player_id = data['playerId']
            # Démarrer la gestion des messages après connexion
            await handle_server_messages(websocket, username)
            break
        elif data['type'] == 'login_failed':
            print(f"Login failed for {username}. Trying another account...")
            available_accounts.remove(ai_account)  # Supprimer l'IA qui a échoué


async def handle_server_messages(websocket, username):
    """Gère les messages reçus du serveur après la connexion"""
    global current_game_id, current_invitation_id, invited_player_id

    async for message in websocket:
        data = json.loads(message)

        if data['type'] == 'login_success':
            print(f"Logged in as AI: {data['username']}")
            current_player_id = data['playerId']

            # Si l'invitation automatique est activée, chercher un joueur à inviter
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
            if data['currentPlayer'] == username:
                print("It's my turn!")
                display_board(data['board'])
                await make_move(websocket, data['board'])
            else:
                print("Waiting for my turn...")
                display_board(data['board'])

        elif data['type'] == 'game_over':
            print(f"Game over. Winner: {data['winner']}")
            display_board(data['board'])

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
        'gridSize': '10x10',  # Taille par défaut de la grille
        'difficulty': 15  # Difficulté par défaut
    }
    await websocket.send(json.dumps(invite_message))


async def make_move(websocket, board):
    """Effectuer un coup basé sur l'état du plateau"""
    global pause_duration
    safe_moves = []
    possible_moves = []

    width = len(board)
    height = len(board[0])

    # Collecter toutes les cases non révélées et analyser les cases révélées avec des mines adjacentes
    for x in range(width):
        for y in range(height):
            if not board[x][y]['revealed'] and not board[x][y]['flagged']:
                possible_moves.append({'x': x, 'y': y})
            elif board[x][y]['revealed'] and 'adjacentMines' in board[x][y] and board[x][y]['adjacentMines'] > 0:
                # Case révélée avec des mines adjacentes, analyser les voisins
                neighbors = get_neighbors(x, y, width, height)
                unrevealed_neighbors = []
                flagged_neighbors = 0

                for neighbor in neighbors:
                    if not board[neighbor['x']][neighbor['y']]['revealed']:
                        if board[neighbor['x']][neighbor['y']]['flagged']:
                            flagged_neighbors += 1
                        else:
                            unrevealed_neighbors.append(neighbor)

                # Si toutes les mines adjacentes sont marquées, les autres voisins sont sûrs
                if flagged_neighbors == board[x][y]['adjacentMines']:
                    safe_moves.extend(unrevealed_neighbors)

    # Décider quel coup jouer
    if safe_moves:
        selected_move = random.choice(safe_moves)
        print(f"Safe move found at ({selected_move['x']}, {selected_move['y']})")
    else:
        selected_move = random.choice(possible_moves)
        print(f"Random move chosen at ({selected_move['x']}, {selected_move['y']})")

    # Pause avant d'envoyer le coup
    time.sleep(pause_duration / 1000)  # Pause en secondes

    # Envoyer le coup au serveur
    move_message = {
        'type': 'reveal_cell',
        'game_id': current_game_id,
        'x': selected_move['x'],
        'y': selected_move['y']
    }
    await websocket.send(json.dumps(move_message))

    print(f"Move made at ({selected_move['x']}, {selected_move['y']})")


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


if __name__ == "__main__":
    uri = "ws://localhost:8080"
    asyncio.get_event_loop().run_until_complete(connect_to_server(uri))
