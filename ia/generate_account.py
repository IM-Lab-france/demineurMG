import asyncio
import websockets
import json
import random
import string
import logging

# Configuration du logger
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')

# Configuration du serveur WebSocket
SERVER_URI = "wss://fozzy.fr:9443"

# Configuration des comptes IA
NUM_IA = 20  # Nombre d'IA à créer
USERNAME_PREFIX = "ia_"
PASSWORD_LENGTH = 8
FILENAME = "ia_accounts.json"  # Fichier pour stocker les comptes IA

# Génère une chaîne aléatoire pour les noms d'utilisateur et mots de passe
def generate_random_string(length):
    characters = string.ascii_letters + string.digits
    return ''.join(random.choice(characters) for _ in range(length))

# Crée des comptes IA avec noms et mots de passe aléatoires
def create_ia_accounts(num_ia):
    accounts = []
    for _ in range(num_ia):
        username = USERNAME_PREFIX + generate_random_string(6)
        password = generate_random_string(PASSWORD_LENGTH)
        accounts.append({"username": username, "password": password})
    return accounts

# Sauvegarde les comptes IA dans un fichier JSON
def save_accounts_to_file(accounts, filename):
    with open(filename, "w") as file:
        json.dump(accounts, file, indent=4)
    logging.info(f"Les comptes IA ont été enregistrés dans le fichier {filename}")

# Enregistre un compte IA via WebSocket en utilisant la commande `register`
async def register_ia_account(websocket, username, password):
    register_data = {
        "type": "register",
        "username": username,
        "password": password
    }
    await websocket.send(json.dumps(register_data))
    logging.info(f"Tentative de création du compte pour {username}")
    response = await websocket.recv()
    data = json.loads(response)
    if data.get("type") == "register_success":
        logging.info(f"Compte {username} créé avec succès.")
    else:
        logging.error(f"Erreur lors de la création du compte {username}: {data.get('message')}")

# Gère la connexion WebSocket et enregistre plusieurs comptes IA
async def create_and_register_ia_accounts():
    accounts = create_ia_accounts(NUM_IA)
    async with websockets.connect(SERVER_URI) as websocket:
        for account in accounts:
            await register_ia_account(websocket, account["username"], account["password"])
    save_accounts_to_file(accounts, FILENAME)

# Lancer le script pour créer les comptes IA
if __name__ == "__main__":
    asyncio.run(create_and_register_ia_accounts())
