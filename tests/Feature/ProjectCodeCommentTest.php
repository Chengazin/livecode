<?php

namespace Tests\Feature;

use App\Events\ProjectRealtimeEvent;
use App\Models\Project;
use App\Models\ProjectParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProjectCodeCommentTest extends TestCase
{
    use RefreshDatabase;

    public function test_developer_can_create_and_list_file_comments(): void
    {
        $owner = $this->createUser('owner-comments@example.com');
        $developer = $this->createUser('developer-comments@example.com');
        $project = $this->createProject($owner, 'Code comments');
        $this->addParticipant($project, $developer, ProjectParticipant::ROLE_DEVELOPER);
        Event::fake([ProjectRealtimeEvent::class]);

        $token = $developer->createToken('test')->plainTextToken;

        $create = $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/code-comments', [
            'path' => 'src/main.js',
            'line_number' => 7,
            'body' => 'Need to split this function.',
        ]);

        $create
            ->assertStatus(201)
            ->assertJsonPath('comment.path', 'src/main.js')
            ->assertJsonPath('comment.line_number', 7)
            ->assertJsonPath('comment.author.user_id', $developer->user_id);
        Event::assertDispatched(ProjectRealtimeEvent::class, function (ProjectRealtimeEvent $event) use ($project, $developer): bool {
            return $event->projectId === (int) $project->project_id
                && $event->userId === (int) $developer->user_id
                && $event->name === 'realtime.code_comment.updated'
                && (string) ($event->payload['action'] ?? '') === 'created'
                && (int) ($event->payload['comment']['comment_id'] ?? 0) > 0;
        });

        $list = $this->withToken($token)->getJson('/api/projects/'.$project->project_id.'/code-comments?path=src/main.js');

        $list
            ->assertOk()
            ->assertJsonCount(1, 'comments')
            ->assertJsonPath('comments.0.body', 'Need to split this function.');
    }

    public function test_comment_delete_allowed_for_project_writer_roles(): void
    {
        $owner = $this->createUser('owner-delete-comments@example.com');
        $maintainer = $this->createUser('maintainer-delete-comments@example.com');
        $author = $this->createUser('author-delete-comments@example.com');
        $otherDeveloper = $this->createUser('other-delete-comments@example.com');
        $project = $this->createProject($owner, 'Delete comments');

        $this->addParticipant($project, $maintainer, ProjectParticipant::ROLE_MAINTAINER);
        $this->addParticipant($project, $author, ProjectParticipant::ROLE_DEVELOPER);
        $this->addParticipant($project, $otherDeveloper, ProjectParticipant::ROLE_DEVELOPER);
        Event::fake([ProjectRealtimeEvent::class]);

        $authorToken = $author->createToken('test')->plainTextToken;
        $maintainerToken = $maintainer->createToken('test')->plainTextToken;
        $otherToken = $otherDeveloper->createToken('test')->plainTextToken;

        $created = $this->withToken($authorToken)->postJson('/api/projects/'.$project->project_id.'/code-comments', [
            'path' => 'src/main.js',
            'line_number' => 3,
            'body' => 'This block is duplicated.',
        ])->assertStatus(201);

        $commentId = (int) $created->json('comment.comment_id');
        $this->assertTrue($commentId > 0);

        $this->withToken($otherToken)
            ->deleteJson('/api/projects/'.$project->project_id.'/code-comments/'.$commentId)
            ->assertNoContent();
        Event::assertDispatched(ProjectRealtimeEvent::class, function (ProjectRealtimeEvent $event) use ($project, $commentId): bool {
            return $event->projectId === (int) $project->project_id
                && $event->name === 'realtime.code_comment.updated'
                && (string) ($event->payload['action'] ?? '') === 'deleted'
                && (int) ($event->payload['comment_id'] ?? 0) === $commentId;
        });

        $createdByMaintainer = $this->withToken($maintainerToken)->postJson('/api/projects/'.$project->project_id.'/code-comments', [
            'path' => 'src/main.js',
            'line_number' => 5,
            'body' => 'Maintainer note.',
        ])->assertStatus(201);

        $commentIdByMaintainer = (int) $createdByMaintainer->json('comment.comment_id');
        $this->assertTrue($commentIdByMaintainer > 0);

        $ownerToken = $owner->createToken('test')->plainTextToken;
        $this->withToken($ownerToken)
            ->deleteJson('/api/projects/'.$project->project_id.'/code-comments/'.$commentIdByMaintainer)
            ->assertNoContent();
    }

    public function test_viewer_cannot_create_comments(): void
    {
        $owner = $this->createUser('owner-viewer-comments@example.com');
        $viewer = $this->createUser('viewer-comments@example.com');
        $project = $this->createProject($owner, 'Viewer comments');
        $this->addParticipant($project, $viewer, ProjectParticipant::ROLE_VIEWER);

        $token = $viewer->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/projects/'.$project->project_id.'/code-comments', [
            'path' => 'src/view.js',
            'line_number' => 2,
            'body' => 'Cannot post as viewer',
        ])->assertStatus(403)
            ->assertJsonPath('message', 'Access denied.');
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

    private function addParticipant(Project $project, User $user, string $role): void
    {
        ProjectParticipant::query()->create([
            'project_id' => $project->project_id,
            'user_id' => $user->user_id,
            'role' => $role,
            'joined_at' => now(),
        ]);
    }
}
