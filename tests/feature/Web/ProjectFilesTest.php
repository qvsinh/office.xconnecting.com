<?php

use App\Controllers\ProjectFiles;
use App\Models\ProjectFileModel;
use App\Models\ProjectMemberModel;
use App\Models\ProjectModel;
use App\Models\UserModel;
use App\Models\WorkspaceMemberModel;
use App\Models\WorkspaceModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\App;
use Tests\Support\Web\WebAuthFixture;

/**
 * Project file manager (`ProjectFiles`), exercised through real routing +
 * filters (`authFilter`) + CSRF for gating/validation, plus direct model
 * seeding for download/delete: the `project_role()`/`can_edit_project()`
 * IDOR guard, the `ext_in`/`mime_in`/`max_size` upload allowlist, and
 * soft-delete + disk cleanup.
 *
 * **On testing a successful upload:** PHP's `move_uploaded_file()` (called
 * by `UploadedFile::move()`) only accepts files the SAPI itself registered
 * as uploaded via a genuine multipart POST — a fabricated `$_FILES` entry in
 * a CLI test process can never satisfy that, so `ProjectFiles::upload()`
 * cannot be driven all the way through `move()` here. The two rejection
 * paths (`.php`, oversize) run entirely pre-move through the real HTTP
 * route, so they're covered end-to-end; the "accepts a legitimate png"
 * case is instead verified by running the exact same validation rules the
 * controller builds (via reflection) against a real small PNG, which
 * exercises the security-critical allowlist without needing `move()`.
 *
 * @internal
 */
