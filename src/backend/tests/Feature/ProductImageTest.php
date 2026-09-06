<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeader;

// Assert the login worked before handing back its token — the guard Task 6
// established and Tasks 7 and 8 carried forward. Without it a 401 or 422 here
// yields null, the header becomes a bare "Bearer ", and every authorised case
// fails as 401 with a diagnosis pointing at the image endpoint rather than at
// the login.
$tokenForLogin = fn (User $user): string => postJson('/api/v1/auth/login', [
    'email' => $user->email,
    'password' => 'correct-horse-battery',
])->json('token');

$tokenFor = function (User $user) use ($tokenForLogin): string {
    $token = $tokenForLogin($user);

    expect($token)->toBeString()->not->toBe('');

    return $token;
};

$admin = fn (): User => User::factory()->admin()->create(['password' => 'correct-horse-battery']);
$customer = fn (): User => User::factory()->create(['password' => 'correct-horse-battery']);

beforeEach(function () {
    // The real round-trip is proven by StorageConnectivityTest; these tests
    // are about the endpoint's behaviour, so a fake disk keeps them fast
    // and independent of Garage being up.
    Storage::fake('s3');
});

it('lets an admin upload an image and exposes its url', function () use ($admin, $tokenFor) {
    $product = Product::factory()->create();

    withHeader('Authorization', 'Bearer '.$tokenFor($admin()))
        ->post("/api/v1/products/{$product->id}/image", [
            'image' => UploadedFile::fake()->image('kettle.jpg', 400, 400),
        ], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonPath('data.id', $product->id);

    $product->refresh();

    expect($product->image_path)->not->toBeNull();
    Storage::disk('s3')->assertExists($product->image_path);

    getJson("/api/v1/products/{$product->id}")
        ->assertJsonPath('data.image_url', fn ($url) => is_string($url) && $url !== '');
});

it('rejects a customer token', function () use ($customer, $tokenFor) {
    $product = Product::factory()->create();

    withHeader('Authorization', 'Bearer '.$tokenFor($customer()))
        ->post("/api/v1/products/{$product->id}/image", [
            'image' => UploadedFile::fake()->image('kettle.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');
});

it('rejects an unauthenticated upload with 401', function () {
    $product = Product::factory()->create();

    post("/api/v1/products/{$product->id}/image", [
        'image' => UploadedFile::fake()->image('kettle.jpg'),
    ], ['Accept' => 'application/json'])
        ->assertStatus(401);
});

it('rejects a file that is not really an image', function () use ($admin, $tokenFor) {
    $product = Product::factory()->create();

    // A text file wearing a .jpg extension. Validation must inspect content,
    // not the client-supplied name or Content-Type (NFR-15).
    $notAnImage = tempnam(sys_get_temp_dir(), 'notimage').'.jpg';
    file_put_contents($notAnImage, 'not an image, just ASCII');

    withHeader('Authorization', 'Bearer '.$tokenFor($admin()))
        ->post("/api/v1/products/{$product->id}/image", [
            // A REAL UploadedFile, deliberately not UploadedFile::fake().
            // Illuminate\Http\Testing\File overrides getMimeType() to return
            // MimeType::from($this->name) — the type derived from the
            // FILENAME — so a fake named .jpg reports image/jpeg whatever is
            // inside it, and Laravel's `image` rule is itself only a mimes
            // check against guessExtension(). Both rules would pass and this
            // test could never go red. A real UploadedFile puts Symfony's
            // finfo guesser back in the path, which is the content inspection
            // NFR-15 actually asks for. The third argument is the
            // client-supplied Content-Type — a lie, on purpose.
            'image' => new UploadedFile($notAnImage, 'malware.jpg', 'image/jpeg', null, true),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422)
        ->assertJsonStructure(['error' => ['details' => ['image']]]);

    // Rejected AND not stored — a 422 whose object landed anyway is worse
    // than either outcome alone.
    expect($product->fresh()->image_path)->toBeNull();

    @unlink($notAnImage);
});

it('busts the cached product payload on upload and on delete', function () use ($admin, $tokenFor) {
    // Task 8 caches product:{id} for an hour and ProductResource carries
    // image_url, so the upload must fire ProductObserver. Warm the entry
    // FIRST: without this GET the cache is cold when the endpoint runs and
    // the assertions below pass even if the controller swaps
    // $product->update() for Product::whereKey($id)->update([...]), which
    // fires no model event, runs no observer, and leaves every cached product
    // serving image_url: null for the full 3600s TTL.
    $product = Product::factory()->create();
    $token = $tokenFor($admin());

    getJson("/api/v1/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.image_url', null);

    expect(Cache::has("product:{$product->id}"))->toBeTrue();

    withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/products/{$product->id}/image", [
            'image' => UploadedFile::fake()->image('kettle.jpg'),
        ], ['Accept' => 'application/json'])
        ->assertOk();

    expect(Cache::has("product:{$product->id}"))->toBeFalse();

    getJson("/api/v1/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.image_url', fn ($url) => is_string($url) && $url !== '');

    withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}/image")
        ->assertNoContent();

    expect(Cache::has("product:{$product->id}"))->toBeFalse();

    getJson("/api/v1/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('data.image_url', null);
});

it('rejects a file over the size limit but accepts one under it', function () use ($admin, $tokenFor) {
    $product = Product::factory()->create();
    $token = $tokenFor($admin());

    withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/products/{$product->id}/image", [
            'image' => UploadedFile::fake()->image('huge.jpg')->size(3000),
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);

    // Both sides of the boundary.
    withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/products/{$product->id}/image", [
            'image' => UploadedFile::fake()->image('fine.jpg')->size(1500),
        ], ['Accept' => 'application/json'])
        ->assertOk();
});

it('deletes the previous object when an image is replaced', function () use ($admin, $tokenFor) {
    $product = Product::factory()->create();
    $token = $tokenFor($admin());

    withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/products/{$product->id}/image", [
            'image' => UploadedFile::fake()->image('first.jpg'),
        ], ['Accept' => 'application/json']);

    $first = $product->fresh()->image_path;

    withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/products/{$product->id}/image", [
            'image' => UploadedFile::fake()->image('second.jpg'),
        ], ['Accept' => 'application/json']);

    $second = $product->fresh()->image_path;

    expect($second)->not->toBe($first);
    Storage::disk('s3')->assertMissing($first);
    Storage::disk('s3')->assertExists($second);
});

