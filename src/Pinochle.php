<?php

namespace jfadich\Pinochle;

use jfadich\Pinochle\Contracts\Seat;
use jfadich\Pinochle\Exceptions\PinochleRuleException;
use jfadich\Pinochle\Cards\Card;
use jfadich\Pinochle\Cards\Deck;
use jfadich\Pinochle\Contracts\Game;
use jfadich\Pinochle\Contracts\Player;
use jfadich\Pinochle\Contracts\Round;

class Pinochle
{
    /**
     * @var Game
     */
    protected $game;

    protected $currentRound;

    public function __construct(Game $game)
    {
        $this->setGame($game);
    }

    public function setGame(Game $game)
    {
        $this->game = $game;

        return $this;
    }

    public function getGame()
    {
        return $this->game;
    }

    public function deal()
    {
        if(count($this->game->getPlayers()) !== 4) {
            throw new PinochleRuleException('Not Enough Players');
        }

        if(count($this->game->getRounds()) > 0)
            throw new PinochleRuleException('Game has already started.');

        $round = $this->game->nextRound();

        $hands = Deck::make()->deal();
        $hands->each(function($cards, $key) use($round) {
            $seat = $this->game->getSeatAtPosition($key);

            if(!$seat)
                throw new \Exception('Seat not filled');

            $round->addHand($seat, [
                'dealt' => $cards,
                'current' => $cards,
            ]);
        });

        $round->setPhase(Round::PHASE_BIDDING);
        $this->game->setNextSeat(1);

        return $this;
    }

    public function placeBid(Seat $seat, $bid)
    {
        $this->validateGameState($seat, Round::PHASE_BIDDING);
        $round = $this->game->getCurrentRound();
        $auction = $round->getAuction();

        if($auction->seatHasPassed($seat))
            throw new PinochleRuleException('You have already passed this round');

        if(is_numeric($bid) && $bid % 10 !== 0)
            throw new PinochleRuleException('Bid must be a multiple of 10');

        $current_bid = $auction->getCurrentBid();

        if($bid !== 'pass' && $bid <= $current_bid)
            throw new PinochleRuleException('Bid must be larger than current bid');

        $this->game->addLog($seat->id, "{$seat->getPlayer()->getName()} bid $bid");
        $auction->placeBid($seat, $bid);

        $this->setNextBidder();

        return $bid;
    }

    public function callTrump(Seat $seat, $trump)
    {
        if(is_int($trump))
            $trump = new Card($trump);

        $this->validateGameState($seat, Round::PHASE_CALLING);

        $round = $this->game->getCurrentRound();

        $round->setTrump($trump);

        $round->setPhase(Round::PHASE_PASSING);

        $player = $this->game->getActiveSeat()->getPlayer();

        $this->game->addLog($player->id, "{$player->getName()} called {$trump->getSuitName()} for trump");

        $nextSeat = $this->game->setNextSeat(1);

        if($nextSeat->getPlayer()->isAuto()) {
            $pass = $round->getAutoPlayerForSeat($nextSeat)->getPass($trump);

            $this->passCards($nextSeat, $pass);
        }
    }

    public function passCards(Seat $seat, array $pass)
    {
        $this->validateGameState($seat, Round::PHASE_PASSING);

        $round = $this->game->getCurrentRound();

        $isLeader = $round->getLeadSeat()->getPosition() === $this->game->getActiveSeat()->getPosition();

        if($isLeader) {
            $partner = $this->game->getNextSeat(1);
        } else {
            $partner = $this->game->setNextSeat(1);
        }

        $pass = $round->getHandForSeat($seat)->takeCards($pass);
        $round->getHandForSeat($partner)->addCards($pass);

        $player = $seat->getPlayer();
        $this->game->addLog($player->id, "{$player->getName()} passed cards to {$partner->getPlayer()->getName()}");

        if($isLeader) {
            $this->game->getCurrentRound()->setPhase(Round::PHASE_MELDING);

            $this->game->getPlayers()->each(function($player) {

                $analysis = new HandAnalyser($player->getCurrentHand()->getCards());
                $meld = $analysis->getMeld($this->game->currentRound->trump);

                $this->game->currentRound->meld()->set("players.{$player->seat}", $meld);

                if($player->isAuto()) {
                    $this->game->currentRound->meld()->push('ready', $player->seat);
                }
            });

            $this->game->currentRound->save();
        } else {
            if($partner->getPlayer()->isAuto()) {
                $pass = $round->getAutoPlayerForSeat($partner)->getPassBack($round->getTrump());
                $this->passCards($partner, $pass);
            }
        }
    }

    public function acceptMeld($seat)
    {
        if(!$this->game->currentRound->isPhase(Round::PHASE_MELDING))
            throw new PinochleRuleException('Game is currently not melding');

        $this->game->currentRound->meld()->push('ready', $seat);

        if(count($this->game->currentRound->meld('ready', [])) === 4) {
            $this->game->currentRound->phase = Round::PHASE_PLAYING;
        }

        $this->game->currentRound->save();
    }

    public function playTrick(Player $player, Card $play)
    {
        $this->validateGameState($player, Round::PHASE_PLAYING);

        if($this->game->getCurrentRound()->active_seat === $this->game->getCurrentRound()->lead_seat) {

            $this->game->getCurrentRound()->play_area('lease_suit', $play->getSuit());
            $this->game->getCurrentRound()->play_area('plays', [$play->getSuit()]);
            $this->game->getCurrentRound()->save();

            return $this->setNextPlayer();
        }


    }

    protected function setNextBidder()
    {
        $game = $this->game;
        $round = $game->getCurrentRound();
        $auction = $round->getAuction();

        $game->setNextSeat();

        $activeSeat = $game->getActiveSeat();
        $nextPlayer = $game->getCurrentPlayer();


        if($auction->seatHasPassed($activeSeat))
            return $this->setNextBidder();

        if(count($auction->getPassedSeats()) === 3) {

            $game->addLog($nextPlayer->id, "{$nextPlayer->getName()} Wins the auction with a bid of {$auction->getCurrentBid()}");

            $round->setPhase(Round::PHASE_CALLING);

            $auction->closeAuction(); // TODO ?? is this still needed?

            $round->setLeadSeat($activeSeat);

            if($nextPlayer->isAuto()) {
                $this->callTrump($activeSeat, $round->getAutoPlayerForSeat($activeSeat)->callTrump());
                return null;
            }

            return null;
        }

        if( $nextPlayer->isAuto() ) {
            $player = $round->getAutoPlayerForSeat($activeSeat);
            $nextBid = $player->getNextBid($auction, $this->game->getNextSeat(1));

            $this->placeBid($activeSeat, $nextBid);
        }
    }

    protected function validateGameState(Seat $seat, $phase)
    {
        if(!$this->game->getCurrentRound()->isPhase($phase))
            throw new PinochleRuleException("Game is currently not $phase");

        if(!$this->game->seatIsActive($seat))
            throw new PinochleRuleException('It\'s not your turn');
    }
}