<?php
// classes/Card.php
class Card {
    public string $suit; // Denari, Coppe, Spade, Bastoni
    public int $value;   // 1..10 (1=Asso, 8=Fante,9=Cavallo,10=Re)

    public function __construct(string $suit, int $value) {
        $this->suit = $suit;
        $this->value = $value;
    }

    public function label(): string {
        return match($this->value) {
            1 => 'Asso',
            8 => 'Fante',
            9 => 'Cavallo',
            10 => 'Re',
            default => strval($this->value)
        };
    }

    public function symbol(): string {
        $sym = [
            'Denari' => "\033[33m🪙\033[0m",
            'Coppe'  => "\033[31m🍷\033[0m",
            'Spade'  => "\033[90m⚔️\033[0m",
            'Bastoni'=> "\033[32m🪵\033[0m"
        ];
        return $sym[$this->suit] ?? '';
    }

    public function __toString(): string {
        return $this->label() . ' ' . $this->symbol();
    }

    // serializzazione semplice per json
    public function toArray(): array {
        return ['suit'=>$this->suit,'value'=>$this->value,'label'=>$this->label()];
    }
}
