<?php

require_once __DIR__ . '/classes/Game.php';

$port = $argv[1] ?? 12345;
$address = '0.0.0.0';

$server = stream_socket_server("tcp://$address:$port", $errno, $errstr);
if (!$server) {
    die("Server error: $errstr ($errno)\n");
}

echo "🃏 SCOPONE SCIENTIFICO server running on port $port...\n";

// 👇 Added emoji card helper (same style of client)
function emojiCard(array $c): string {
    $suitMap = [
        'Spade'   => '⚔️',
        'Denari'  => '💰',
        'Coppe'   => '🍷',
        'Bastoni' => '🪵',
    ];
    $rankEmoji = match($c['value'] ?? null) {
        1 => 'A',
        2 => '2',
        3 => '3',
        4 => '4',
        5 => '5',
        6 => '6',
        7 => '7',
        8 => '🧙',
        9 => '🐴',
        10 => '👑',
        default => '?'
    };
    $suitEmoji = $suitMap[$c['suit'] ?? ''] ?? '?';
    if (($c['suit'] ?? '') === 'Denari' && in_array($c['value'], [7,10], true)) {
        return "⭐ {$rankEmoji}{$suitEmoji}";
    }
    return "{$rankEmoji}{$suitEmoji}";
}

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
        stream_set_blocking($conn, false); // was true: make non-blocking
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

    // Read player input (only from ready sockets, non-blocking)
    foreach ($read as $client) {
        if ($client === $server) continue;
        $data = fgets($client);
        if ($data === false) {
            if (feof($client)) {
                // remove disconnected client
                $idx = array_search($client, $clients, true);
                if ($idx !== false) {
                    fclose($client);
                    array_splice($clients, $idx, 1);
                }
            }
            continue;
        }
        $msg = json_decode(trim($data), true);
        if (!$msg) continue;

        if ($msg['action'] === 'play') {
            $result = $game->handlePlay($msg['payload']['playerId'], $msg['payload']['cardIndex']);

            if (empty($result['ok'])) {
                @fwrite($client, json_encode([
                    'action'=>'error',
                    'playerId'=>$msg['payload']['playerId'],
                    'msg'=>$result['error']
                ])."\n");
                if (str_contains($result['error'], 'asso')) {
                    echo "[RULE] Player{$msg['payload']['playerId']} blocked first-turn Asso\n";
                } else {
                    echo "[ERROR] ".$result['error']."\n";
                }
                $game->broadcastState($clients);
                continue;
            }

            if (!empty($result['events'])) {
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

                        // 👇 Server-side verbose log with emojis
                        if ($ev['type'] === 'capture') {
                            $cardsStr = implode(' ', array_map('emojiCard', $ev['cards']));
                            echo "[CAPTURE] Player" . ($ev['player'] + 1) . " prende: $cardsStr\n";
                        } else {
                            echo "[PLAY] Player" . ($ev['player'] + 1) . " mette " . emojiCard($ev['card']) . "\n";
                        }
                    } elseif (in_array($ev['type'], ['SETTEBELLO','REBELLO','SCOPA'], true)) {
                        $out = json_encode([
                            'action'=>'announce',
                            'type'=>$ev['type'],
                            'who'=>"Player".($ev['player']+1),
                            'player'=>$ev['player']
                        ]);
                        foreach ($clients as $c) @fwrite($c, $out."\n");

                        // 👇 Server announce log
                        $label = match($ev['type']) {
                            'SETTEBELLO' => '⚜️ SETTEBELLO',
                            'REBELLO'    => '👑 RE BELLO',
                            'SCOPA'      => '🧹 SCOPA',
                            default      => $ev['type']
                        };
                        echo "[ANNOUNCE] $label Player" . ($ev['player'] + 1) . "\n";
                    }
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
