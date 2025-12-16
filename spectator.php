<?php
// spectator.php
// Usage: php spectator.php <SERVER_IP> <PORT>
// ex: php spectator.php 192.168.1.42 9000

$server = $argv[1] ?? '127.0.0.1';
$port   = isset($argv[2]) ? intval($argv[2]) : 9000;

$socket = @stream_socket_client("tcp://{$server}:{$port}", $errno, $errstr, 5);
if (!$socket) {
    echo "Connessione fallita: $errstr ($errno)\n";
    exit(1);
}

stream_set_blocking($socket, true);
stream_set_blocking(STDIN, true);

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

    if (($c['suit'] ?? '') === 'Denari' && in_array(($c['value'] ?? 0), [7, 10], true)) {
        return "⭐{$rankEmoji}{$suitEmoji}";
    }
    return "{$rankEmoji}{$suitEmoji}";
}

// NEW: join FIRST (server replies welcome after deciding role)
@fwrite($socket, json_encode([
    'action' => 'join',
    'nick'   => 'Spectator',
    'mode'   => 'spectator'
]) . "\n");

$line = fgets($socket);
if ($line === false) {
    echo "Nessun messaggio di welcome dal server.\n";
    exit(1);
}
$welcome = json_decode(trim($line), true);
if (!is_array($welcome) || ($welcome['action'] ?? '') !== 'welcome') {
    echo "Welcome non valido: {$line}\n";
    exit(1);
}

$role = $welcome['payload']['role'] ?? 'spectator';
echo "\033[1;37mSCOPONE SCIENTIFICO — Connected as {$role}\033[0m\n\n";

$s = null;
$lastMoveText = '(nessuna)';
$lastMovePlayerIndex = null;

$lobbyPlayers = 0;
$lobbyNeeded  = 4;

$waitingNext = false;
$nextRound = null;
$roundReady = 0;
$roundTotal = 4;

