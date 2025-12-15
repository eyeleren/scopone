<?php
// classes/Game.php
require_once __DIR__ . '/Deck.php';
require_once __DIR__ . '/Player.php';

/**
 * Game engine per Scopone con supporto per registrazione dinamica dei giocatori.
 */
class Game {
    /** @var Player[] */
    public array $players = [];
    /** @var Card[] */
    public array $table = [];
    public int $turn = 0;
    public array $teamScores = ['A'=>0,'B'=>0];
    public int $round = 1;
    public array $roles = [];
    public int $movesInRound = 0; // <--- aggiunto
    public bool $started = false; // nuovo flag

    /** Costruttore opzionale (compat per vecchia versione). */
    public function __construct(array $names = []) {
        foreach ($names as $n) {
            if (count($this->players) < 4) $this->players[] = new Player($n);
        }
        if (count($this->players) === 4) $this->startRound();
    }

    /** Registra un giocatore o spettatore. */
    public function registerPlayer(int $id, string $name, string $role): void {
        $this->roles[$id] = $role;
        if ($role === 'player') {
            if (count($this->players) >= 4) return; // già pieno
            $this->players[] = new Player($name);
        }
    }

    /** True se abbiamo 4 giocatori. */
    public function isReady(): bool { return count($this->players) === 4; }

    public function startRound(): void {
        if (!$this->isReady()) return;
        $deck = new Deck();
        foreach ($this->players as $p) {
            $p->setHand($deck->deal(10));
            $p->captures = [];
        }
        $this->table = [];
        $this->turn = 0;
        $this->movesInRound = 0;
        $this->started = true; // segna avvio
    }

    // build state payload to send to client/spectator
    // $forIndex: if null => full (used for spectator), otherwise a player index and will hide other hands unless $debug true
    public function buildState(?int $forIndex=null, bool $debug=false): array {
        $state = [
            'round' => $this->round,
            'turn' => $this->turn,
            'table' => array_map(fn($c)=>$c->toArray(), $this->table),
            'teamScores' => $this->teamScores,
            'players' => []
        ];
        foreach ($this->players as $i => $p) {
            $team = ($i === 0 || $i === 2) ? 'A' : 'B';
            $entry = [
                'name'=>$p->name,
                'capturesCount'=>count($p->captures),
                'team'=>$team
            ];
            if ($forIndex === null || $debug || $forIndex === $i) {
                $entry['hand'] = array_map(fn($c)=>$c->toArray(), $p->hand);
            } else {
                $entry['hand'] = array_fill(0, count($p->hand), ['hidden'=>true]);
            }
            $state['players'][] = $entry;
        }
        return $state;
    }

    // player i plays card index (0-based). returns array with ok/events/roundEnd/error
    public function playCard(int $i, int $cardIndex): array {
        if ($i !== $this->turn) return ['ok'=>false,'error'=>'Non è il tuo turno.'];
        $player = $this->players[$i];
        $card = $player->playCard($cardIndex);
        if ($card === null) return ['ok'=>false,'error'=>'Carta non valida.'];

        // REGOLA: Asso non può essere la primissima carta del gioco
        if ($card->value === 1 && $this->round === 1 && $this->movesInRound === 0) {
            // rimetti la carta nella mano (annulla giocata)
            $player->hand = array_merge(
                array_slice($player->hand, 0, $cardIndex),
                [$card],
                array_slice($player->hand, $cardIndex)
            );
            return ['ok'=>false,'error'=>'Non puoi giocare un asso come prima carta della partita, piuttosto parti mulo.'];
        }

        // Caso speciale: Asso su tavolo vuoto -> prende solo se stesso
        if ($card->value === 1 && empty($this->table)) {
            $player->addCaptured([$card]);
            $events = [
                [
                    'type'  => 'capture',
                    'player'=> $i,
                    // NEW: explicit fields so client/server can render "prende X con Y"
                    'card'  => $card->toArray(),
                    'taken' => [$card->toArray()],
                    // keep for backward compatibility (captured = played + taken)
                    'cards' => [$card->toArray()],
                ]
            ];
            $this->advanceTurn();
            $this->movesInRound++;
            return ['ok'=>true,'events'=>$events,'roundEnd'=>$this->isRoundEnd()];
        }

        $taken = $this->resolveTake($card);
        $events = [];

        if (!empty($taken)) {
            $hadCardsBefore = count($this->table); // per SCOPA
            foreach ($taken as $t) {
                foreach ($this->table as $k => $tabc) {
                    if ($tabc === $t) { array_splice($this->table, $k, 1); break; }
                }
            }

            $captured = array_merge([$card], $taken);
            $player->addCaptured($captured);

            // NEW: send played card + taken cards separately
            $events[] = [
                'type'   => 'capture',
                'player' => $i,
                'card'   => $card->toArray(),
                'taken'  => array_map(fn($c)=>$c->toArray(), $taken),
                // keep for backward compatibility (captured = played + taken)
                'cards'  => array_map(fn($c)=>$c->toArray(), $captured),
            ];

            // annunci speciali
            foreach ($captured as $c) {
                if ($c->suit === 'Denari' && $c->value === 7) $events[] = ['type'=>'SETTEBELLO','player'=>$i];
                if ($c->suit === 'Denari' && $c->value === 10) $events[] = ['type'=>'REBELLO','player'=>$i];
            }
            // SCOPA: tavolo svuotato e c'erano carte prima
            // FIX: per regola di questa versione, SCOPA non può avvenire con Asso
            if ($card->value !== 1 && count($this->table) === 0 && $hadCardsBefore > 0) {
                $player->addScopa();
                $events[] = ['type'=>'SCOPA','player'=>$i];
            }
        } else {
            $this->table[] = $card;
            $events[] = ['type'=>'place','player'=>$i,'card'=>$card->toArray()];
        }

        $this->advanceTurn();
        $this->movesInRound++;
        $roundEnd = $this->isRoundEnd();
        return ['ok'=>true,'events'=>$events,'roundEnd'=>$roundEnd];
    }

