<?php
// classes/Player.php
require_once __DIR__ . '/Card.php';

class Player {
    public string $name;
    /** @var Card[] */
    public array $hand = [];
    /** @var Card[] captured cards (flattened) */
    public array $captures = [];

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function setHand(array $cards) {
        $this->hand = $cards;
    }

    public function playCard(int $index): ?Card {
        if (!isset($this->hand[$index])) return null;
        $card = $this->hand[$index];
        array_splice($this->hand, $index, 1);
        return $card;
    }

    public function addCaptured(array $cards) {
        foreach ($cards as $c) $this->captures[] = $c;
    }
}
