<?php

declare(strict_types=1);

use App\Models\Commit;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->keyPair = apiKeyPairFor($this->user);
});

it('suggests people to follow, with why and whether they follow the user', function (): void {
    $friend = User::factory()->create();
    $vouched = User::factory()->has(Commit::factory())->create(['username' => 'octocat']);

    $this->user->following()->attach($friend);
    $friend->following()->attach($vouched);
    $vouched->following()->attach($this->user);

    $response = signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index'));

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $vouched->id)
        ->assertJsonPath('data.0.username', 'octocat')
        ->assertJsonPath('data.0.lane', $vouched->lane())
        ->assertJsonPath('data.0.followed_by_following_count', 1)
        ->assertJsonPath('data.0.followers_count', 1)
        ->assertJsonPath('data.0.follows_you', true)
        ->assertJsonPath('data.0.is_followed', false);
});

it('suggests popular people nobody the user follows follows, with their followers', function (): void {
    $popular = User::factory()->has(Commit::factory())->create();

    $popular->followers()->attach(User::factory()->count(2)->create());

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index'))
        ->assertOk()
        ->assertJsonPath('data.0.id', $popular->id)
        ->assertJsonPath('data.0.followed_by_following_count', 0)
        ->assertJsonPath('data.0.followers_count', 2)
        ->assertJsonPath('data.0.follows_you', false)
        ->assertJsonPath('data.0.is_followed', false);
});

it('sends three suggestions and three spares, as the web does', function (): void {
    User::factory()->count(8)->has(Commit::factory())->create();

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index'))
        ->assertOk()
        ->assertJsonCount(6, 'data');
});

it("moves to the next page of the day's picks each round", function (): void {
    User::factory()->count(8)->has(Commit::factory())->create();

    $first = signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index'))->json('data.*.id');
    $again = signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['round' => 0]))->json('data.*.id');
    $next = signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['round' => 1]))->json('data.*.id');

    expect($again)->toBe($first)
        ->and(array_slice($next, 0, 3))->not->toBe(array_slice($first, 0, 3));
});

it('leaves out people the user dismissed', function (): void {
    $dismissed = User::factory()->has(Commit::factory())->create();
    $suggested = User::factory()->has(Commit::factory())->create();

    $this->user->dismissedSuggestions()->attach($dismissed);

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$suggested->id]);
});

it('starts at the first round when none is given', function (): void {
    User::factory()->count(8)->has(Commit::factory())->create();

    $first = signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['round' => 0]))->json('data.*.id');

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['round' => '']))
        ->assertOk()
        ->assertJsonPath('data.*.id', $first);
});

it('leaves out people the user mutes or has a block with', function (Closure $hide): void {
    $hidden = User::factory()->has(Commit::factory())->create();
    $suggested = User::factory()->has(Commit::factory())->create();

    $hide($this->user, $hidden);

    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index'))
        ->assertOk()
        ->assertJsonPath('data.*.id', [$suggested->id]);
})->with([
    'muting' => fn (User $user, User $hidden) => $user->muting()->attach($hidden),
    'blocking' => fn (User $user, User $hidden) => $user->blocking()->attach($hidden),
    'blocked' => fn (User $user, User $hidden) => $hidden->blocking()->attach($user),
]);

it('refuses a round that is not a whole number from zero', function (mixed $round): void {
    signedApiRequest($this->keyPair, 'GET', route('api-suggested-user.index', ['round' => $round]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('round');
})->with([-1, 'next']);

it('requires a signed request', function (): void {
    $this->getJson(route('api-suggested-user.index'))->assertUnauthorized();
});
