<?php
// classes/Deck.php
require_once __DIR__ . '/Card.php';

class Deck {
    public array $cards = [];

    public function __construct() {
        $suits = ['Denari','Coppe','Spade','Bastoni'];
        // RANKS: Asso,2,3,4,5,6,7,Fante(8),Cavallo(9),Re(10)
        for ($v = 1; $v <= 10; $v++) {
            foreach ($suits as $suit) {
                $this->cards[] = new Card($suit, $v);
            }
        }
        shuffle($this->cards);
    }

    public function deal(int $n): array {
        return array_splice($this->cards, 0, $n);
    }
}
