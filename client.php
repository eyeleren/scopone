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
$lastPromptToken = null; // evita prompt duplicati

echo "Connected as $name ($role)\n\n";

function showStatusAnimation(string $mode, int $have=0, int $need=0) {
    static $i = 0;
    $frames = ["⏳","⌛","🕐","🕑","🕒","🕓","🕔","🕕","🕖","🕗","🕘","🕙","🕚"];
    $f = $frames[$i % count($frames)];
    $i++;
    echo "\rIn attesa giocatori $f ($have/$need) ";
}

// Animazione per il turno di un altro giocatore
function showTurnAnimation(int $turnIndex) {
    static $i = 0;
    $frames = ["🕐","🕑","🕒","🕓","🕔","🕕","🕖","🕗","🕘","🕙","🕚","🕛"];
    $f = $frames[$i % count($frames)];
    $i++;
    echo "\rTurno giocatore ".($turnIndex+1)." $f ";
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
        1 => 'A',      // Asso
        2 => '2',
        3 => '3',
        4 => '4',
        5 => '5',
        6 => '6',
        7 => '7',
        8 => '🧙',     // Signore
        9 => '🐴',     // Cavallo
        10 => '👑',    // Re
        default => '?'
    };
    $suitEmoji = $suitMap[$c['suit'] ?? ''] ?? '?';

    // Highlight settebello (7 Denari) and Re bello (10 Denari)
    if (($c['suit'] ?? '') === 'Denari') {
        if (($c['value'] ?? 0) === 7) {
            return "⭐ {$rankEmoji}{$suitEmoji}";
        }
        if (($c['value'] ?? 0) === 10) {
            return "👑 {$rankEmoji}{$suitEmoji}";
        }
    }
    return "{$rankEmoji}{$suitEmoji}";
}

// Add robust prompt for card index (loop until valid)
function promptCardIndex(int $max): int {
    while (true) {
        echo "Indice carta (0-$max): ";
        $raw = fgets(STDIN);
        if ($raw === false) continue;
        $raw = trim($raw);
        if ($raw === '') {
            echo "Input vuoto. ";
            continue;
        }
        if (!ctype_digit($raw)) {
            echo "Deve essere un numero. ";
            continue;
        }
        $val = (int)$raw;
        if ($val < 0 || $val > $max) {
            echo "Fuori range. ";
            continue;
        }
        return $val;
    }
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
                // RIMOSSO: echo duplicato del turno
                // if ($role === 'player' && $payload['turn'] !== $playerId - 1) {
                //     echo "\nTurno giocatore ".($payload['turn']+1)." 🕒\n";
                // }

                // Prompt solo se è davvero il tuo turno e non abbiamo già chiesto
                if ($role === 'player' && $payload['turn'] == $playerId - 1) {
                    $max = count($payload['players'][$playerId - 1]['hand']) - 1;
                    $token = $payload['round'].'-'.$payload['turn'].'-'.$max;
                    if ($max >= 0 && $lastPromptToken !== $token) {
                        $lastPromptToken = $token;
                        $cardIndex = promptCardIndex($max);
                        fwrite($conn, json_encode([
                            'action' => 'play',
                            'payload' => [
                                'playerId' => $playerId,
                                'cardIndex' => $cardIndex
                            ]
                        ]) . "\n");
                    }
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
                } elseif ($msg['type'] === 'SCOPA') {
                    echo "\n🧹  SCOPA di Player".($msg['player']+1)."! 🧹\n";
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
            case 'error':
                echo "\n[ERRORE] {$msg['msg']}\n";
                // Ripropone SOLO al giocatore che ha generato l'errore
                if (($msg['playerId'] ?? null) === $playerId && isset($payload) && $payload['turn'] == $playerId - 1) {
                    $max = count($payload['players'][$playerId - 1]['hand']) - 1;
                    if ($max >= 0) {
                        // Non aggiorniamo $lastPromptToken così la logica di re-prompt interno continua
                        $cardIndex = promptCardIndex($max);
                        fwrite($conn, json_encode([
                            'action'=>'play',
                            'payload'=>[
                                'playerId'=>$playerId,
                                'cardIndex'=>$cardIndex
                            ]
                        ])."\n");
                    }
                }
                break;
        }
    } else {
        if (!isset($payload) && $role === 'player') {
            showStatusAnimation('lobby', $lobbyPlayers, $lobbyNeeded);
        } elseif (isset($payload) && $role === 'player' && $payload['turn'] !== $playerId - 1) {
            // Animazione solo “Turno giocatore X”
            showTurnAnimation($payload['turn']);
        }
        usleep(200000);
    }
}