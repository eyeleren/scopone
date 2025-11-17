<?php

const DEBUG = false;

$ip   = $argv[1] ?? '127.0.0.1';
$port = $argv[2] ?? 12345;
$name = $argv[3] ?? 'Player';

$conn = stream_socket_client("tcp://$ip:$port", $errno, $errstr, 30);
if (!$conn) die("Connection failed: $errstr ($errno)\n");

$line = fgets($conn);
if ($line === false) die("No welcome message from server\n");
$welcome = json_decode(trim($line), true);
if (!is_array($welcome) || !isset($welcome['payload'])) die("Invalid welcome message: $line\n");

$playerId = $welcome['payload']['id'];
$role = $welcome['payload']['role'];

echo "Connected as $name ($role)\n\n";

function showStatusAnimation(string $mode, int $have=0, int $need=0) {
    static $i = 0;
    $frames = ["⏳","⌛","🕐","🕑","🕒","🕓","🕔","🕕","🕖","🕗","🕘","🕙","🕚"];
    $bar    = ["▮▯▯▯▯","▮▮▯▯▯","▮▮▮▯▯","▮▮▮▮▯","▮▮▮▮▮"];
    $f = $frames[$i % count($frames)];
    $b = $bar[$i % count($bar)];
    $i++;
    if ($mode === 'lobby') {
        echo "\rIn attesa giocatori $f ($have/$need) ";
    } elseif ($mode === 'turn') {
        echo "\rAttendi il tuo turno... $b";
    }
}

// Map card to emoji string
function emojiCard(array $c): string {
    if (isset($c['hidden'])) return '🂠';
    $suitMap = [
        'spade'   => '⚔️',
        'denari'  => '💰',
        'coppe'   => '🍷',
        'bastoni' => '🪵',
        'hearts'  => '❤️',
        'diamonds'=> '💎',
        'clubs'   => '♣️'
    ];
    $numMap = [
        'A'=>'🅰️','1'=>'1️⃣','2'=>'2️⃣','3'=>'3️⃣','4'=>'4️⃣','5'=>'5️⃣',
        '6'=>'6️⃣','7'=>'7️⃣','8'=>'8️⃣','9'=>'9️⃣','10'=>'🔟',
        'J'=>'🧑','Q'=>'👸','K'=>'🤴'
    ];
    $label = $c['label'] ?? '?';
    $suit  = $c['suit'] ?? '?';
    $rankEmoji = $numMap[$label] ?? $label;
    $suitEmoji = $suitMap[$suit] ?? $suit;
    // Highlight settebello (7 denari)
    if ($label === '7' && $suit === 'denari') {
        return "⭐ $rankEmoji$suitEmoji";
    }
    return "$rankEmoji$suitEmoji";
}

stream_set_blocking($conn, false);

$lobbyPlayers = 1;
$lobbyNeeded  = 4;

while (true) {
    $data = fgets($conn);
    if ($data) {
        $msg = json_decode(trim($data), true);
        if (!$msg) continue;

        switch ($msg['action']) {
            case 'lobby':
                $lobbyPlayers = $msg['players'];
                $lobbyNeeded  = $msg['needed'];
                showStatusAnimation('lobby', $lobbyPlayers, $lobbyNeeded);
                break;

            case 'state':
                system('clear');
                $payload = $msg['payload'];

                echo "─────────────── SCOPONE SCIENTIFICO ───────────────\n";
                echo "Round: {$payload['round']} | Turno: Player".($payload['turn']+1)."\n\n";

                foreach ($payload['players'] as $id => $p) {
                    if ($id == $playerId - 1) {
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
                echo "Punteggio: Coppia A {$payload['teamScores']['A']} | Coppia B {$payload['teamScores']['B']}\n\n";

                if ($payload['turn'] == $playerId - 1 && $role === 'player') {
                    echo "È il tuo turno! Inserisci indice carta (0-" . (count($payload['players'][$playerId - 1]['hand']) - 1) . "): ";
                    $input = trim(fgets(STDIN));
                    fwrite($conn, json_encode(['action' => 'play', 'payload' => [
                        'playerId' => $playerId,
                        'cardIndex' => (int)$input
                    ]]) . "\n");
                }
                break;

            case 'event':
                if ($msg['type'] === 'capture') {
                    $cards = implode(' ', array_map('emojiCard', $msg['cards']));
                    echo "\n[CATTURA] Player".($msg['player']+1)." prende: $cards\n";
                } elseif ($msg['type'] === 'place') {
                    $c = $msg['card'];
                    echo "\n[GIOCA] Player".($msg['player']+1)." mette ".emojiCard($c)."\n";
                }
                break;

            case 'announce':
                if ($msg['type'] === 'SETTEBELLO') {
                    echo "\n⚜️  SETTEBELLO a Player".($msg['player']+1)."! ⚜️\n";
                } elseif ($msg['type'] === 'REBELLO') {
                    echo "\n👑  RE BELLO a Player".($msg['player']+1)."! 👑\n";
                }
                break;

            case 'round_summary':
                echo "\n──────── ROUND {$msg['round']} ────────\n";
                echo "Coppia A: +{$msg['coppiaA']['points']} (Tot {$msg['coppiaA']['total']})\n";
                echo "Coppia B: +{$msg['coppiaB']['points']} (Tot {$msg['coppiaB']['total']})\n";
                echo "Dettagli:\n";
                foreach ($msg['notes'] as $n) echo " - $n\n";
                echo "Premi INVIO per continuare...";
                fgets(STDIN);
                break;

            case 'game_over':
                echo "\n*** " . ($msg['msg'] ?? 'FINE') . " ***\n";
                exit(0);
        }
    } else {
        if (!isset($payload) && $role === 'player') {
            showStatusAnimation('lobby', $lobbyPlayers, $lobbyNeeded);
        } elseif (isset($payload) && $role === 'player' && $payload['turn'] !== $playerId - 1) {
            showStatusAnimation('turn');
        }
        usleep(200000);
    }
}