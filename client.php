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

// Invia richiesta join con nome scelto
fwrite($conn, json_encode([
    'action'=>'join',
    'nick'=>$name,
    'mode'=>'player'
])."\n");

function showStatusAnimation(string $mode, int $have=0, int $need=0) {
    if ($have >= $need) return; // evita mostrare (4/4) una volta pieno
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
            return "⭐{$rankEmoji}{$suitEmoji}";
        }
        if (($c['value'] ?? 0) === 10) {
            return "⭐{$rankEmoji}{$suitEmoji}";
        }
    }
    return "{$rankEmoji}{$suitEmoji}";
}

// Add robust prompt for card index (loop until valid)
function promptCardIndex(int $max): int {
    // Temporarily blocca STDIN per input affidabile
    stream_set_blocking(STDIN, true);
    try {
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
    } finally {
        // Torna non bloccante
        stream_set_blocking(STDIN, false);
        flushStdin(); // scarta eventuali caratteri residui
    }
}

stream_set_blocking($conn, false);

// Rende STDIN non bloccante per poter scartare input prematuro
stream_set_blocking(STDIN, false);

// Utility: svuota qualsiasi input digitato prima del prompt reale
function flushStdin(): void {
    while (($ch = fgetc(STDIN)) !== false) { /* discard */ }
}

$lobbyPlayers = 1;
$lobbyNeeded  = 4;
$waitingNext = false;
$nextRound = null;
$roundReadyCount = 0;

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
                if ($waitingNext && $nextRound !== null && $payload['round'] === $nextRound) {
                    $waitingNext = false;
                    $nextRound = null;
                    $roundReadyCount = 0;
                }

                $turnName = $payload['players'][$payload['turn']]['name'] ?? ('Player'.($payload['turn']+1));
                $myIndex = $playerId - 1;
                $myTeam = $payload['players'][$myIndex]['team'] ?? (($myIndex===0||$myIndex===2)?'A':'B');
                // teammate index
                $mateIndex = ($myTeam === 'A')
                    ? ($myIndex === 0 ? 2 : 0)
                    : ($myIndex === 1 ? 3 : 1);
                $mateName = $payload['players'][$mateIndex]['name'] ?? '??';

                echo "─────────────── SCOPONE SCIENTIFICO ───────────────\n";
                echo "Round: {$payload['round']} | Turno: {$turnName}\n";
                echo "Squadra: {$myTeam} (con {$mateName})  |  Punteggi A = {$payload['teamScores']['A']} / B = {$payload['teamScores']['B']}\n\n";

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
                    // Prima di mostrare il prompt scarta qualsiasi input digitato prima del turno
                    flushStdin();
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
                    $pindex = $msg['player'];
                    $pname = ($payload['players'][$pindex]['name'] ?? ("Player".($pindex+1)));
                    echo "\n[CATTURA] {$pname} prende: $cards\n";
                } elseif ($msg['type'] === 'place') {
                    $c = $msg['card'];
                    $pindex = $msg['player'];
                    $pname = ($payload['players'][$pindex]['name'] ?? ("Player".($pindex+1)));
                    echo "\n[GIOCA] {$pname} mette ".emojiCard($c)."\n";
                }
                break;

            case 'announce':
                $pindex = $msg['player'];
                $pname = ($payload['players'][$pindex]['name'] ?? ("Player".($pindex+1)));
                if ($msg['type'] === 'SETTEBELLO') {
                    echo "\n⚜️  SETTEBELLO a {$pname}! ⚜️\n";
                } elseif ($msg['type'] === 'REBELLO') {
                    echo "\n👑  RE BELLO a {$pname}! 👑\n";
                } elseif ($msg['type'] === 'SCOPA') {
                    echo "\n🧹  SCOPA di {$pname}! 🧹\n";
                }
                break;

            case 'round_summary':
                echo "\n──────── ROUND {$msg['round']} ────────\n";
                echo "Coppia A: +{$msg['coppiaA']['points']} (Tot {$msg['coppiaA']['total']}) [".implode(' + ',$msg['coppiaA']['players'])."]\n";
                echo "Coppia B: +{$msg['coppiaB']['points']} (Tot {$msg['coppiaB']['total']}) [".implode(' + ',$msg['coppiaB']['players'])."]\n";
                echo "Dettagli:\n";
                foreach ($msg['notes'] as $n) echo " - $n\n";
                $nextRound = $msg['round'] + 1;
                echo "\nPremi un tasto per cominciare il {$nextRound}° round...";
                // Blocco per conferma giocatore (solo player)
                if ($role === 'player') {
                    stream_set_blocking(STDIN, true);
                    fgets(STDIN);
                    stream_set_blocking(STDIN, false);
                    fwrite($conn, json_encode([
                        'action'=>'round_ready',
                        'playerId'=>$playerId,
                        'round'=>$nextRound
                    ])."\n");
                    $waitingNext = true;
                    $roundReadyCount = 1; // noi siamo pronti
                } else {
                    // spettatore: non partecipa al handshake
                }
                break;

            case 'round_prepare':
                // Server segnala inizio handshake (può arrivare prima del nostro ready se siamo lenti)
                $nextRound = $msg['nextRound'] ?? null;
                if ($role === 'player') {
                    $waitingNext = true;
                    // Se non abbiamo ancora inviato ready (es. arrivato prima del summary) lo faremo al summary
                }
                break;

            case 'round_progress':
                if ($waitingNext && isset($msg['nextRound']) && $msg['nextRound'] === $nextRound) {
                    $roundReadyCount = $msg['ready'];
                }
                break;

            case 'game_over':
                echo "\n*** " . ($msg['msg'] ?? 'FINE') . " ***\n";
                exit(0);
            case 'error':
                echo "\n[ERRORE] {$msg['msg']}\n";
                if (str_contains($msg['msg'], 'Nome già in uso')) {
                    echo "Nuovo nome: ";
                    $new = trim(fgets(STDIN));
                    if ($new !== '') {
                        fwrite($conn, json_encode([
                            'action'=>'join',
                            'nick'=>$new,
                            'mode'=>'player'
                        ])."\n");
                    }
                }
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
        if ($waitingNext && $role === 'player') {
            // Animazione attesa prossimo round
            static $w = 0;
            $frames = ["⏳","⌛","🕐","🕑","🕒","🕓","🕔","🕕","🕖","🕗","🕘","🕙","🕚"];
            $f = $frames[$w % count($frames)];
            $w++;
            echo "\rIn attesa degli altri giocatori per round {$nextRound} $f ({$roundReadyCount}/4) ";
        } elseif (!isset($payload) && $role === 'player') {
            showStatusAnimation('lobby', $lobbyPlayers, $lobbyNeeded);
        } elseif (isset($payload) && $role === 'player' && $payload['turn'] !== $playerId - 1 && !$waitingNext) {
            showTurnAnimation($payload['turn']);
            $line = fgets(STDIN);
            if ($line !== false) {
                echo "\nInput ignorato (non è il tuo turno)\n";
                flushStdin();
            }
        }
        usleep(200000);
    }
}