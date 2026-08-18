<?php

namespace Tests\Feature\Documents;

use App\Jobs\document\IndexDocumentJob;
use App\Models\Account;
use App\Models\Chunk;
use App\Models\CrawlJob;
use App\Models\Document;
use App\Models\Page;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\lexical\LexicalIndexService;
use App\Services\vector\VectorIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class DocumentResourceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_upload_list_rename_and_delete_a_site_resource(): void
    {
        Queue::fake();
        [$owner, , $site] = $this->tenant();

        $upload = $this->withoutMiddleware()
            ->actingAs($owner)
            ->post('/api/v1/site/'.$site->id.'/documents', [
                'title' => 'Guide commercial 2026',
                'file' => UploadedFile::fake()->createWithContent('guide.txt', 'Contenu de connaissance suffisamment explicite.'),
            ])
            ->assertAccepted()
            ->assertJsonPath('data.title', 'Guide commercial 2026');

        $documentId = $upload->json('data.id');
        Queue::assertPushed(IndexDocumentJob::class);
        $this->assertDatabaseHas('documents', [
            'id' => $documentId,
            'documentable_id' => $site->id,
            'title' => 'Guide commercial 2026',
            'indexing_status' => 'queued',
        ]);

        $this->withoutMiddleware()
            ->actingAs($owner)
            ->getJson('/api/v1/site/'.$site->id.'/documents')
            ->assertOk()
            ->assertJsonPath('data.0.id', $documentId);

        $this->withoutMiddleware()
            ->actingAs($owner)
            ->post('/api/v1/site/'.$site->id.'/documents/'.$documentId, [
                'title' => 'Guide commercial 2027',
            ])
            ->assertAccepted()
            ->assertJsonPath('data.title', 'Guide commercial 2027');

        $this->assertDatabaseHas('documents', [
            'id' => $documentId,
            'title' => 'Guide commercial 2027',
            'index_revision' => 2,
        ]);

        $this->withoutMiddleware()
            ->actingAs($owner)
            ->deleteJson('/api/v1/site/'.$site->id.'/documents/'.$documentId)
            ->assertOk();

        $this->assertDatabaseMissing('documents', ['id' => $documentId]);
    }

    public function test_deletion_removes_linked_chunks_from_both_search_indexes(): void
    {
        [$owner, , $site] = $this->tenant();
        $document = $site->documents()->create([
            'title' => 'Document à supprimer',
            'type' => 'file',
            'purpose' => 'knowledge',
            'path' => 'assets/resources/documents/missing.txt',
            'extension' => 'txt',
        ]);
        $crawlJob = CrawlJob::query()->create([
            'site_id' => $site->id,
            'page_url' => 'https://example.test/page',
        ]);
        $page = Page::query()->create([
            'site_id' => $site->id,
            'crawl_job_id' => $crawlJob->id,
            'source' => 'crawl',
            'content' => 'Contenu',
            'title' => 'Page',
            'url' => 'https://example.test/page',
        ]);
        $chunk = Chunk::query()->create([
            'page_id' => $page->id,
            'site_id' => $site->id,
            'document_id' => $document->id,
            'source_type' => 'document',
            'text' => 'Chunk lié au document',
            'embedding' => [],
            'priority' => 50,
            'metadata' => [],
            'hash' => hash('sha256', 'Chunk lié au document'),
        ]);

        $vector = Mockery::mock(VectorIndexService::class);
        $vector->shouldReceive('deleteChunksBatch')
            ->once()
            ->with([$chunk->id], 'chunks_'.$site->id);
        $lexical = Mockery::mock(LexicalIndexService::class);
        $lexical->shouldReceive('deleteChunksBatch')
            ->once()
            ->with([$chunk->id], $site->id);
        $this->app->instance(VectorIndexService::class, $vector);
        $this->app->instance(LexicalIndexService::class, $lexical);

        $this->withoutMiddleware()
            ->actingAs($owner)
            ->deleteJson('/api/v1/site/'.$site->id.'/documents/'.$document->id)
            ->assertOk()
            ->assertJsonPath('deleted_chunks', 1);

        $this->assertDatabaseMissing('chunks', ['id' => $chunk->id]);
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
    }

    public function test_admin_cannot_access_another_accounts_resources(): void
    {
        [$owner] = $this->tenant();
        [, , $otherSite] = $this->tenant();

        $this->withoutMiddleware()
            ->actingAs($owner)
            ->getJson('/api/v1/site/'.$otherSite->id.'/documents')
            ->assertForbidden();
    }

    public function test_sitemap_deletion_removes_chunks_from_its_crawled_pages(): void
    {
        [$owner, , $site] = $this->tenant();
        $sitemap = $site->documents()->create([
            'title' => 'Sitemap principal',
            'type' => 'sitemap',
            'purpose' => 'sitemap',
            'path' => 'assets/sitemaps/missing.xml',
            'extension' => 'xml',
        ]);
        $crawlJob = CrawlJob::query()->create([
            'site_id' => $site->id,
            'source_document_id' => $sitemap->id,
            'page_url' => 'https://example.test/from-sitemap',
        ]);
        $page = Page::query()->create([
            'site_id' => $site->id,
            'crawl_job_id' => $crawlJob->id,
            'source' => 'sitemap',
            'content' => 'Contenu du sitemap',
            'title' => 'Page sitemap',
            'url' => 'https://example.test/from-sitemap',
        ]);
        $chunk = Chunk::query()->create([
            'page_id' => $page->id,
            'site_id' => $site->id,
            'document_id' => null,
            'source_type' => 'sitemap',
            'text' => 'Chunk issu du sitemap',
            'embedding' => [],
            'priority' => 50,
            'metadata' => [],
            'hash' => hash('sha256', 'Chunk issu du sitemap'),
        ]);

        $vector = Mockery::mock(VectorIndexService::class);
        $vector->shouldReceive('deleteChunksBatch')->once()->with([$chunk->id], 'chunks_'.$site->id);
        $lexical = Mockery::mock(LexicalIndexService::class);
        $lexical->shouldReceive('deleteChunksBatch')->once()->with([$chunk->id], $site->id);
        $this->app->instance(VectorIndexService::class, $vector);
        $this->app->instance(LexicalIndexService::class, $lexical);

        $this->withoutMiddleware()
            ->actingAs($owner)
            ->deleteJson('/api/v1/site/'.$site->id.'/documents/'.$sitemap->id)
            ->assertOk()
            ->assertJsonPath('deleted_chunks', 1);

        $this->assertDatabaseMissing('crawl_jobs', ['id' => $crawlJob->id]);
        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
        $this->assertDatabaseMissing('chunks', ['id' => $chunk->id]);
    }

    private function tenant(): array
    {
        $role = Role::query()->create(['name' => 'admin-'.Str::lower(Str::random(10))]);
        $owner = User::factory()->create(['role_id' => $role->id]);
        $account = Account::query()->create([
            'name' => 'Account '.Str::random(8),
            'email' => Str::uuid().'@example.test',
            'owner_user_id' => $owner->id,
        ]);
        $site = Site::query()->create([
            'account_id' => $account->id,
            'url' => 'https://'.Str::lower(Str::random(12)).'.example.test',
        ]);

        return [$owner, $account, $site];
    }
}
