<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectSpeechTest extends TestCase
{
    use RefreshDatabase;

    public function test_transcription_endpoint_requires_local_script_configuration(): void
    {
        config()->set('services.speech.local.script_path', 'tools/missing_transcriber.py');

        $owner = $this->createUser('speech-owner@example.com');
        $collaborator = $this->createUser('speech-collaborator@example.com');
        $project = $this->createProject($owner, 'Speech project');
        $this->addParticipant($project, $collaborator);

        $token = $collaborator->createToken('test')->plainTextToken;
        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'fake-audio');

        $response = $this->withToken($token)->post('/api/projects/'.$project->project_id.'/speech/transcribe', [
            'audio' => $audio,
            'language' => 'ru',
        ]);

        $response
            ->assertStatus(503)
            ->assertJsonPath('code', 'speech_local_unavailable');
    }

    public function test_transcription_endpoint_denies_non_collaborator(): void
    {
        $owner = $this->createUser('speech-owner-2@example.com');
        $intruder = $this->createUser('speech-intruder@example.com');
        $project = $this->createProject($owner, 'Speech private project');

        $token = $intruder->createToken('test')->plainTextToken;
        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'fake-audio');

        $response = $this->withToken($token)->post('/api/projects/'.$project->project_id.'/speech/transcribe', [
            'audio' => $audio,
            'language' => 'en',
        ]);

        $response
            ->assertStatus(403)
            ->assertJson(['message' => 'Access denied.']);
    }

    private function createUser(string $email): User
    {
        return User::query()->create([
            'name' => 'Test user',
            'email' => $email,
            'password_hash' => Hash::make('password123'),
            'status' => 'active',
            'language' => 'eng',
        ]);
    }

    private function createProject(User $owner, string $name): Project
    {
        return Project::query()->create([
            'name' => $name,
            'description' => null,
            'owner_id' => $owner->user_id,
            'project_path' => 'projects/tests/'.strtolower(str_replace(' ', '-', $name)).'-'.$owner->user_id,
            'is_public' => false,
        ]);
    }

    private function addParticipant(Project $project, User $user): void
    {
        ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $user->user_id,
            'joined_at' => now(),
        ]);
    }
}