final class ProjectFilesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    /**
     * A minimal valid 1x1 transparent PNG, base64-encoded.
     */
    private const TINY_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;

    /**
     * @var list<string>
     */
    private array $cleanupPaths = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();

        foreach ($this->cleanupPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
    }

    public function testIndexIsGatedToProjectMembers(): void
    {
        [$projectId] = $this->makeProjectWithOwner('owner-idx@example.com');
        $outsider = $this->seedWebUser(['email' => 'outsider-idx@example.com']);
        $this->loginAs($outsider);

        $index = $this->withSession()->get('/projects/' . $projectId . '/files');

        $index->assertStatus(403);
    }

    public function testUploadIsGatedToEditors(): void
    {
        [$projectId, , , $projectMemberModel] = $this->makeProjectWithOwner('owner-upload@example.com');
        $viewer = $this->seedWebUser(['email' => 'viewer-upload@example.com']);
        $projectMemberModel->add($projectId, (int) $viewer->id, 'viewer');
        $this->loginAs($viewer);

        $upload = $this->withSession()->post('/projects/' . $projectId . '/files/upload', $this->withCsrf([]));

        $upload->assertStatus(403);
    }

    public function testUploadRejectsAPhpFile(): void
    {
        [$projectId, $ownerId] = $this->makeProjectWithOwner('owner-php@example.com');
        $owner = model(UserModel::class)->findUser($ownerId);
        $this->loginAs($owner);

        $tmpPath = $this->writeTempFile('<?php echo "pwned"; ?>');
        service('superglobals')->setFilesArray([
            'file' => [
                'name'     => 'malware.php',
                'type'     => 'text/plain',
                'tmp_name' => $tmpPath,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize($tmpPath),
            ],
        ]);

        $upload = $this->withSession()->post('/projects/' . $projectId . '/files/upload', $this->withCsrf([]));

        $upload->assertStatus(400);
        $this->assertSame(0, model(ProjectFileModel::class)->countAllResults());
    }

    public function testUploadRejectsAnOversizeFile(): void
    {
        [$projectId, $ownerId] = $this->makeProjectWithOwner('owner-big@example.com');
        $owner = model(UserModel::class)->findUser($ownerId);
        $this->loginAs($owner);

        $tmpPath = $this->writeTempFile(base64_decode(self::TINY_PNG_BASE64, true));
        service('superglobals')->setFilesArray([
            'file' => [
                'name'     => 'huge.png',
                'type'     => 'image/png',
                'tmp_name' => $tmpPath,
                'error'    => UPLOAD_ERR_OK,
                // Real bytes on disk are tiny; the client-reported size
                // (what max_size checks) is faked oversize on purpose.
                'size'     => 20 * 1024 * 1024,
            ],
        ]);

        $upload = $this->withSession()->post('/projects/' . $projectId . '/files/upload', $this->withCsrf([]));

        $upload->assertStatus(400);
        $this->assertSame(0, model(ProjectFileModel::class)->countAllResults());
    }

    public function testUploadValidationAcceptsALegitimatePng(): void
    {
        $tmpPath = $this->writeTempFile(base64_decode(self::TINY_PNG_BASE64, true));
        $this->injectMockRequestWithFile($tmpPath, 'photo.png', 'image/png', (int) filesize($tmpPath));

        $rules      = $this->uploadValidationRules();
        $validation = service('validation', null, false)->setRules($rules);

        $this->assertTrue($validation->run(['file' => null]));
    }

    public function testDownloadStreamsForMemberAndIsDeniedForNonMember(): void
    {
        [$projectId, $ownerId] = $this->makeProjectWithOwner('owner-download@example.com');
        $fileId = $this->seedFileRow($projectId, $ownerId, 'notes.txt', 'Hello, project.');

        $owner = model(UserModel::class)->findUser($ownerId);
        $this->loginAs($owner);
        $download = $this->withSession()->get('/projects/files/download/' . $fileId);
        $download->assertStatus(200);

        $outsider = $this->seedWebUser(['email' => 'outsider-download@example.com']);
        $this->loginAs($outsider);
        $denied = $this->withSession()->get('/projects/files/download/' . $fileId);
        $denied->assertStatus(403);
    }

    public function testDownloadReturns404ForMissingRowOrMissingDiskFile(): void
    {
        [$projectId, $ownerId] = $this->makeProjectWithOwner('owner-missing@example.com');
        $owner = model(UserModel::class)->findUser($ownerId);
        $this->loginAs($owner);

        $missingRow = $this->withSession()->get('/projects/files/download/999999');
        $missingRow->assertStatus(404);

        // Row exists but the on-disk bytes were removed out-of-band.
        $fileId = $this->seedFileRow($projectId, $ownerId, 'ghost.txt', 'gone', writeToDisk: false);
        $missingDisk = $this->withSession()->get('/projects/files/download/' . $fileId);
        $missingDisk->assertStatus(404);
    }

    public function testDeleteIsGatedToEditorsAndRemovesRowAndDiskFile(): void
    {
        [$projectId, $ownerId, , $projectMemberModel] = $this->makeProjectWithOwner('owner-delete-file@example.com');
        $viewer = $this->seedWebUser(['email' => 'viewer-delete-file@example.com']);
        $projectMemberModel->add($projectId, (int) $viewer->id, 'viewer');
        $fileId  = $this->seedFileRow($projectId, $ownerId, 'report.txt', 'contents');
        $absPath = WRITEPATH . 'uploads/projects/' . $projectId . '/' . model(ProjectFileModel::class)->find($fileId)->stored_name;

        $this->loginAs($viewer);
        $denied = $this->withSession()->post('/projects/files/delete/' . $fileId, $this->withCsrf([]));
        $denied->assertStatus(403);
        $this->assertTrue(is_file($absPath));

        $owner = model(UserModel::class)->findUser($ownerId);
        $this->loginAs($owner);
        $delete = $this->withSession()->post('/projects/files/delete/' . $fileId, $this->withCsrf([]));
        $delete->assertStatus(200);

        $this->assertNull(model(ProjectFileModel::class)->find($fileId));
        $this->assertFalse(is_file($absPath));
    }

    /**
     * Creates a workspace + project with `$email` as both workspace and
     * project owner.
     *
     * @return array{0: int, 1: int, 2: WorkspaceMemberModel, 3: ProjectMemberModel}
     */
    private function makeProjectWithOwner(string $email): array
    {
        $owner       = $this->seedWebUser(['email' => $email]);
        $wsMember    = model(WorkspaceMemberModel::class);
        $workspaceId = model(WorkspaceModel::class)->createWorkspace('Files Space ' . $email, (int) $owner->id);
        $wsMember->add($workspaceId, (int) $owner->id, 'owner');

        $projectMemberModel = model(ProjectMemberModel::class);
        $projectId           = model(ProjectModel::class)->createProject($workspaceId, 'Files Project ' . $email, (int) $owner->id);
        $projectMemberModel->add($projectId, (int) $owner->id, 'owner');

        return [$projectId, (int) $owner->id, $wsMember, $projectMemberModel];
    }

    /**
     * Inserts a `project_files` row and, unless `$writeToDisk` is false,
     * the matching bytes on disk — tracked for cleanup in `tearDown()`.
     */
    private function seedFileRow(int $projectId, int $uploaderId, string $originalName, string $contents, bool $writeToDisk = true): int
    {
        $storedName = bin2hex(random_bytes(8)) . '.txt';
        $dir        = WRITEPATH . 'uploads/projects/' . $projectId . '/';

        if ($writeToDisk) {
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
                $this->cleanupPaths[] = $dir;
            }
            file_put_contents($dir . $storedName, $contents);
            $this->cleanupPaths[] = $dir . $storedName;
        }

        return (int) model(ProjectFileModel::class)->insert([
            'project_id'    => $projectId,
            'uploaded_by'   => $uploaderId,
            'original_name' => $originalName,
            'stored_name'   => $storedName,
            'mime'          => 'text/plain',
            'size'          => strlen($contents),
        ]);
    }

    private function writeTempFile(string $contents): string
    {
        $path                 = tempnam(sys_get_temp_dir(), 'pf-test-');
        $this->cleanupPaths[] = $path;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * Injects a real `IncomingRequest` carrying the given fabricated file
     * into the shared `request` service — needed only for the
     * validation-rules-in-isolation check (`testUploadValidationAccepts...`)
     * since that test never goes through `FeatureTestTrait::call()`.
     */
    private function injectMockRequestWithFile(string $tmpPath, string $clientName, string $clientMime, int $size): void
    {
        Services::injectMock('request', service('incomingrequest', config(App::class), false));

        service('superglobals')->setFilesArray([
            'file' => [
                'name'     => $clientName,
                'type'     => $clientMime,
                'tmp_name' => $tmpPath,
                'error'    => UPLOAD_ERR_OK,
                'size'     => $size,
            ],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function uploadValidationRules(): array
    {
        $method = new ReflectionMethod(ProjectFiles::class, 'uploadValidationRules');
        $method->setAccessible(true);

        return $method->invoke(new ProjectFiles());
    }
}
