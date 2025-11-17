# How to play

1. Start the server:
   ```
   php server.php 9000
   ```

2. Connect as a player (use 127.0.0.1 locally, not 0.0.0.0):
   ```
   php client.php 127.0.0.1 9000 Player1
   php client.php 127.0.0.1 9000 Player2
   php client.php 127.0.0.1 9000 Player3
   php client.php 127.0.0.1 9000 Player4
   ```

3. Connect as a spectator:
   ```
   php spectator.php 127.0.0.1 9000
   ```