    /** Wrapper usato dal server: playerId è 1-based. */
    public function handlePlay(int $playerId, int $cardIndex): array {
        $idx = $playerId - 1;
        if (!isset($this->players[$idx])) return ['ok'=>false,'error'=>'Giocatore inesistente'];
        return $this->playCard($idx, $cardIndex);
    }

    /** Invia lo stato a tutti i client (vista spettatore generica). */
    public function broadcastState(array $clients): void {
        $state = $this->buildState(null, false);
        $msg = json_encode(['action'=>'state','payload'=>$state]);
        foreach ($clients as $c) { @fwrite($c, $msg."\n"); }
    }

    protected function resolveTake(Card $card): array {
        // REGOLA: Asso (value=1) prende tutte le carte sul tavolo
        if ($card->value === 1 && !empty($this->table)) {
            return $this->table;
        }

        // priority: exact equal value on table -> single take
        foreach ($this->table as $t) {
            if ($t->value === $card->value) return [$t];
        }
        // try subset sum combinations (first found)
        $n = count($this->table);
        if ($n === 0) return [];
        $indices = range(0, $n-1);
        $target = $card->value;
        $subsetCount = 1 << $n;
        for ($mask = 1; $mask < $subsetCount; $mask++) {
            $sum = 0; $combo = [];
            foreach ($indices as $j) {
                if ($mask & (1 << $j)) {
                    $sum += $this->table[$j]->value;
                    $combo[] = $this->table[$j];
                }
            }
            if ($sum === $target) return $combo;
        }
        return [];
    }

    protected function advanceTurn(): void {
        $this->turn = ($this->turn + 1) % 4;
    }

    protected function isRoundEnd(): bool {
        foreach ($this->players as $p) {
            if (!empty($p->hand)) return false;
        }
        return true;
    }

    // scoring helpers
    protected function flattenTeamCaptures(string $team): array {
        // team A: players 0 and 2; team B: 1 and 3
        $cards = [];
        if ($team === 'A') {
            $cards = array_merge($this->players[0]->captures, $this->players[2]->captures);
        } else {
            $cards = array_merge($this->players[1]->captures, $this->players[3]->captures);
        }
        return $cards;
    }

    protected function computeNapoli(array $cards): int {
        $have = [];
        foreach ($cards as $c) {
            if ($c->suit === 'Denari') $have[$c->value] = true;
        }
        if (!isset($have[1]) || !isset($have[2]) || !isset($have[3])) return 0;
        $count = 0;
        for ($v = 1; $v <= 10; $v++) {
            if (isset($have[$v])) $count++;
            else break;
        }
        return ($count >= 3) ? $count : 0;
    }

