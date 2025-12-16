<?php

const DEBUG = false;

// $ip   = $argv[1] ?? '127.0.0.1';
// $port = $argv[2] ?? 12345;
$ip   = '127.0.0.1';
$port = 9000;
$name = $argv[1] ?? 'Player';

$conn = stream_socket_client("tcp://$ip:$port", $errno, $errstr, 30);
if (!$conn) die("Connection failed: $errstr ($errno)\n");

// NEW: handshake in blocking mode; ignore lobby until welcome
stream_set_blocking($conn, true);

fwrite($conn, json_encode([
    'action' => 'join',
    'nick'   => $name,
    'mode'   => 'player'
]) . "\n");

$welcome = null;
while (true) {
    $line = fgets($conn);
    if ($line === false) die("No welcome message from server\n");

    $m = json_decode(trim($line), true);
    if (!is_array($m)) continue;

    if (($m['action'] ?? '') === 'lobby') {
        // server may broadcast lobby before welcome; ignore here
        continue;
    }

    if (($m['action'] ?? '') === 'error') {
        die("Join error: " . ($m['msg'] ?? 'Errore') . "\n");
    }

    if (($m['action'] ?? '') === 'welcome' && isset($m['payload'])) {
        $welcome = $m;
        break;
    }

    // ignore any other message types during handshake
}

$playerId = $welcome['payload']['id'] ?? null;
$role = $welcome['payload']['role'] ?? 'player';
$assignedName = $welcome['payload']['name'] ?? $name;

// after handshake, switch back to non-blocking like before
stream_set_blocking($conn, false);

$lastPromptToken = null;

// 👇 NEW: last move (persisted across screen clears)
$lastMoveText = '(nessuna)';
$lastMovePlayerIndex = null;

// 👇 NEW: keep last state for round-end recap (captures/hand lines)
$payload = null;

// 👇 NEW: next-round readiness UI state
$waitingNext = false;
$nextRound = null;
$roundReadyCount = 0;
$roundTotal = 4;
$sentReadyForNextRound = null; // int|null (nextRound number)

echo "Connected as $assignedName ($role)\n\n";

// NEW: ANSI color helpers (same terminal style as spectator.php)
const C_RESET = "\033[0m";
const C_RED   = "\033[1;31m";
const C_GREEN_BG = "\033[1;42m";
const C_CYAN  = "\033[1;36m";
const C_WHITE = "\033[1;37m";
const C_YELLOW= "\033[1;33m";
const C_BLUE  = "\033[1;34m";

function c(string $ansi, string $text): string { return $ansi . $text . C_RESET; }

function showStatusAnimation(string $mode, int $have=0, int $need=0) {
    if ($have >= $need) return;
    static $i = 0;
    $frames = ["⏳","⌛","🕐","🕑","🕒","🕓","🕔","🕕","🕖","🕗","🕘","🕙","🕚"];
    $f = $frames[$i % count($frames)];
    $i++;
    echo "\r" . c(C_YELLOW, "In attesa giocatori $f ($have/$need) ");
}

// NEW: waiting animation for next round readiness
function showWaitNextRoundAnimation(int $round, int $ready, int $total): void {
    static $i = 0;
    $frames = ["⏳","⌛","🕐","🕑","🕒","🕓","🕔","🕕","🕖","🕗","🕘","🕙","🕚"];
    $f = $frames[$i % count($frames)];
    $i++;
    echo "\r" . c(C_YELLOW, "[WAIT] Prossimo round {$round}: pronti {$ready}/{$total} {$f}   ");
}

function emojiCard(array $c): string {
    if (isset($c['hidden'])) return '🂠';
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

    if (($c['suit'] ?? '') === 'Denari') {
        if (($c['value'] ?? 0) === 7) return "⭐{$rankEmoji}{$suitEmoji}";
        if (($c['value'] ?? 0) === 10) return "⭐{$rankEmoji}{$suitEmoji}";
    }
    return "{$rankEmoji}{$suitEmoji}";
}

function flushStdin(): void {
    while (($ch = fgetc(STDIN)) !== false) { /* discard */ }
}

// queue for messages received while we are waiting for user input
$inbox = [];

