<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Collection;

class TeamController extends Controller
{
    /**
     * Display a listing of teams according to the game.
     */
    public function indexByGame(string $gameId)
    {
        $game = Game::findOrFail($gameId);

        if (!GamePlayer::where('game_id', '=', $gameId)->exists()) {
            return redirect('games')
                ->withErrors([
                    'Nenhum jogador confirmado para a partida!
                    Por favor, confirme a presença de jogadores antes de gerar equipes.'
                ])
            ;
        }

        return view(
            'team.index',
            [
                'teams' => GamePlayer::teamsByGameId($gameId),
                'game' => $game,
            ]
        );
    }

    /**
     * Store a newly created game_player with the game and the RSVP'd player.
     */
    public function store(Request $request): Redirector|RedirectResponse
    {
        $validatedData = $request->validate([
            'game_id' => 'required|uuid|exists:games,id',
            'player_id' => 'required|uuid|exists:players,id',
        ]);

        $gameId = $validatedData['game_id'];
        $playerId = $validatedData['player_id'];

        $gamePlayerExists = GamePlayer::where('game_id', $gameId)
            ->where('player_id', $playerId)
            ->exists()
        ;

        if ($gamePlayerExists) {
            return back()->withErrors('This player is already confirmed for this game');
        }

        (new GamePlayer([
            'game_id' => $gameId,
            'player_id' => $playerId,
        ]))->save();

        return redirect('available-players/'.$gameId)->with('success', 'Presença confirmada com sucesso.');
    }

    /**
     * Generate teams for a game according to the amount of players per team.
     */
    public function update(Request $request): Redirector|RedirectResponse
    {
        $validatedData = $request->validate([
            'game_id' => 'required|uuid|exists:games,id',
            // 'players' => 'required|integer|min:1'
        ]);

        $gameId = $validatedData['game_id'];

        $players = (new Game(['id' => $gameId]))->players->sortBy('ability');

        $validationResult = $this->validatePlayersCount($players);

        if ($validationResult !== null) {
            return $validationResult;
        }

        $teams = $this->generateTeams($players);

        $this->persistTeams($gameId, $teams);
    
        return back()->with('success', 'Equipes geradas com sucesso!');

    }

    /**
     * Generate teams by separating goalkeepers and balancing the players.
     */
    private function validatePlayersCount(Collection &$players): Redirector|RedirectResponse|null
    {
        $playersCount = $players->count();

        if ($playersCount <= 0) {
            return back()
                ->withErrors([
                    'A quantidade de jogadores confirmados para a partida deve ser maior que zero'
                ])
            ;
        }

        if ($playersCount%2 != 0) {
            return back()
                ->withErrors([
                    'A quantidade de jogadores confirmados para a partida deve ser um número par.'
                ])
            ;
        }

        return null;

        // if ($playersCount/2 < $desiredPlayersCount) {
        //     throw new Exception(
        //         "The amount of players confirmed for the game cannot be less than "
        //         . $desiredPlayersCount,
        //     );
        // }

        // if ($playersCount/2 > $desiredPlayersCount) {
        //     throw new Exception(
        //         "The amount of players confirmed for the game cannot be more than "
        //         . $desiredPlayersCount,
        //     );
        // }
    }

    /**
     * Generate teams by separating goalkeepers and balancing the players abilities.
     */
    private function generateTeams(Collection &$players): array
    {
        $teams = [];

        $goalkeepers = $players->where('goalkeeper', '=', 1);
        $playersCount = $players->count();

        if ($goalkeepers->count() >= 2) {
            $id = $this->extractGoalkeeper($goalkeepers, $players);
            $teams[1][] = $id;

            $id = $this->extractGoalkeeper($goalkeepers, $players);
            $teams[2][] = $id;

            $playersCount-=2;
        }

        for ($i=0; $i<$playersCount; $i+=2) {
            $id = $players->pop()->id;
            $teams[1][] = $id;
            $id = $players->pop()->id;
            $teams[2][] = $id;
        }

        return $teams;
    }

    /**
     * Extract goalkeeper from original collections after being added into a team.
     */
    private function extractGoalkeeper(Collection &$goalkeepers, Collection &$players): string
    {
        $id = $goalkeepers->pop()->id;
    
        $players = $players->reject(
            function (Player $player) use ($id) {
                return $player->id == $id;
            }
        );

        return $id;
    }

    /**
     * Persist teams in the database and returning game_players records.
     */
    private function persistTeams(string $gameId, array $teams): void
    {
        $this->saveTeam($teams[1], 1, $gameId);
        $this->saveTeam($teams[2], 2, $gameId);
    }

    /**
     * Persist teams in the database by updating game_players rows.
     */
    private function saveTeam(array $team, int $index, string $gameId): void
    {
        GamePlayer::whereIn('player_id', $team)
            ->where('game_id', $gameId)
            ->update([
                'team' => $index
            ])
        ;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(GamePlayer $gamePlayer)
    {
        //
    }
}