while (!feof($socket)) {
    $raw = fgets($socket);

    // NEW: if server disconnects, exit spectator too
    if ($raw === false) {
        if (feof($socket)) break;
        continue;
    }

    $raw = trim($raw);
    if ($raw === '') continue;

    $msg = json_decode($raw, true);
    if (!is_array($msg)) continue;

    switch ($msg['action'] ?? '') {
        case 'error':
            echo "\n[ERROR] " . ($msg['msg'] ?? 'Errore') . "\n";
            break;

        case 'lobby':
            $lobbyPlayers = (int)($msg['players'] ?? 0);
            $lobbyNeeded  = (int)($msg['needed'] ?? 4);
            echo "\rIn attesa giocatori ({$lobbyPlayers}/{$lobbyNeeded}) ";
            break;

        case 'state':
            $s = $msg['payload'] ?? null;
            if (!is_array($s)) break;

            // NEW: if the next round has started, stop showing the WAIT line
            if ($waitingNext && $nextRound !== null) {
                $currentRound = (int)($s['round'] ?? 0);
                if ($currentRound >= (int)$nextRound) {
                    $waitingNext = false;
                    $nextRound = null;
                    $roundReady = 0;
                    $roundTotal = 4;
                }
            }

            echo "\033[2J\033[;H";
            echo "\033[1;36mSCOPONE SCIENTIFICO — ROUND {$s['round']} (SPECTATOR)\033[0m\n";
            echo "Turno: " . (($s['players'][$s['turn']]['name'] ?? ('Player'.($s['turn']+1)))) . "\n";
            echo "Team Scores: A {$s['teamScores']['A']}  |  B {$s['teamScores']['B']}\n\n";

            echo "Tavolo: " . (empty($s['table']) ? '(vuoto)' : implode(' ', array_map('emojiCard', $s['table']))) . "\n";
            echo "Ultima mossa: {$lastMoveText}\n";

            if ($waitingNext && $nextRound !== null) {
                echo "\n\033[1;33m[WAIT] Prossimo round {$nextRound}: pronti {$roundReady}/{$roundTotal}\033[0m\n";
            }

            echo "\n--- MANI (FULL VIEW) ---\n";
            foreach (($s['players'] ?? []) as $i => $p) {
                $hand = $p['hand'] ?? [];
                echo ($i+1) . ") {$p['name']} | captures: {$p['capturesCount']} | hand: ";
                echo empty($hand) ? "(vuota)" : implode(' ', array_map('emojiCard', $hand));
                echo "\n";
            }
            break;

        case 'event':
            $type = $msg['type'] ?? '';
            if ($type === 'capture') {
                $pindex = (int)($msg['player'] ?? -1);
                $pname = $s['players'][$pindex]['name'] ?? ("Player".($pindex+1));

                $taken = (isset($msg['taken']) && is_array($msg['taken'])) ? $msg['taken'] : ($msg['cards'] ?? []);
                $played = (isset($msg['card']) && is_array($msg['card'])) ? $msg['card'] : null;

                $takenStr = is_array($taken) ? implode(' ', array_map('emojiCard', $taken)) : '';
                $playedStr = is_array($played) ? emojiCard($played) : '??';

                $lastMovePlayerIndex = $pindex;
                $lastMoveText = "[CAPTURE] {$pname} prende {$takenStr} con {$playedStr}";
                echo "\n{$lastMoveText}\n";

            } elseif ($type === 'place') {
                $pindex = (int)($msg['player'] ?? -1);
                $pname = $s['players'][$pindex]['name'] ?? ("Player".($pindex+1));
                $c = $msg['card'] ?? null;

                $lastMovePlayerIndex = $pindex;
                $lastMoveText = "[PLAY] {$pname} mette " . (is_array($c) ? emojiCard($c) : '??');
                echo "\n{$lastMoveText}\n";
            }
            break;

        case 'announce':
            $pindex = (int)($msg['player'] ?? -1);
            $label = match($msg['type'] ?? '') {
                'SETTEBELLO' => '⚜️ SETTEBELLO',
                'REBELLO'    => '👑 RE BELLO',
                'SCOPA'      => '🧹 SCOPA',
                default      => (string)($msg['type'] ?? 'ANNOUNCE')
            };

            if ($lastMovePlayerIndex === $pindex) {
                $lastMoveText .= " | {$label}";
            }
            break;

        case 'round_prepare':
            $waitingNext = true;
            $nextRound = (int)($msg['nextRound'] ?? 0);
            $roundReady = 0;
            $roundTotal = (int)($msg['needed'] ?? 4);
            echo "\n\033[1;33m[WAIT] In attesa conferme per round {$nextRound} (0/{$roundTotal})\033[0m\n";
            break;

        case 'round_progress':
            $waitingNext = true;
            $nextRound = (int)($msg['nextRound'] ?? $nextRound);
            $roundReady = (int)($msg['ready'] ?? $roundReady);
            $roundTotal = (int)($msg['total'] ?? $roundTotal);
            echo "\r\033[1;33m[WAIT] Round {$nextRound}: pronti {$roundReady}/{$roundTotal}\033[0m   ";
            break;

        case 'round_summary':
            echo "\n\033[1;34m────────────── ROUND {$msg['round']} ──────────────\033[0m\n";
            echo "Coppia A (" . implode(' + ', $msg['coppiaA']['players']) . "): +{$msg['coppiaA']['points']}  (Tot {$msg['coppiaA']['total']})\n";
            echo "Coppia B (" . implode(' + ', $msg['coppiaB']['players']) . "): +{$msg['coppiaB']['points']}  (Tot {$msg['coppiaB']['total']})\n";
            echo "Dettagli:\n";
            foreach (($msg['notes'] ?? []) as $n) echo "  - {$n}\n";
            echo "────────────────────────────────────────\n";

            // NEW: if final, don't wait for ENTER (game_over is coming)
            if (!empty($msg['final'])) {
                echo "(Fine partita in arrivo...)\n";
                break;
            }

            echo "Premi INVIO per continuare...";
            fgets(STDIN);
            break;

        case 'game_over':
            echo "\n\033[1;42m*** " . ($msg['msg'] ?? 'FINE') . " ***\033[0m\n";
            exit(0);

        default:
            break;
    }
}

echo "\n[DISCONNECT] Server disconnected. Closing spectator.\n";
fclose($socket);
exit(0);