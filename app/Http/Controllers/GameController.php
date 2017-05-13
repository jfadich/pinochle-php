<?php

namespace App\Http\Controllers;

use App\Exceptions\PinochleRuleException;
use App\Pinochle\AutoPlayer;
use App\Pinochle\Cards\Card;
use App\Models\Game;
use App\Models\Hand;
use App\Pinochle\Pinochle;
use App\Models\Player;
use App\Models\Round;
use Illuminate\Http\Request;

class GameController extends Controller
{
    public function analysis(Game $game)
    {
        $hands = collect([]);
        foreach($game->currentRound->hands()->get() as $key => $hand) {

            // Save current hand or reference, but reset the hand as dealt for analysis.
            $dealt = $hand->getDealtCards();
            $cards = $hand->getCards();
            $hand->dealt = $cards;
            $hand->current = $dealt;

            $analysis = new AutoPlayer($hand);
            $trump = $analysis->callTrump();

            $hands->push([
                'seat' => $key,
                'player' => $hand->player,
                'cards' => $dealt,
                'current' => $cards,
                'trump' => new Card($trump),
                'meld'  => $analysis->getMeld($trump),
                'potential' => $analysis->getMeldPotential($trump),
                'play_power' => $analysis->getPlayingPower($trump, false),
                'wishlist' => $analysis->getMeldWishList($trump),
                'pass' => [
                    Card::SUIT_HEARTS => $analysis->getPass(Card::SUIT_HEARTS),
                    Card::SUIT_SPADES => $analysis->getPass(Card::SUIT_SPADES),
                    Card::SUIT_DIAMONDS => $analysis->getPass(Card::SUIT_DIAMONDS),
                    Card::SUIT_CLUBS => $analysis->getPass(Card::SUIT_CLUBS)
                ],
                'bid' => $analysis->getMaxBid()
            ]);
        }

        return view('analysis', compact('game', 'hands'));
    }

    public function play(Game $game)
    {
        $game->load(['players.hands']);


        return view('game', compact('game'));
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|max:255'
        ]);

        $game = Game::create(['name' => $request->get('name'), 'join_code' => str_random('12')]);

        // TODO Add 'addPlayer' methods
        $game->addPlayer(['seat' => 0, 'user_id' => null]);
        $game->addPlayer(['seat' => 1, 'user_id' => 1]);
        $game->addPlayer(['seat' => 2, 'user_id' => null]);
        $game->addPlayer(['seat' => 3, 'user_id' => null]);

        (new Pinochle($game))->deal();

        return redirect("/games/{$game->id}");
    }

    public function show(Game $game)
    {
        $game->load(['players', 'rounds']);
        return $game;
    }

    public function placeBid(Game $game, Request $request)
    {
        $this->validate($request, [
            'bid' => 'required'
        ]);

        $pinochle = new Pinochle($game);

        $new_bid = $request->get('bid');

        $player = Player::findOrFail($request->get('player'));

        $pinochle->placeBid($player, $new_bid);

        return redirect("/games/{$game->id}");
    }

    public function callTrump(Game $game, Request $request)
    {
        $this->validate($request, [
            'trump' => 'required'
        ]);

        $pinochle = Pinochle::make($game);

        $trump = $request->get('trump');

        $player = Player::findOrFail($request->get('player'));

        $pinochle->callTrump($player, (int)$trump);

        return redirect("/games/{$game->id}");
    }

    public function passCards(Game $game, Request $request)
    {
        $this->validate($request, [
            'cards' => 'required|array|max:4|min:4'
        ]);

        $pinochle = Pinochle::make($game);
        $cards = $request->get('cards');
        $player = Player::findOrFail($request->get('player'));

        $pinochle->passCards($player, $cards);


        return redirect("/games/{$game->id}");
    }

    public function meld(Game $game, Request $request)
    {
        $this->validate($request, [
            'seat' => 'required|numeric|min:0|max:3'
        ]);

        $pinochle = Pinochle::make($game);

        $pinochle->acceptMeld($request->get('seat'));

        return redirect("/games/{$game->id}");
    }
}
