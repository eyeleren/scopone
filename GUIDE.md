# How to play

1. Start the server:
   ```
   php server.php # (default port is 9000)
   php server.php 9000
   ```

2. Connect as a player:
   ```
   php client.php Player1
   php client.php 127.0.0.1 9000 Player1
   ```

3. Connect as a spectator:
   ```
   php spectator.php
   php spectator.php 127.0.0.1 9000
   ```