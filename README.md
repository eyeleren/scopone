# Scopone Scientifico (terminal)

Scopone multiplayer for Linux/macOS terminals, written in PHP. Includes player clients and a spectator client.

## Requirements
- PHP 8.1+ (uses `match()`)

## Quick start

### 1) Start the server
```sh
php server.php 9000
```

### 2) Connect 4 players (separate terminals)
```sh
php client.php 127.0.0.1 9000 Player1
php client.php 127.0.0.1 9000 Player2
php client.php 127.0.0.1 9000 Player3
php client.php 127.0.0.1 9000 Player4
```

### 3) Optional: connect a spectator
```sh
php spectator.php 127.0.0.1 9000
```

## How to play (client)
- Wait until 4 players have joined.
- On your turn, type the card index shown in your hand (`Indice carta (0-N)`).
- `Tavolo:` shows the cards currently on the table.
- `Ultima mossa:` shows the most recent move and is kept across screen refreshes.

### Last move log format
- Play:  
  `[PLAY] <player> mette <carta>`
- Capture:  
  `[CAPTURE] <player> prende <carte prese> con <carta giocata>`
- If bonuses happen, the client appends:  
  `| 🧹 SCOPA`, `| ⚜️ SETTEBELLO`, `| 👑 RE BELLO`

## Card legend (deck)
The deck is the Italian 40-card deck: **4 suits × 10 ranks**.

### Suits
- **Spade** = `⚔️`
- **Denari** = `💰`
- **Coppe** = `🍷`
- **Bastoni** = `🪵`

### Ranks (values)
- `1` = **A** (Asso)
- `2` = **2**
- `3` = **3**
- `4` = **4**
- `5` = **5**
- `6` = **6**
- `7` = **7**
- `8` = **🧙** (Fante / “Signore”)
- `9` = **🐴** (Cavallo)
- `10` = **👑** (Re)

### Special highlights
In the client UI (and server logs), some cards are highlighted with a star:
- **Settebello**: `7 Denari` → `⭐7💰`
- **Re bello**: `10 Denari` → `⭐👑💰`

## Rules implemented (engine)
Game logic is in [`classes/Game.php`](classes/Game.php).

Notable rules currently enforced:
- The first move of the whole game cannot be an **Asso** (`value === 1`).
- **Asso** on a non-empty table takes **all** table cards.
- **SCOPA is never awarded when the played card is an Asso**.

## Project files
- Server: [`server.php`](server.php)
- Player client: [`client.php`](client.php)
- Spectator: [`spectator.php`](spectator.php)
- Engine: [`classes/`](classes/)

## Notes / TODO
See: [`TODO.md`](TODO.md)


