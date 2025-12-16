<?php

require_once __DIR__ . '/classes/Game.php';

$port = $argv[1] ?? 9000;
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
$game = new Game();
$lastLobbyBroadcast = time();
$clientMeta = []; // socketId -> endpoint
$socketPlayerIndex = []; // socketId -> player index (0..3)

// NEW: identity maps
$socketRole = [];     // socketId -> 'player'|'spectator'|'pending'
$socketPlayerId = []; // socketId -> 1-based playerId (only for players)
$nextSpectatorId = 1; // for welcome payload only

// Handshake nuovo round
$pendingNextRound = false;
$nextRoundNumber = null;
$roundReady = [];

// helper: broadcast lobby (players only)
function broadcastLobby(array $clients, Game $game): void {
    $lobbyMsg = json_encode([
        'action'=>'lobby',
        'players'=>count($game->players),
        'needed'=>4
    ]);
    foreach ($clients as $c) { @fwrite($c, $lobbyMsg."\n"); }
}

while (true) {
    $read = $clients;
    $read[] = $server;

    stream_select($read, $write, $except, 0, 200000);

    // Accept new connections (NO automatic role assignment here)
    if (in_array($server, $read, true)) {
        $conn = stream_socket_accept($server);
        if (!$conn) continue;

        stream_set_blocking($conn, false);
        stream_set_write_buffer($conn, 0);

        $meta = stream_socket_get_name($conn, true);
        $clients[] = $conn;

        $sockId = (int)$conn;
        $clientMeta[$sockId] = $meta;

        // pending until we receive {"action":"join", ...}
        $socketRole[$sockId] = 'pending';
        $socketPlayerId[$sockId] = null;

        // no lobby broadcast yet; wait for join (player/spectator)
    }

    // Periodic lobby updates until game start
    if (!$game->isReady()) {
        if (time() - $lastLobbyBroadcast >= 1) {
            $lastLobbyBroadcast = time();
            broadcastLobby($clients, $game);
        }
    }

    foreach ($read as $client) {
        if ($client === $server) continue;

        $data = fgets($client);
        if ($data === false) {
            if (feof($client)) {
                $sockId = (int)$client;

                // If a PLAYER disconnects -> end for everyone (spectator leaving does NOT end)
                $role = $socketRole[$sockId] ?? null;
                if ($role === 'player') {
                    $meta = $clientMeta[$sockId] ?? 'unknown';
                    $pIdx = $socketPlayerIndex[$sockId] ?? null;

                    $pname = 'Player?';
                    if ($pIdx !== null && isset($game->players[$pIdx])) {
                        $pname = $game->players[$pIdx]->name;
                    } elseif (!empty($socketPlayerId[$sockId])) {
                        $pname = "Player" . $socketPlayerId[$sockId];
                    }

                    $why = $game->started ? 'Partita terminata' : 'Lobby annullata';
                    echo "[DISCONNECT] {$pname} left ({$meta}) -> {$why}.\n";

                    $out = json_encode([
                        'action'     => 'game_over',
                        'msg'        => "{$why}: {$pname} si è disconnesso.",
                        'winner'     => null,
                        'teamScores' => $game->teamScores
                    ]);

                    foreach ($clients as $c) { @fwrite($c, $out . "\n"); }

                    foreach ($clients as $c) { @fclose($c); }
                    @fclose($server);
                    exit(0);
                }

                // spectator/pending disconnect: just remove
                $idx = array_search($client, $clients, true);
                if ($idx !== false) {
                    fclose($client);
                    array_splice($clients, $idx, 1);
                }

                unset(
                    $clientMeta[$sockId],
                    $socketPlayerIndex[$sockId],
                    $socketRole[$sockId],
                    $socketPlayerId[$sockId]
                );
            }
            continue;
        }

        $msg = json_decode(trim($data), true);
        if (!is_array($msg)) continue;

        $sockId = (int)$client;
        $role = $socketRole[$sockId] ?? 'pending';

        // --- JOIN MUST BE FIRST ---
        if (($msg['action'] ?? '') !== 'join' && $role === 'pending') {
            @fwrite($client, json_encode([
                'action' => 'error',
                'msg'    => 'Devi inviare join prima di qualsiasi altra azione.'
            ]) . "\n");
            continue;
        }

        if (($msg['action'] ?? '') === 'join') {
            $mode = $msg['mode'] ?? 'player';
            $nick = trim((string)($msg['nick'] ?? ''));

            // prevent double-join
            if ($role !== 'pending') {
                @fwrite($client, json_encode([
                    'action' => 'error',
                    'msg'    => 'Sei già registrato.'
                ]) . "\n");
                continue;
            }

            // Decide final role based on requested mode + slots available
            if ($mode === 'player' && count($game->players) < 4) {
                // Validate name; fallback to PlayerN if invalid/duplicate
                if ($nick === '' || mb_strlen($nick) > 20 || $game->isNameTaken($nick)) {
                    $base = 'Player' . (count($game->players) + 1);
                    $candidate = $base;
                    $k = 2;
                    while ($game->isNameTaken($candidate)) {
                        $candidate = $base . $k;
                        $k++;
                    }
                    $nick = $candidate;
                }

                $playerId = count($game->players) + 1;

                $socketRole[$sockId] = 'player';
                $socketPlayerId[$sockId] = $playerId;

                $game->registerPlayer($playerId, $nick, 'player');
                $socketPlayerIndex[$sockId] = count($game->players) - 1;

                @fwrite($client, json_encode([
                    'action' => 'welcome',
                    'payload' => [
                        'id'      => $playerId,
                        'role'    => 'player',
                        'name'    => $nick,
                        'players' => count($game->players),
                        'needed'  => 4
                    ]
                ]) . "\n");

                $metaStr = $clientMeta[$sockId] ?? 'unknown';
                echo "New player connected ($metaStr) as {$nick}\n";

                broadcastLobby($clients, $game);

                if ($game->isReady() && !$game->started) {
                    $game->startRound();

                    // NEW: server-side game start message
                    $a1 = $game->players[0]->name ?? 'Player1';
                    $b1 = $game->players[1]->name ?? 'Player2';
                    $a2 = $game->players[2]->name ?? 'Player3';
                    $b2 = $game->players[3]->name ?? 'Player4';
                    echo "[START] Partita iniziata! Round {$game->round}\n";
                    echo "        Coppia A: {$a1} + {$a2}\n";
                    echo "        Coppia B: {$b1} + {$b2}\n";

                    $game->broadcastState($clients);
                }

            } else {
                // spectator (either requested, or player slots full)
                $socketRole[$sockId] = 'spectator';
                $socketPlayerId[$sockId] = null;

                $sid = $nextSpectatorId++;
                @fwrite($client, json_encode([
                    'action' => 'welcome',
                    'payload' => [
                        'id'      => $sid,
                        'role'    => 'spectator',
                        'name'    => ($nick !== '' ? $nick : 'Spectator'),
                        'players' => count($game->players),
                        'needed'  => 4
                    ]
                ]) . "\n");

                $metaStr = $clientMeta[$sockId] ?? 'unknown';
                echo "New spectator connected ($metaStr)\n";

                // send current lobby snapshot so spectator sees it immediately
                broadcastLobby([$client], $game);

                // if game already started, push a state snapshot right away
                if ($game->started) {
                    $state = $game->buildState(null, false);
                    @fwrite($client, json_encode(['action'=>'state','payload'=>$state]) . "\n");
                }
            }

            continue;
        }

        // --- Existing actions (unchanged) ---
        if (($msg['action'] ?? '') === 'play') {
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
                $moveBonuses = [];
                foreach ($result['events'] as $ev0) {
                    if (!in_array($ev0['type'], ['SETTEBELLO','REBELLO','SCOPA'], true)) continue;
                    $moveBonuses[] = match($ev0['type']) {
                        'SETTEBELLO' => '⚜️ SETTEBELLO',
                        'REBELLO'    => '👑 RE BELLO',
                        'SCOPA'      => '🧹 SCOPA',
                        default      => $ev0['type']
                    };
                }
                $bonusSuffix = empty($moveBonuses) ? '' : (' | ' . implode(' | ', $moveBonuses));
                $bonusApplied = false;

                foreach ($result['events'] as $ev) {
                    if ($ev['type'] === 'capture' || $ev['type'] === 'place') {
                        $out = json_encode([
                            'action' => 'event',
                            'type'   => $ev['type'],
                            'player' => $ev['player'],
                            'taken'  => $ev['taken'] ?? null,
                            'cards'  => $ev['cards'] ?? null,
                            'card'   => $ev['card'] ?? null
                        ]);
                        foreach ($clients as $c) @fwrite($c, $out . "\n");

                        $suffix = (!$bonusApplied ? $bonusSuffix : '');

                        if ($ev['type'] === 'capture') {
                            $pname = $game->players[$ev['player']]->name;

                            $takenArr = $ev['taken'] ?? [];
                            $takenStr = implode(' ', array_map('emojiCard', $takenArr));

                            $playedCard = $ev['card'] ?? null;
                            $playedStr = is_array($playedCard) ? emojiCard($playedCard) : '??';

                            echo "[CAPTURE] {$pname} prende {$takenStr} con {$playedStr}{$suffix}\n";
                        } else {
                            $pname = $game->players[$ev['player']]->name;
                            echo "[PLAY] {$pname} mette " . emojiCard($ev['card']) . "{$suffix}\n";
                        }

                        $bonusApplied = true;

                    } elseif (in_array($ev['type'], ['SETTEBELLO','REBELLO','SCOPA'], true)) {
                        $out = json_encode([
                            'action'=>'announce',
                            'type'=>$ev['type'],
                            'who'=>"Player".($ev['player']+1),
                            'player'=>$ev['player']
                        ]);
                        foreach ($clients as $c) @fwrite($c, $out."\n");

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
                $winner = $game->checkWinner();

                $summary = json_encode([
                    'action' => 'round_summary',
                    'round'  => $game->round,
                    'final'  => ($winner !== null),   // NEW
                    'winner' => $winner,              // NEW: 'A'|'B'|null
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

                if ($winner !== null) {
                    // CHANGED: include winner + final teamScores so clients don't rely on stale "state"
                    $msgWin = json_encode([
                        'action'     => 'game_over',
                        'msg'        => "Vince la Coppia $winner",
                        'winner'     => $winner,               // 'A' | 'B'
                        'teamScores' => $game->teamScores      // FINAL totals (post scoreRound)
                    ]);
                    foreach ($clients as $c) @fwrite($c, $msgWin."\n");

                    echo "*** GAME OVER: Coppia {$winner} vince | Score finale A {$game->teamScores['A']} - B {$game->teamScores['B']} ***\n";
                } else {
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
        } elseif (($msg['action'] ?? '') === 'round_ready') {
            if ($pendingNextRound && isset($msg['playerId'])) {
                $pid = (int)$msg['playerId'];
                if (($game->roles[$pid] ?? null) === 'player') {
                    $roundReady[$pid] = true;
                    $readyCount = count($roundReady);

                    $prog = json_encode([
                        'action'=>'round_progress',
                        'nextRound'=>$nextRoundNumber,
                        'ready'=>$readyCount,
                        'total'=>4
                    ]);
                    foreach ($clients as $c) @fwrite($c, $prog."\n");

                    // NEW: print real player name instead of "Player{pid}"
                    $pname = $game->players[$pid - 1]->name ?? ("Player{$pid}");
                    echo "[READY] {$pname} pronto ({$readyCount}/4) per round {$nextRoundNumber}\n";

                    if ($readyCount === 4) {
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
