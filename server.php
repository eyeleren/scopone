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
        return "⭐{$rankEmoji}{$suitEmoji}";
    }
    return "{$rankEmoji}{$suitEmoji}";
}

$clients = [];
$playerCount = 0;
$game = new Game();
$lastLobbyBroadcast = time();
$clientMeta = []; // mappa socket->endpoint

// Handshake nuovo round
$pendingNextRound = false;
$nextRoundNumber = null;
$roundReady = [];

while (true) {
    $read = $clients;
    $read[] = $server;

    stream_select($read, $write, $except, 0, 200000);

    // Accept new connections
    if (in_array($server, $read)) {
        $conn = stream_socket_accept($server);
        if (!$conn) continue;
        stream_set_blocking($conn, false);
        stream_set_write_buffer($conn, 0);
        $meta = stream_socket_get_name($conn, true);
        $clients[] = $conn;
        $playerCount++;
        $clientMeta[(int)$conn] = $meta;

        $role = $playerCount <= 4 ? 'player' : 'spectator';
        $id = $playerCount;
        $game->registerPlayer($id, "Player$id", $role);

        fwrite($conn, json_encode([
            'action' => 'welcome',
            'payload' => [
                'id' => $id,
                'role' => $role,
                'players' => count($game->players),
                'needed'  => 4
            ]
        ]) . "\n");

        $lobbyMsg = json_encode([
            'action'=>'lobby',
            'players'=>count($game->players),
            'needed'=>4
        ]);
        foreach ($clients as $c) { @fwrite($c, $lobbyMsg."\n"); }

        // Log immediato solo per spettatori; per i player aspettiamo il nome reale
        if ($role === 'spectator') {
            echo "New spectator connected ($meta)\n";
        }
        // RIMOSSO avvio immediato: aspettiamo conferma nomi
        // if ($game->isReady()) {
        //     $game->startRound();
        //     $game->broadcastState($clients);
        // }
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
                            $pname = $game->players[$ev['player']]->name;
                            echo "[CAPTURE] {$pname} prende: $cardsStr\n";
                        } else {
                            $pname = $game->players[$ev['player']]->name;
                            echo "[PLAY] {$pname} mette " . emojiCard($ev['card']) . "\n";
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
                        $pname = $game->players[$ev['player']]->name;
                        echo "[ANNOUNCE] $label $pname\n";
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
                        'players'=>[$game->players[0]->name,$game->players[2]->name],
                        'points'=>$score['roundPoints']['A'],
                        'total'=>$score['teamScores']['A']
                    ],
                    'coppiaB'=>[
                        'players'=>[$game->players[1]->name,$game->players[3]->name],
                        'points'=>$score['roundPoints']['B'],
                        'total'=>$score['teamScores']['B']
                    ],
                    'notes'=>$score['notes']
                ]);
                foreach ($clients as $c) @fwrite($c, $summary."\n");
                echo "[ROUND END] Round {$game->round} | A +{$score['roundPoints']['A']} (Tot {$score['teamScores']['A']}) "
                    . "| B +{$score['roundPoints']['B']} (Tot {$score['teamScores']['B']})\n";
                foreach ($score['notes'] as $n) echo "  - $n\n";

                $winner = $game->checkWinner();
                if ($winner !== null) {
                    $msgWin = json_encode(['action'=>'game_over','msg'=>"Vince la Coppia $winner"]);
                    foreach ($clients as $c) @fwrite($c, $msgWin."\n");
                    echo "*** GAME OVER: Coppia {$winner} vince | Score finale A {$game->teamScores['A']} - B {$game->teamScores['B']} ***\n";
                } else {
                    // 👉 Avvia handshake per prossimo round
                    $pendingNextRound = true;
                    $nextRoundNumber = $game->round + 1;
                    $roundReady = [];
                    $prep = json_encode([
                        'action'=>'round_prepare',
                        'nextRound'=>$nextRoundNumber,
                        'needed'=>4
                    ]);
                    foreach ($clients as $c) @fwrite($c, $prep."\n");
                    echo "[WAIT] In attesa che tutti i giocatori confermino per round $nextRoundNumber...\n";
                }
            }
        } elseif ($msg['action'] === 'join') {
            $nick = trim($msg['nick'] ?? '');
            $mode = $msg['mode'] ?? 'player';
            // $idx = $game->isReady() ? null : ($msg['id'] ?? null); // optional
            // Trova indice player legato a questo socket
            $playerIdx = null;
            foreach ($game->players as $pi => $pl) {
                // match by provisional auto-name "PlayerX" (1-based id)
                if ($pl->name === "Player".($pi+1)) {
                    // assume order mapping
                }
            }
            // Usa l'ordine di connessione: playerIdx = count(players)-1
            $playerIdx = count($game->players) - 1;
            if ($mode === 'player' && $playerIdx !== null) {
                if ($nick === '' || mb_strlen($nick) > 20) {
                    @fwrite($client, json_encode(['action'=>'error','msg'=>'Nome non valido']) . "\n");
                } elseif (!$game->setPlayerName($playerIdx, $nick)) {
                    @fwrite($client, json_encode(['action'=>'error','msg'=>'Nome già in uso']) . "\n");
                } else {
                    @fwrite($client, json_encode([
                        'action'=>'name_ack',
                        'msg'=>'OK',
                        'name'=>$nick
                    ]) . "\n");

                    $metaStr = $clientMeta[(int)$client] ?? 'unknown';
                    // Log del nome reale (solo la prima volta o rename)
                    echo "New player connected ($metaStr) as {$nick}\n";

                    $lobbyMsg = json_encode([
                        'action'=>'lobby',
                        'players'=>count($game->players),
                        'needed'=>4
                    ]);
                    foreach ($clients as $c) { @fwrite($c, $lobbyMsg."\n"); }

                    if ($game->isReady() && $game->allNamesConfirmed() && !$game->started) {
                        $game->startRound();
                        $game->broadcastState($clients);
                    }
                }
            }
        } elseif ($msg['action'] === 'round_ready') {
            // Conferma giocatore per avvio prossimo round
            if ($pendingNextRound && isset($msg['playerId'])) {
                $pid = (int)$msg['playerId'];
                if (($game->roles[$pid] ?? null) === 'player') {
                    $roundReady[$pid] = true;
                    $readyCount = count($roundReady);
                    // Broadcast progresso
                    $prog = json_encode([
                        'action'=>'round_progress',
                        'nextRound'=>$nextRoundNumber,
                        'ready'=>$readyCount,
                        'total'=>4
                    ]);
                    foreach ($clients as $c) @fwrite($c, $prog."\n");
                    echo "[READY] Player{$pid} pronto ($readyCount/4) per round $nextRoundNumber\n";
                    if ($readyCount === 4) {
                        // Tutti pronti: avvia
                        $game->round++;
                        $game->startRound();
                        $pendingNextRound = false;
                        $nextRoundNumber = null;
                        echo "[START] Round {$game->round} avviato.\n";
                        $game->broadcastState($clients);
                    }
                }
            }
        }
    }
}
