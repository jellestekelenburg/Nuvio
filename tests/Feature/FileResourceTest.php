<?php

namespace Tests\Feature;

use App\Http\Resources\FileResource;
use App\Models\File;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class FileResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_exact_timestamps_and_user_names(): void
    {
        $creator = User::factory()->create(['name' => 'Ada Lovelace']);
        $updater = User::factory()->create(['name' => 'Grace Hopper']);

        $file = new File;
        $file->name = 'notes.txt';
        $file->is_folder = false;
        $file->created_by = $creator->id;
        $file->updated_by = $updater->id;
        $file->makeRoot()->save();
        $file->forceFill([
            'created_at' => CarbonImmutable::parse('2026-07-14 10:30:00 UTC'),
            'updated_at' => CarbonImmutable::parse('2026-07-15 14:45:00 UTC'),
        ]);
        $file->load(['user', 'updater']);

        $this->actingAs($creator);
        $request = Request::create('/');
        $request->setUserResolver(fn (): User => $creator);

        $data = (new FileResource($file))->resolve($request);

        $this->assertSame('me', $data['owner']);
        $this->assertSame($creator->id, $data['created_by']);
        $this->assertSame($updater->id, $data['updated_by']);
        $this->assertSame('2026-07-14T10:30:00+00:00', $data['details']['created_at']);
        $this->assertSame('2026-07-15T14:45:00+00:00', $data['details']['updated_at']);
        $this->assertSame('Ada Lovelace (me)', $data['details']['owner']);
        $this->assertSame('Ada Lovelace (me)', $data['details']['created_by']);
        $this->assertSame('Grace Hopper', $data['details']['updated_by']);
        $this->assertArrayNotHasKey('path', $data);
    }
}