    public function scoreRound(): array {
        $tA = $this->flattenTeamCaptures('A');
        $tB = $this->flattenTeamCaptures('B');

        $detail = [
            'A'=>['cards'=>count($tA),'denari'=>count(array_filter($tA, fn($c)=>$c->suit==='Denari'))],
            'B'=>['cards'=>count($tB),'denari'=>count(array_filter($tB, fn($c)=>$c->suit==='Denari'))]
        ];

        $roundPoints = ['A'=>0,'B'=>0];
        $notes = [];

        // CARTE
        if ($detail['A']['cards'] > $detail['B']['cards']) { $roundPoints['A']++; $notes[]="Carte: +1 ad A"; }
        elseif ($detail['B']['cards'] > $detail['A']['cards']) { $roundPoints['B']++; $notes[]="Carte: +1 a B"; }
        else { $notes[]="Carte: parità, nessun punto"; }

        // DENARI
        if ($detail['A']['denari'] > $detail['B']['denari']) { $roundPoints['A']++; $notes[]="Denari: +1 ad A"; }
        elseif ($detail['B']['denari'] > $detail['A']['denari']) { $roundPoints['B']++; $notes[]="Denari: +1 a B"; }
        else { $notes[]="Denari: parità, nessun punto"; }

        // SETTEBELLO & RE BELLO
        $has7A = count(array_filter($tA, fn($c)=>$c->suit==='Denari' && $c->value===7))>0;
        $has7B = count(array_filter($tB, fn($c)=>$c->suit==='Denari' && $c->value===7))>0;
        if ($has7A && !$has7B) { $roundPoints['A']++; $notes[]="Settebello: +1 ad A"; }
        elseif ($has7B && !$has7A) { $roundPoints['B']++; $notes[]="Settebello: +1 a B"; }
        else { $notes[]="Settebello: nessuno"; }

        $hasReA = count(array_filter($tA, fn($c)=>$c->suit==='Denari' && $c->value===10))>0;
        $hasReB = count(array_filter($tB, fn($c)=>$c->suit==='Denari' && $c->value===10))>0;
        if ($hasReA && !$hasReB) { $roundPoints['A']++; $notes[]="Re bello: +1 ad A"; }
        elseif ($hasReB && !$hasReA) { $roundPoints['B']++; $notes[]="Re bello: +1 a B"; }
        else { $notes[]="Re bello: nessuno"; }

        // PRIMIERA: compare only 7s; if tie compare 6s; if tie => patta (nessun punto)
        $cnt7A = count(array_filter($tA, fn($c)=>$c->value===7));
        $cnt7B = count(array_filter($tB, fn($c)=>$c->value===7));
        if ($cnt7A > $cnt7B) { $roundPoints['A']++; $notes[]="Primiera: +1 ad A (7)"; }
        elseif ($cnt7B > $cnt7A) { $roundPoints['B']++; $notes[]="Primiera: +1 a B (7)"; }
        else {
            $cnt6A = count(array_filter($tA, fn($c)=>$c->value===6));
            $cnt6B = count(array_filter($tB, fn($c)=>$c->value===6));
            if ($cnt6A > $cnt6B) { $roundPoints['A']++; $notes[]="Primiera: +1 ad A (6)"; }
            elseif ($cnt6B > $cnt6A) { $roundPoints['B']++; $notes[]="Primiera: +1 a B (6)"; }
            else { $notes[]="Primiera: parità, nessun punto"; }
        }

        // NAPOLI
        $napA = $this->computeNapoli($tA);
        $napB = $this->computeNapoli($tB);
        if ($napA > 0) { $roundPoints['A'] += $napA; $notes[]="Napoli: A riceve $napA punti"; }
        if ($napB > 0) { $roundPoints['B'] += $napB; $notes[]="Napoli: B riceve $napB punti"; }
        if ($napA===0 && $napB===0) $notes[]="Napoli: nessuna";

        // update totals
        $this->teamScores['A'] += $roundPoints['A'];
        $this->teamScores['B'] += $roundPoints['B'];

        return [
            'roundPoints'=>$roundPoints,
            'teamScores'=>$this->teamScores,
            'detail'=>$detail,
            'notes'=>$notes,
            'napoli'=>['A'=>$napA,'B'=>$napB]
        ];
    }

    // check overall winner (>=21 and strictly greater than opponent)
    public function checkWinner(): ?string {
        if ($this->teamScores['A'] >= 21 && $this->teamScores['A'] > $this->teamScores['B']) return 'A';
        if ($this->teamScores['B'] >= 21 && $this->teamScores['B'] > $this->teamScores['A']) return 'B';
        return null;
    }

    public function isNameTaken(string $name): bool {
        foreach ($this->players as $p) {
            if (strcasecmp($p->name, $name) === 0) return true;
        }
        return false;
    }

    public function setPlayerName(int $index, string $name): bool {
        if (!isset($this->players[$index])) return false;
        foreach ($this->players as $i => $p) {
            if ($i !== $index && strcasecmp($p->name, $name) === 0) return false;
        }
        $this->players[$index]->name = $name;
        return true;
    }

    public function allNamesConfirmed(): bool {
        if (!$this->isReady()) return false;
        foreach ($this->players as $p) {
            if (preg_match('/^Player\d+$/', $p->name)) return false;
        }
        return true;
    }
}
