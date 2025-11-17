<?php
// spectator.php
// Usage: php spectator.php <SERVER_IP> <PORT>
// ex: php spectator.php 192.168.1.42 9000

if ($argc < 3) {
    echo "Uso: php spectator.php <SERVER_IP> <PORT>\n";
    exit(1);
}

$server = $argv[1];
$port = intval($argv[2]);

$socket = @stream_socket_client("tcp://{$server}:{$port}", $errno, $errstr, 5);
if (!$socket) {
    echo "Connessione fallita: $errstr ($errno)\n";
    exit(1);
}
stream_set_blocking($socket, true);
stream_set_blocking(STDIN, true);

// send join as spectator (nick optional)
fwrite($socket, json_encode(['action'=>'join','nick'=>'Spectator','mode'=>'spectator']) . "\n");
$ack = trim(fgets($socket));

echo "\033[1;37mSCOPONE SCIENTIFICO — Spectator connected\033[0m\n\n";

while (!feof($socket)) {
    $line = trim(fgets($socket));
    if ($line === '') continue;
    $msg = json_decode($line, true);
    if (!is_array($msg)) continue;

    switch ($msg['action'] ?? '') {
        case 'state':
            $s = $msg['payload'];
            echo "\033[2J\033[;H";
            echo "\033[1;36mSCOPONE SCIENTIFICO — ROUND {$s['round']} (SPECTATOR)\033[0m\n";
            echo "Turn index: {$s['turn']}\n";
            echo "Tavolo: ";
            if (empty($s['table'])) echo "(vuoto)\n"; else {
                foreach ($s['table'] as $c) echo "[{$c['label']} {$c['suit']}]  ";
                echo "\n";
            }
            echo "\n--- MANI (FULL VIEW) ---\n";
            foreach ($s['players'] as $i => $p) {
                echo ($i+1) . ") {$p['name']} | captures: {$p['capturesCount']} | hand: ";
                foreach ($p['hand'] as $ci => $card) {
                    echo "[{$card['label']} {$card['suit']}] ";
                }
                echo "\n";
            }
            echo "\nTeam Scores: A {$s['teamScores']['A']}  |  B {$s['teamScores']['B']}\n";
            break;

        case 'event':
            if ($msg['type'] === 'capture') {
                echo "\nEvento: {$msg['who']} ha catturato: ";
                foreach ($msg['cards'] as $c) echo "[{$c['label']} {$c['suit']}] ";
                echo "\n";
            } elseif ($msg['type'] === 'place') {
                $c = $msg['card'];
                echo "\nEvento: {$msg['who']} ha messo {$c['label']} {$c['suit']}\n";
            }
            break;

        case 'announce':
            if ($msg['type'] === 'SETTEBELLO') {
                for ($i=0;$i<3;$i++) {
                    echo "\033[1;41m" . str_pad("  ⚜️  SETTEBELLO!  ⚜️  {$msg['who']}  ", 60, " ", STR_PAD_BOTH) . "\033[0m\n";
                    usleep(160000); echo "\n"; usleep(120000);
                }
            } elseif ($msg['type'] === 'REBELLO') {
                for ($i=0;$i<3;$i++) {
                    echo "\033[1;41m" . str_pad("  👑  RE BELLO!  👑  {$msg['who']}  ", 60, " ", STR_PAD_BOTH) . "\033[0m\n";
                    usleep(160000); echo "\n"; usleep(120000);
                }
            }
            break;

        case 'round_summary':
            echo "\n\033[1;34m────────────── ROUND {$msg['round']} ──────────────\033[0m\n";
            echo "Coppia A (" . implode(' + ', $msg['coppiaA']['players']) . "): +{$msg['coppiaA']['points']}  (Tot {$msg['coppiaA']['total']})\n";
            echo "Coppia B (" . implode(' + ', $msg['coppiaB']['players']) . "): +{$msg['coppiaB']['points']}  (Tot {$msg['coppiaB']['total']})\n";
            echo "Dettagli:\n";
            foreach ($msg['notes'] as $n) echo "  - {$n}\n";
            echo "────────────────────────────────────────\n";
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

fclose($socket);