function promptCardIndex(int $max, $conn, array &$inbox, array &$payload): int {
    // Non-blocking prompt: wait for either keyboard OR server messages/disconnect
    stream_set_blocking(STDIN, false);
    stream_set_blocking($conn, false);

    $printed = false;

    while (true) {
        if (!$printed) {
            echo "Indice carta (0-$max): ";
            $printed = true;
        }

        $read = [$conn, STDIN];
        $write = null;
        $except = null;

        $n = @stream_select($read, $write, $except, 0, 200000); // 200ms
        if ($n === false) continue;

        // Socket readable => either data or EOF
        if (in_array($conn, $read, true)) {
            while (true) {
                $line = fgets($conn);

                if ($line === false) {
                    if (feof($conn)) {
                        system('clear');
                        echo "\n[DISCONNECT] Server disconnected. Closing client.\n";
                        exit(0);
                    }
                    break;
                }

                $m = json_decode(trim($line), true);
                if (!is_array($m)) continue;

                // If game ends while prompting, show immediately and exit
                if (($m['action'] ?? '') === 'game_over') {
                    system('clear');

                    $winner = $m['winner'] ?? null; // 'A'|'B'|null

                    // compute my team (fallback even if payload missing)
                    $myIndex = ($GLOBALS['playerId'] ?? 1) - 1;
                    $myTeam = is_array($payload) && isset($payload['players'][$myIndex]['team'])
                        ? ($payload['players'][$myIndex]['team'])
                        : (($myIndex === 0 || $myIndex === 2) ? 'A' : 'B');

                    $text = (string)($m['msg'] ?? 'FINE');

                    if ($winner === 'A' || $winner === 'B') {
                        $resultLine = ($winner === $myTeam) ? "HAI VINTO" : "HAI PERSO";
                        echo c(C_GREEN_BG, "*** {$resultLine} — {$text} ***") . "\n\n";
                    } else {
                        echo c(C_GREEN_BG, "*** {$text} ***") . "\n\n";
                    }

                    // CHANGED: prefer final scores from game_over payload
                    $scores = (isset($m['teamScores']) && is_array($m['teamScores']))
                        ? $m['teamScores']
                        : (is_array($payload) && isset($payload['teamScores']) ? $payload['teamScores'] : null);

                    if (is_array($scores)) {
                        $a = $scores['A'] ?? 0;
                        $b = $scores['B'] ?? 0;
                        echo "Score finale: " . c(C_WHITE, "A {$a}") . " | " . c(C_WHITE, "B {$b}") . "\n";
                    }

                    exit(0);
                }

                // Keep last known state while we’re paused in input
                if (($m['action'] ?? '') === 'state' && isset($m['payload']) && is_array($m['payload'])) {
                    $payload = $m['payload'];
                }

                // Queue everything else for the main loop
                $inbox[] = $m;
            }
        }

        // Keyboard readable
        if (in_array(STDIN, $read, true)) {
            $raw = fgets(STDIN);
            if ($raw === false) continue;

            $raw = trim($raw);
            if ($raw === '') { echo "Input vuoto.\n"; $printed = false; continue; }
            if (!ctype_digit($raw)) { echo "Deve essere un numero.\n"; $printed = false; continue; }

            $val = (int)$raw;
            if ($val < 0 || $val > $max) { echo "Fuori range.\n"; $printed = false; continue; }
            return $val;
        }
    }
}

stream_set_blocking($conn, false);
stream_set_blocking(STDIN, false);

$lobbyPlayers = 1;
$lobbyNeeded  = 4;

