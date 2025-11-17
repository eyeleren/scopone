<?php

require_once __DIR__ . '/classes/Game.php';

$port = $argv[1] ?? 12345;
$address = '0.0.0.0';

$server = stream_socket_server("tcp://$address:$port", $errno, $errstr);
if (!$server) {
    die("Server error: $errstr ($errno)\n");
}

echo "🂡 SCOPONE SCIENTIFICO server running on port $port...\n";

$clients = [];
$playerCount = 0;
$game = new Game();
$lastLobbyBroadcast = time();

while (true) {
    $read = $clients;
    $read[] = $server;

    stream_select($read, $write, $except, 0, 200000);

    // Accept new connections
    if (in_array($server, $read)) {
        $conn = stream_socket_accept($server);
        if (!$conn) continue;
        stream_set_blocking($conn, true);
        stream_set_write_buffer($conn, 0); // flush immediately
        $meta = stream_socket_get_name($conn, true);
        $clients[] = $conn;
        $playerCount++;

        $role = $playerCount <= 4 ? 'player' : 'spectator';
        $id = $playerCount;
        $game->registerPlayer($id, "Player$id", $role);

        // include lobby info in welcome
        fwrite($conn, json_encode([
            'action' => 'welcome',
            'payload' => [
                'id' => $id,
                'role' => $role,
                'players' => count($game->players),
                'needed'  => 4
            ]
        ]) . "\n");

        // broadcast lobby (ensure everyone gets fresh count)
        $lobbyMsg = json_encode([
            'action'=>'lobby',
            'players'=>count($game->players),
            'needed'=>4
        ]);
        foreach ($clients as $c) { @fwrite($c, $lobbyMsg."\n"); }

        echo "New $role connected ($meta)\n";

        if ($game->isReady()) {
            $game->startRound();
            $game->broadcastState($clients);
        }
    }

    // Periodic lobby updates until game start
    if (!$game->isReady()) {
        if (time() - $lastLobbyBroadcast >= 1) {
            $lastLobbyBroadcast = time();
            $lobbyMsg = json_encode([
                'action'=>'lobby',
                'players'=>count($game->players),
                'needed'=>4
            ]);
            foreach ($clients as $c) { @fwrite($c, $lobbyMsg."\n"); }
        }
    }

    // Read player input
    foreach ($clients as $client) {
        $data = fgets($client);
        if ($data === false) continue;
        $msg = json_decode(trim($data), true);
        if (!$msg) continue;

        if ($msg['action'] === 'play') {
            $result = $game->handlePlay($msg['payload']['playerId'], $msg['payload']['cardIndex']);

            foreach ($result['events'] as $ev) {
                if ($ev['type'] === 'capture' || $ev['type'] === 'place') {
                    $out = json_encode([
                        'action' => 'event',
                        'type'   => $ev['type'],
                        'player' => $ev['player'],
                        'cards'  => $ev['cards'] ?? null,
                        'card'   => $ev['card'] ?? null
                    ]);
                    foreach ($clients as $c) @fwrite($c, $out . "\n");
                } elseif ($ev['type'] === 'SETTEBELLO' || $ev['type'] === 'REBELLO') {
                    $out = json_encode([
                        'action'=>'announce',
                        'type'=>$ev['type'],
                        'who'=>"Player".($ev['player']+1),
                        'player'=>$ev['player']
                    ]);
                    foreach ($clients as $c) @fwrite($c, $out."\n");
                }
            }

            $game->broadcastState($clients);

            if (!empty($result['roundEnd'])) {
                $score = $game->scoreRound();
                $summary = json_encode([
                    'action'=>'round_summary',
                    'round'=>$game->round,
                    'coppiaA'=>[
                        'players'=>['Player1','Player3'],
                        'points'=>$score['roundPoints']['A'],
                        'total'=>$score['teamScores']['A']
                    ],
                    'coppiaB'=>[
                        'players'=>['Player2','Player4'],
                        'points'=>$score['roundPoints']['B'],
                        'total'=>$score['teamScores']['B']
                    ],
                    'notes'=>$score['notes']
                ]);
                foreach ($clients as $c) @fwrite($c, $summary."\n");

                $winner = $game->checkWinner();
                if ($winner !== null) {
                    $msgWin = json_encode(['action'=>'game_over','msg'=>"Vince la Coppia $winner"]);
                    foreach ($clients as $c) @fwrite($c, $msgWin."\n");
                } else {
                    $game->round++;
                    $game->startRound();
                    $game->broadcastState($clients);
                }
            }
        }
    }
}