it('deletes the image and clears the column', function () use ($admin, $tokenFor) {
    $product = Product::factory()->create();
    $token = $tokenFor($admin());

    withHeader('Authorization', "Bearer {$token}")
        ->post("/api/v1/products/{$product->id}/image", [
            'image' => UploadedFile::fake()->image('kettle.jpg'),
        ], ['Accept' => 'application/json']);

    $path = $product->fresh()->image_path;

    withHeader('Authorization', "Bearer {$token}")
        ->deleteJson("/api/v1/products/{$product->id}/image")
        ->assertNoContent();

    expect($product->fresh()->image_path)->toBeNull();
    Storage::disk('s3')->assertMissing($path);
});

it('refuses a delete from a customer and from an anonymous caller', function () use ($admin, $customer, $tokenFor) {
    // The deny side of DELETE. Upload has all three cases — admin, customer,
    // anonymous — while delete had only the admin success above. Dropping
    // $this->authorize('update', $product) from destroy(), or registering the
    // delete route outside the auth:api group, would let any logged-in
    // customer wipe every product image with the suite still green.
    $product = Product::factory()->create();

    withHeader('Authorization', 'Bearer '.$tokenFor($admin()))
        ->post("/api/v1/products/{$product->id}/image", [
            'image' => UploadedFile::fake()->image('kettle.jpg'),
        ], ['Accept' => 'application/json']);

    $path = $product->fresh()->image_path;

    withHeader('Authorization', 'Bearer '.$tokenFor($customer()))
        ->deleteJson("/api/v1/products/{$product->id}/image")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'FORBIDDEN');

    // withoutHeader, because withHeader() above wrote into the TestCase's
    // $defaultHeaders and they persist for the REST OF THIS TEST. Without
    // this the "anonymous" call below still carries the customer's bearer
    // token, gets the customer's 403, and asserts nothing new -- the
    // anonymous side of DELETE would have no coverage at all while looking
    // like it did.
    test()->withoutHeader('Authorization')
        ->deleteJson("/api/v1/products/{$product->id}/image")
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    // And the object is still there — a 403 that deleted anyway is worse than
    // no check at all.
    expect($product->fresh()->image_path)->toBe($path);
    Storage::disk('s3')->assertExists($path);
});

it('reports a null image url when there is no image', function () {
    $product = Product::factory()->create();

    getJson("/api/v1/products/{$product->id}")
        ->assertJsonPath('data.image_url', null);
});