while (true) {
    // NEW: process queued messages first (arrived while prompting)
    if (!empty($inbox)) {
        $msg = array_shift($inbox);
    } else {
        $data = fgets($conn);

        if ($data === false) {
            if (feof($conn)) {
                system('clear');
                echo "\n[DISCONNECT] Server disconnected. Closing client.\n";
                exit(0);
            }
            usleep(50_000);
            continue;
        }

        $msg = json_decode(trim($data), true);
        if (!$msg) continue;
    }

    switch ($msg['action']) {
        case 'error':
            echo "\n" . c(C_RED, "[ERROR] " . ($msg['msg'] ?? 'Errore')) . "\n";
            break;

        case 'lobby':
            $lobbyPlayers = $msg['players'];
            $lobbyNeeded  = $msg['needed'];
            showStatusAnimation('lobby', $lobbyPlayers, $lobbyNeeded);
            break;

        case 'round_prepare':
            // server is waiting for 4 players to confirm readiness
            $waitingNext = true;
            $nextRound = (int)($msg['nextRound'] ?? 0);
            $roundReadyCount = 0;
            $roundTotal = (int)($msg['needed'] ?? 4);
            $sentReadyForNextRound = null;

            // show animated wait line (instead of plain text)
            showWaitNextRoundAnimation($nextRound, $roundReadyCount, $roundTotal);
            echo "\n";
            break;

        case 'round_progress':
            $waitingNext = true;
            $nextRound = (int)($msg['nextRound'] ?? ($nextRound ?? 0));
            $roundReadyCount = (int)($msg['ready'] ?? $roundReadyCount);
            $roundTotal = (int)($msg['total'] ?? $roundTotal);

            // show animated wait line
            showWaitNextRoundAnimation($nextRound, $roundReadyCount, $roundTotal);
            break;

        case 'round_summary':
            system('clear');

            $r = (int)($msg['round'] ?? 0);
            echo c(C_BLUE, "────────────── ROUND {$r} ──────────────") . "\n";

            $cA = $msg['coppiaA'] ?? null;
            $cB = $msg['coppiaB'] ?? null;
            if (is_array($cA) && is_array($cB)) {
                $aPlayers = implode(' + ', $cA['players'] ?? []);
                $bPlayers = implode(' + ', $cB['players'] ?? []);
                echo "Coppia A ({$aPlayers}): +".($cA['points'] ?? 0)."  (Tot ".($cA['total'] ?? 0).")\n";
                echo "Coppia B ({$bPlayers}): +".($cB['points'] ?? 0)."  (Tot ".($cB['total'] ?? 0).")\n";
            }

            echo "Dettagli:\n";
            foreach (($msg['notes'] ?? []) as $n) echo "  - {$n}\n";

            if (!empty($msg['final'])) {
                echo "\n" . c(C_YELLOW, "(Fine partita in arrivo...)") . "\n";
                break;
            }

            if ($role === 'player' && $playerId !== null) {
                echo "\n" . c(C_YELLOW, "Premi INVIO per confermare pronto al prossimo round...");

                stream_set_blocking(STDIN, true);
                fgets(STDIN);
                stream_set_blocking(STDIN, false);
                flushStdin();

                if ($nextRound !== null && $sentReadyForNextRound !== $nextRound) {
                    $sentReadyForNextRound = $nextRound;
                }
                fwrite($conn, json_encode([
                    'action'   => 'round_ready',
                    'playerId' => $playerId
                ]) . "\n");
            }
            break;

        case 'state':
            system('clear');
            $payload = $msg['payload'];

            // NEW: if the next round has started, stop showing the WAIT line
            if ($waitingNext && $nextRound !== null) {
                $currentRound = (int)($payload['round'] ?? 0);
                if ($currentRound >= (int)$nextRound) {
                    $waitingNext = false;
                    $nextRound = null;
                    $roundReadyCount = 0;
                    $roundTotal = 4;
                    $sentReadyForNextRound = null;
                }
            }

            $turnName = $payload['players'][$payload['turn']]['name'] ?? ('Player'.($payload['turn']+1));
            $myIndex = ($playerId ?? 1) - 1;
            $myTeam = $payload['players'][$myIndex]['team'] ?? (($myIndex===0||$myIndex===2)?'A':'B');

            $mateIndex = ($myTeam === 'A')
                ? ($myIndex === 0 ? 2 : 0)
                : ($myIndex === 1 ? 3 : 1);
            $mateName = $payload['players'][$mateIndex]['name'] ?? '??';

            echo c(C_CYAN, "─────────────── SCOPONE SCIENTIFICO ───────────────") . "\n";
            echo "Round: {$payload['round']} | Turno: " . c(C_WHITE, $turnName) . "\n";
            echo "Squadra: " . c(C_WHITE, "{$myTeam} (con {$mateName})") . "  |  ";
            echo "Punteggi " . c(C_WHITE, "A = {$payload['teamScores']['A']}") . " / " . c(C_WHITE, "B = {$payload['teamScores']['B']}") . "\n\n";

            foreach ($payload['players'] as $id => $p) {
                if ($id == ($playerId ?? 1) - 1) {
                    echo "Le tue carte:\n";
                    foreach ($p['hand'] as $idx => $card) {
                        echo " [$idx] " . emojiCard($card) . "\n";
                    }
                } else {
                    if (DEBUG || $role === 'spectator') {
                        echo "{$p['name']}:\n";
                        foreach ($p['hand'] as $idx => $card) {
                            echo "  " . emojiCard($card) . "\n";
                        }
                    } else {
                        echo "{$p['name']}: [" . count($p['hand']) . " carte]\n";
                    }
                }
            }

            echo "\nTavolo: " . (empty($payload['table']) ? '(vuoto)' :
                implode(' ', array_map('emojiCard', $payload['table']))) . "\n";

            echo "Ultima mossa: " . c(C_WHITE, $lastMoveText) . "\n";

            if ($waitingNext && $nextRound !== null) {
                // animated wait line even during state redraws
                showWaitNextRoundAnimation((int)$nextRound, (int)$roundReadyCount, (int)$roundTotal);
                echo "\n";
            }
            echo "\n";

            if ($role === 'player' && $payload['turn'] == ($playerId ?? 1) - 1) {
                flushStdin();
                $max = count($payload['players'][($playerId ?? 1) - 1]['hand']) - 1;
                $token = $payload['round'].'-'.$payload['turn'].'-'.$max;
                if ($max >= 0 && $lastPromptToken !== $token) {
                    $lastPromptToken = $token;

                    // CHANGED: prompt watches socket too (disconnect/game_over-safe)
                    $cardIndex = promptCardIndex($max, $conn, $inbox, $payload);

                    fwrite($conn, json_encode([
                        'action' => 'play',
                        'payload' => [
                            'playerId'  => $playerId,
                            'cardIndex' => $cardIndex
                        ]
                    ]) . "\n");
                }
            }
            break;

        case 'event': {
            // NEW: keep "Ultima mossa" working (server sends event before state broadcast)
            $type = (string)($msg['type'] ?? '');
            $pindex = (int)($msg['player'] ?? -1);

            $pname = "Player" . ($pindex + 1);
            if (is_array($payload) && isset($payload['players'][$pindex]['name'])) {
                $pname = $payload['players'][$pindex]['name'];
            }

            if ($type === 'capture') {
                $taken  = (isset($msg['taken']) && is_array($msg['taken'])) ? $msg['taken'] : ($msg['cards'] ?? []);
                $played = (isset($msg['card']) && is_array($msg['card'])) ? $msg['card'] : null;

                $takenStr  = is_array($taken) ? implode(' ', array_map('emojiCard', $taken)) : '';
                $playedStr = is_array($played) ? emojiCard($played) : '??';

                $lastMovePlayerIndex = $pindex;
                $lastMoveText = "[CAPTURE] {$pname} prende {$takenStr} con {$playedStr}";
            } elseif ($type === 'place') {
                $c = (isset($msg['card']) && is_array($msg['card'])) ? $msg['card'] : null;

                $lastMovePlayerIndex = $pindex;
                $lastMoveText = "[PLAY] {$pname} mette " . (is_array($c) ? emojiCard($c) : '??');
            }

            break;
        }

        case 'announce': {
            // NEW: server sends announce with {type, player}, not {msg}
            $pindex = (int)($msg['player'] ?? -1);

            $label = match((string)($msg['type'] ?? '')) {
                'SETTEBELLO' => '⚜️ SETTEBELLO',
                'REBELLO'    => '👑 RE BELLO',
                'SCOPA'      => '🧹 SCOPA',
                default      => (string)($msg['type'] ?? 'ANNOUNCE'),
            };

            // Append bonus only to the move that generated it
            if ($lastMovePlayerIndex === $pindex) {
                $lastMoveText .= " | {$label}";
            }

            // (optional) don't spam extra lines; state redraw will show it
            break;
        }

        case 'game_over':
            system('clear');

            $winner = $msg['winner'] ?? null; // 'A'|'B'|null

            $myIndex = ($playerId ?? 1) - 1;
            $myTeam = is_array($payload) && isset($payload['players'][$myIndex]['team'])
                ? ($payload['players'][$myIndex]['team'])
                : (($myIndex === 0 || $myIndex === 2) ? 'A' : 'B');

            $text = (string)($msg['msg'] ?? 'FINE');

            if ($winner === 'A' || $winner === 'B') {
                $resultLine = ($winner === $myTeam) ? "HAI VINTO" : "HAI PERSO";
                echo c(C_GREEN_BG, "*** {$resultLine} — {$text} ***") . "\n\n";
            } else {
                echo c(C_GREEN_BG, "*** {$text} ***") . "\n\n";
            }

            // CHANGED: prefer final scores from game_over payload
            $scores = (isset($msg['teamScores']) && is_array($msg['teamScores']))
                ? $msg['teamScores']
                : (is_array($payload) && isset($payload['teamScores']) ? $payload['teamScores'] : null);

            if (is_array($scores)) {
                $a = $scores['A'] ?? 0;
                $b = $scores['B'] ?? 0;
                echo "Score finale: " . c(C_WHITE, "A {$a}") . " | " . c(C_WHITE, "B {$b}") . "\n";
            }

            exit(0);

        default:
            echo "\n" . c(C_RED, "[ERROR] Azione sconosciuta: " . ($msg['action'] ?? '')) . "\n";
            break;
    }
}